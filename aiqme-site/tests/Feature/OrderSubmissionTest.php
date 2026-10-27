<?php

namespace Tests\Feature;

use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Orders\Models\OrderMeta;
use App\Mail\FormSubmitted;
use App\Models\OrderCharge;
use App\Models\StlFile;
use App\Models\StlPart;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Lunar\Models\Order;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Заявка на изготовление W-12 (§3.1, §6.1, §6.2, §9.2 «Заявка» и «Права»): multipart-отправка гостем и клиентом, корзина
 * Lunar → заказ, серверная оценка вместо клиентской суммы, номер из счётчика, повтор токена отправки, гостевая ссылка,
 * honeypot и частота, событие outbox и письмо, файлы без сирот, лимиты PHP. Перенесены и адаптированы тесты эксперимента
 * (orders-comparison/shared/Tests): отправка без оплаты с двумя приватными STL, пустая корзина и неверное количество,
 * изоляция чужого заказа.
 */
class OrderSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        $this->seed(PrototypeContentSeeder::class);
        Storage::fake('local');
        $this->token = (string) Str::uuid();
    }

    private function stl(string $fixture, ?string $name = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name ?? $fixture, file_get_contents(base_path('tests/fixtures/stl/'.$fixture)));
    }

    /**
     * Две детали: куб 10 мм ASCII, qty 3 (3,72 г → 37 центов, не 3 × 12) и тот же куб binary в см с масштабом 10 % (1 см³,
     * 12 центов); PLA, заполнение 100 %, плата за заказ 500 → итог 549.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return [
            'submission_token' => $this->token,
            'name' => 'Ona Petraitė',
            'email' => 'ona@example.test',
            'phone' => '+370 600 00000',
            'company' => 'UAB Detalė',
            'company_code' => '300000000',
            'vat_code' => '',
            'delivery' => 'ship',
            'country' => 'LT',
            'city' => 'Vilnius',
            'address' => 'Gedimino pr. 1',
            'postcode' => 'LT-01103',
            'material' => 'PLA',
            'infill' => '100',
            'color' => 'black',
            'quality' => 'standard',
            'layer' => '0.2',
            'finish' => 'none',
            'term' => 'standard',
            'parts' => [
                ['file' => $this->stl('cube-10mm-ascii.stl', 'bracket.stl'), 'unit' => 'mm', 'scale' => '100', 'quantity' => '3'],
                ['file' => $this->stl('cube-10mm-binary.stl', 'spacer.stl'), 'unit' => 'cm', 'scale' => '10', 'quantity' => '1'],
            ],
            ...$override,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function submit(array $payload, string $locale = 'en'): TestResponse
    {
        return $this->post($locale === 'lt' ? '/lt/kalkuliatorius' : '/en/quote', $payload, ['Accept' => 'application/json']);
    }

    /** Отправка и страница подтверждения: номер и гостевая ссылка (если есть). */
    private function submitAndConfirm(array $payload, string $locale = 'en'): array
    {
        $redirect = $this->submit($payload, $locale)->assertCreated()->json('redirect');
        $page = Html::xpath($this->get($redirect)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent());
        $link = $page->query('//a[@data-guest-link]')->item(0);

        return [
            'number' => (int) $page->query('//*[@data-order-number]')->item(0)->getAttribute('data-order-number'),
            'total' => (int) $page->query('//*[@data-total]')->item(0)->getAttribute('data-cents'),
            'guest_url' => $link?->getAttribute('href'),
        ];
    }

    private function nothingWritten(): void
    {
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, StlFile::count());
        $this->assertSame(0, IntegrationEvent::count());
        Mail::assertNothingSent();
    }

    public function test_guest_submission_creates_lunar_order_with_server_estimate_and_private_files(): void
    {
        // Клиентская сумма и цена строки игнорируются.
        $confirmed = $this->submitAndConfirm($this->payload(['total' => '1', 'total_cents' => '1', 'parts' => [
            ['file' => $this->stl('cube-10mm-ascii.stl', 'bracket.stl'), 'unit' => 'mm', 'scale' => '100', 'quantity' => '3', 'cents' => '1'],
            ['file' => $this->stl('cube-10mm-binary.stl', 'spacer.stl'), 'unit' => 'cm', 'scale' => '10', 'quantity' => '1'],
        ]]));

        $order = Order::with('lines.purchasable')->sole();
        $meta = OrderMeta::sole();
        $this->assertSame(1, $meta->number);
        $this->assertSame('1', $order->reference);
        $this->assertSame(['number' => 1, 'total' => 549], ['number' => $confirmed['number'], 'total' => $confirmed['total']]);
        $this->assertNull($order->user_id);
        $this->assertNotNull($order->placed_at);

        // Строки: две StlPart (quantity = 1, количество — в parameters, цена строки = итог строки) и fee 500.
        $parts = $order->lines->filter(fn ($line) => $line->purchasable instanceof StlPart)->values();
        $this->assertCount(2, $parts);
        $this->assertSame([1, 1], $parts->pluck('quantity')->all());
        $this->assertSame([37, 12], $parts->map(fn ($line) => $line->unit_price->value)->all());
        $this->assertSame([3, 1], $parts->map(fn ($line) => $line->purchasable->parameters['quantity'])->all());
        $this->assertSame([37, 12], $parts->map(fn ($line) => $line->purchasable->estimate)->all());
        $this->assertEqualsWithDelta(3.72, $parts[0]->purchasable->measurement['mass_grams'], 1e-9);
        $this->assertSame(['material' => 'PLA', 'infill' => 100, 'unit' => 'mm', 'scale' => 100, 'quantity' => 3, 'color' => 'black',
            'quality' => 'standard', 'layer' => '0.2', 'finish' => 'none', 'term' => 'standard'], $parts[0]->purchasable->parameters);
        $fee = $order->lines->first(fn ($line) => $line->purchasable instanceof OrderCharge);
        $this->assertSame([OrderCharge::FEE, 500, 1, $order->id], [$fee->purchasable->kind, $fee->unit_price->value, $fee->quantity, $fee->purchasable->order_id]);

        // Сумма заказа Lunar = сумма строк = сумма на экране; налога нет (aiqme:bootstrap без ставок).
        $this->assertSame(549, $order->total->value);
        $this->assertSame(549, $order->lines->sum(fn ($line) => $line->total->value));
        $this->assertSame(0, $order->tax_total->value);
        $this->assertSame(0, $order->transactions()->count());

        // Файлы — приватные, имя назначает сервер, исходное имя и sha256 — метаданные; владелец гостевого файла — заказ.
        $files = StlFile::orderBy('id')->get();
        $this->assertSame(['bracket.stl', 'spacer.stl'], $files->pluck('original_name')->all());
        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('#^stl/[0-9a-f-]{36}\.stl$#', $file->path);
            Storage::disk('local')->assertExists($file->path);
            $this->assertSame(hash('sha256', Storage::disk('local')->get($file->path)), $file->sha256);
            $this->assertSame([null, $order->id, 12], [$file->user_id, $file->order_id, $file->triangles]);
            $this->assertFileDoesNotExist(public_path('storage/'.$file->path));
        }
        $this->assertNotSame($files[0]->sha256, $files[1]->sha256);

        // order_meta: контакты, получение, адрес, локаль и снимок настроек; открытый гостевой токен не хранится.
        $this->assertSame('en', $meta->locale);
        $this->assertSame(['name' => 'Ona Petraitė', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => 'UAB Detalė',
            'company_code' => '300000000', 'vat_code' => null], $meta->contact);
        $this->assertSame(['ship', ['country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gedimino pr. 1', 'postcode' => 'LT-01103']], [$meta->delivery, $meta->address]);
        $this->assertSame(['0.10', 500], [$meta->settings['rate_per_gram'], $meta->settings['order_fee_cents']]);
        $guestToken = basename($confirmed['guest_url']);
        $this->assertSame(43, strlen($guestToken));
        $this->assertSame(hash('sha256', $guestToken), $meta->guest_token_hash);
        $this->assertStringNotContainsString($guestToken, json_encode(OrderMeta::sole()->getAttributes()));
    }

    public function test_guest_link_opens_the_order_and_wrong_number_or_token_is_404(): void
    {
        $confirmed = $this->submitAndConfirm($this->payload());
        $this->assertStringStartsWith(config('app.url').'/en/order/1/', $confirmed['guest_url']);

        $page = $this->get($confirmed['guest_url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $page->assertSee('Order No. 1')->assertSee('bracket.stl')->assertSee('× 3')->assertSee('€5.49');
        $this->assertStringContainsString('noindex', $page->getContent());
        $token = basename($confirmed['guest_url']);
        $this->get('/lt/uzsakymas/1/'.$token)->assertOk()->assertSee('Užsakymas Nr. 1');

        $this->get('/en/order/1/'.strrev($token))->assertNotFound();
        $this->get('/en/order/1/x')->assertNotFound();
        $this->get('/en/order/2/'.$token)->assertNotFound();
        // Номер доступа не даёт: подтверждение без только что отправленной заявки — 404.
        $this->get('/en/quote/submitted')->assertNotFound();
    }

    public function test_confirmation_shows_the_guest_link_only_once(): void
    {
        $redirect = $this->submit($this->payload())->assertCreated()->json('redirect');
        $this->get($redirect)->assertOk()->assertSee('Your order link. Save it: it is shown only once.');
        $this->get($redirect)->assertNotFound();
    }

    public function test_double_submit_with_same_token_returns_same_order_and_reissues_guest_link(): void
    {
        $first = $this->submitAndConfirm($this->payload());
        // Вторая вкладка или повтор отправки: те же данные и файлы (новые объекты загрузки) и тот же токен.
        $second = $this->submitAndConfirm($this->payload());

        $this->assertSame([1, 1], [Order::count(), OrderMeta::count()]);
        $this->assertSame($first['number'], $second['number']);
        $this->assertSame(2, StlFile::count());
        $this->assertCount(2, Storage::disk('local')->allFiles('stl'));
        $this->assertSame(1, IntegrationEvent::count());
        $this->assertNotSame($first['guest_url'], $second['guest_url']);
        $this->get($first['guest_url'])->assertNotFound();
        $this->get($second['guest_url'])->assertOk()->assertSee('Order No. 1');
    }

    public function test_same_token_with_other_data_is_rejected_without_writes(): void
    {
        $this->submitAndConfirm($this->payload());
        $this->submit($this->payload(['phone' => '+370 600 11111']))
            ->assertUnprocessable()->assertJsonPath('errors.submission_token.0', 'This order has already been submitted with other details. Reload the page and try again.');
        $this->submit($this->payload(['parts' => [['file' => $this->stl('cube-10mm-ascii.stl', 'bracket.stl'), 'unit' => 'mm', 'scale' => '100', 'quantity' => '4']]]))
            ->assertUnprocessable();

        $this->assertSame([1, 2, 1], [Order::count(), StlFile::count(), IntegrationEvent::count()]);
        $this->assertCount(2, Storage::disk('local')->allFiles('stl'));
    }

    public function test_different_submissions_get_sequential_numbers_from_the_counter(): void
    {
        config(['aiqme.orders.first_number' => 1001]);
        $numbers = [];
        foreach (range(1, 3) as $ignored) {
            $this->token = (string) Str::uuid();
            $numbers[] = $this->submitAndConfirm($this->payload())['number'];
        }

        $this->assertSame([1001, 1002, 1003], $numbers);
        $this->assertSame(['1001', '1002', '1003'], Order::orderBy('id')->pluck('reference')->all());
        $this->assertSame(1003, (int) \DB::table('counters')->where('name', 'orders')->value('value'));
    }

    public function test_event_is_recorded_with_the_order_and_mail_goes_to_info(): void
    {
        Mail::fake();
        $this->submitAndConfirm($this->payload());
        $order = Order::sole();

        $event = IntegrationEvent::sole();
        $this->assertSame(['order.submitted', 'lunar_orders', $order->id], [$event->type, $event->entity_type, $event->entity_id]);
        $this->assertSame(['sent', 'pending'], [$event->mail_status, $event->sheets_status]);
        $this->assertSame(['order', 1, 'Ona Petraitė', 'ona@example.test', 'bracket.stl, spacer.stl', 2, '5.49'],
            [$event->data['kind'], $event->data['number'], $event->data['name'], $event->data['email'], $event->data['summary'], $event->data['attachments'], $event->data['amount']]);
        Mail::assertSent(FormSubmitted::class, function (FormSubmitted $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('info@aiqme.com') && ! $mail->hasTo('ona@example.test') && $mail->hasReplyTo('ona@example.test')
                && str_contains($html, '<title>Naujas gamybos užsakymas</title>')
                && str_contains($html, 'bracket.stl, spacer.stl') && str_contains($html, '5.49 EUR');
        });
    }

    public function test_failure_after_files_are_written_leaves_no_orphans_and_no_number(): void
    {
        // Сбой в транзакции заявки после записи файлов, корзины, заказа и выдачи номера: всё откатывается, файлы удалены.
        Mail::fake();
        OrderMeta::creating(fn () => throw new \RuntimeException('order_meta down'));
        $this->submit($this->payload())->assertServerError();

        $this->nothingWritten();
        $this->assertSame(0, StlPart::count() + OrderCharge::count());
        $this->assertNull(\DB::table('counters')->where('name', 'orders')->value('value'));

        // Номер при откате не расходуется.
        OrderMeta::flushEventListeners();
        $this->assertSame(1, $this->submitAndConfirm($this->payload())['number']);
    }

    public function test_mail_failure_does_not_roll_back_the_order(): void
    {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP down');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);

        $this->submitAndConfirm($this->payload());

        $this->assertSame(1, Order::count());
        $event = IntegrationEvent::sole();
        $this->assertSame(['failed', 'pending'], [$event->mail_status, $event->sheets_status]);
        $this->assertStringContainsString('SMTP down', $event->mail_error);
    }

    public function test_honeypot_is_rejected_without_success_number_or_writes(): void
    {
        Mail::fake();
        $this->submit($this->payload(['website_hp' => 'https://spam.example']))
            ->assertUnprocessable()->assertJsonPath('errors.form.0', 'The request was not accepted. Please try again.')->assertJsonMissingPath('redirect');

        $this->nothingWritten();
        $this->get('/en/quote/submitted')->assertNotFound();
    }

    public function test_rate_limit_per_ip_returns_429_without_writes(): void
    {
        Mail::fake();
        config(['aiqme.orders.per_hour' => 2]);
        foreach (range(1, 2) as $ignored) {
            $this->token = (string) Str::uuid();
            $this->submit($this->payload())->assertCreated();
        }
        $files = Storage::disk('local')->allFiles();
        $this->token = (string) Str::uuid();
        $this->submit($this->payload())->assertTooManyRequests();

        $this->assertSame([2, 4, 2], [Order::count(), StlFile::count(), IntegrationEvent::count()]);
        $this->assertSame($files, Storage::disk('local')->allFiles());
        Mail::assertSent(FormSubmitted::class, 2);
        // Лимит — по IP: с другого адреса заявка принимается.
        $this->post('/en/quote', $this->payload(), ['Accept' => 'application/json', 'REMOTE_ADDR' => '10.0.0.2'])->assertCreated();
    }

    public function test_files_over_php_limits_get_a_clear_message(): void
    {
        Mail::fake();
        // upload_max_filesize: PHP отдаёт файл без содержимого с ошибкой загрузки.
        $tooBig = new UploadedFile(base_path('tests/fixtures/stl/cube-10mm-binary.stl'), 'huge.stl', null, UPLOAD_ERR_INI_SIZE, true);
        $this->submit($this->payload(['parts' => [['file' => $tooBig, 'unit' => 'mm', 'scale' => '100', 'quantity' => '1']]]))
            ->assertUnprocessable()->assertJsonPath('errors', ['parts.0.file' => ['File exceeds the 50 MB limit']]);
        $this->submit($this->payload(['parts' => [['file' => $tooBig, 'unit' => 'mm', 'scale' => '100', 'quantity' => '1']]]), 'lt')
            ->assertJsonPath('errors', ['parts.0.file' => ['Failas viršija 50 MB ribą']]);

        // post_max_size: тело отброшено целиком — 413 с понятным сообщением на языке адреса.
        $tooLarge = (string) (511 * 1024 * 1024);
        $this->call('POST', '/en/quote', [], [], [], ['CONTENT_LENGTH' => $tooLarge, 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(413)->assertExactJson(['message' => 'The files are too large to send: up to 50 MB per file.']);
        $this->call('POST', '/lt/kalkuliatorius', [], [], [], ['CONTENT_LENGTH' => $tooLarge, 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(413)->assertExactJson(['message' => 'Failai per dideli išsiųsti: iki 50 MB vienam failui.']);

        $this->nothingWritten();
    }

    public function test_invalid_input_is_rejected_by_the_server_without_writes(): void
    {
        Mail::fake();
        config(['aiqme.orders.per_hour' => 100]);
        $part = fn (array $override = [], string $fixture = 'cube-10mm-ascii.stl'): array => ['file' => $this->stl($fixture), 'unit' => 'mm', 'scale' => '100', 'quantity' => '1', ...$override];
        $cases = [
            'parts.0.file' => [['parts' => [$part([], 'truncated-binary.stl')]], 'truncated-binary.stl: Could not read geometry'],
            'parts.1.file' => [['parts' => [$part(), $part([], 'nan-binary.stl')]], 'nan-binary.stl: Could not read geometry'],
            'parts.0.scale' => [['parts' => [$part(['scale' => '0'])]], 'Scale must be between 1 and 1000%.'],
            'parts.0.quantity' => [['parts' => [$part(['quantity' => '101'])]], 'Quantity: from 1 to 100.'],
            'infill' => [['infill' => '101'], 'Choose a value from the list.'],
            'parts.0.unit' => [['parts' => [$part(['unit' => 'ft'])]], 'Choose a value from the list.'],
            'parts' => [['parts' => array_map(fn () => $part(), range(1, 11))], 'No more than 10 files.'],
            'material' => [['material' => 'Titanium'], 'Choose a value from the list.'],
            'phone' => [['phone' => ''], 'Fill in this field.'],
            'city' => [['city' => ''], 'Fill in this field.'],
            'email' => [['email' => 'not-an-email'], 'Enter a valid email address.'],
            'submission_token' => [['submission_token' => 'not-a-uuid'], 'The request was not accepted. Please try again.'],
        ];
        foreach ($cases as $field => [$override, $message]) {
            $response = $this->submit($this->payload($override));
            $this->assertSame([422, $message], [$response->status(), $response->json('errors')[$field][0] ?? null], $field.': '.json_encode($response->json('errors')));
        }
        // Пустая заявка (эксперимент: пустая корзина) — «Upload a model.».
        $this->submit(array_diff_key($this->payload(), ['parts' => 1]))->assertUnprocessable()->assertJsonPath('errors.parts.0', 'Upload a model.');

        $this->nothingWritten();
    }

    public function test_stl_is_analyzed_one_at_a_time_and_busy_queue_asks_to_resubmit(): void
    {
        Mail::fake();
        config(['aiqme.calculator.analysis_wait_seconds' => 0.3]);
        // Блокировку держит «другой разбор»: flock на отдельном дескрипторе конфликтует и внутри одного процесса.
        $other = fopen(storage_path('framework/stl-analysis.lock'), 'c');
        $this->assertTrue(flock($other, LOCK_EX));
        try {
            $response = $this->submit($this->payload())->assertUnprocessable();
            $this->assertSame('bracket.stl: The server is busy checking other models. Please submit the request again in a minute.', $response->json('errors')['parts.0.file'][0]);
            $this->nothingWritten();
        } finally {
            fclose($other);
        }

        // Блокировка свободна — та же заявка проходит.
        $this->submit($this->payload())->assertCreated();
    }

    public function test_collection_needs_no_address_and_lt_page_stores_lt_locale(): void
    {
        $confirmed = $this->submitAndConfirm($this->payload(['delivery' => 'pickup', 'country' => '', 'city' => '', 'address' => '', 'postcode' => '']), 'lt');

        $meta = OrderMeta::sole();
        $this->assertSame(['lt', 'pickup', null], [$meta->locale, $meta->delivery, $meta->address]);
        $this->assertStringStartsWith(config('app.url').'/lt/uzsakymas/1/', $confirmed['guest_url']);
        $this->token = (string) Str::uuid();
        $this->submitAndConfirm($this->payload());
        $this->assertSame(['lt', 'en'], OrderMeta::orderBy('number')->pluck('locale')->all());
    }

    public function test_signed_in_client_owns_the_order_without_guest_link(): void
    {
        $client = User::factory()->create(['email' => 'jonas@example.test']);
        $redirect = $this->actingAs($client)->submit($this->payload())->assertCreated()->json('redirect');
        $page = $this->get($redirect)->assertOk()->assertSee('The order is saved in your account.');
        $this->assertCount(0, Html::xpath($page->getContent())->query('//a[@data-guest-link]'));

        $order = Order::sole();
        $this->assertSame($client->id, $order->user_id);
        $this->assertSame([$client->id, $client->id], StlFile::pluck('user_id')->all());
        $this->assertNull(OrderMeta::sole()->guest_token_hash);
        // У заказа клиента гостевой ссылки нет: любой токен — 404, в том числе для самого клиента и клиента B.
        $this->get('/en/order/1/'.str_repeat('a', 43))->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/en/order/1/'.str_repeat('a', 43))->assertNotFound();
        $this->get($redirect)->assertNotFound();
    }

    public function test_guest_order_is_not_visible_to_other_clients_or_visitors_without_the_token(): void
    {
        $confirmed = $this->submitAndConfirm($this->payload());
        $token = basename($confirmed['guest_url']);
        $this->token = (string) Str::uuid();
        $other = $this->submitAndConfirm($this->payload());

        // Гость с ссылкой на другой заказ, клиент B и посетитель без токена — 404 (§6.1: null === null доступа не даёт).
        $this->get('/en/order/1/'.basename($other['guest_url']))->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/en/order/1/'.basename($other['guest_url']))->assertNotFound();
        $this->get('/en/order/1')->assertNotFound();
        $this->get('/en/order/1/')->assertNotFound();
        $this->get('/en/order/1/'.$token)->assertOk();
    }

    public function test_calculator_form_is_prefilled_from_the_client_profile(): void
    {
        $client = User::factory()->create(['name' => 'Account Name', 'email' => 'jonas@example.test']);
        $client->profile()->create(['name' => 'Jonas Jonaitis', 'company' => 'UAB Spauda', 'company_code' => '123456789',
            'vat_code' => 'LT123456789', 'address' => 'Laisvės al. 1', 'city' => 'Kaunas', 'postcode' => 'LT-44001']);
        $values = fn (string $html): array => collect(['name', 'email', 'company', 'company_code', 'vat_code', 'address', 'city', 'postcode'])
            ->mapWithKeys(fn (string $name) => [$name => Html::xpath($html)->query('//form[@id="checkout-form"]//input[@name="'.$name.'"]')->item(0)->getAttribute('value')])->all();

        $this->assertSame(['name' => 'Jonas Jonaitis', 'email' => 'jonas@example.test', 'company' => 'UAB Spauda', 'company_code' => '123456789',
            'vat_code' => 'LT123456789', 'address' => 'Laisvės al. 1', 'city' => 'Kaunas', 'postcode' => 'LT-44001'],
            $values($this->actingAs($client)->get('/en/quote')->assertOk()->getContent()));

        // Профиля нет — имя и email аккаунта; гость — пустые поля.
        $plain = User::factory()->create(['name' => 'Ieva', 'email' => 'ieva@example.test']);
        $this->assertSame(['Ieva', 'ieva@example.test', ''], array_values(array_slice($values($this->actingAs($plain)->get('/lt/kalkuliatorius')->getContent()), 0, 3)));
        auth()->logout();
        $this->assertSame(array_fill_keys(['name', 'email', 'company', 'company_code', 'vat_code', 'address', 'city', 'postcode'], ''),
            $values($this->get('/en/quote')->getContent()));
    }

    public function test_calculator_form_posts_to_the_order_endpoint_with_csrf_token_and_honeypot(): void
    {
        $xpath = Html::xpath($this->get('/en/quote')->assertOk()->getContent());
        $form = $xpath->query('//form[@id="checkout-form"]')->item(0);
        $this->assertSame([config('app.url').'/en/quote', 'post'], [$form->getAttribute('action'), $form->getAttribute('method')]);
        $this->assertCount(1, $xpath->query('//form[@id="checkout-form"]//input[@name="_token"]'));
        $this->assertCount(1, $xpath->query('//form[@id="checkout-form"]//input[@name="website_hp"]'));
        $token = $xpath->query('//form[@id="checkout-form"]//input[@name="submission_token"]')->item(0)->getAttribute('value');
        $this->assertTrue(Str::isUuid($token));
        $this->assertFalse($xpath->query('//form[@id="checkout-form"]//button[@type="submit"]')->item(0)->hasAttribute('disabled'));
        $this->assertNotSame($token, Html::xpath($this->get('/en/quote')->getContent())->query('//input[@name="submission_token"]')->item(0)->getAttribute('value'));
    }
}
