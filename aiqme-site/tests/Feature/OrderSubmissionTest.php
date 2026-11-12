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
use Illuminate\Support\Facades\RateLimiter;
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
    private function payload(array $override = [], array $printing = []): array
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
            'parts' => [
                $this->printed(['file' => $this->stl('cube-10mm-ascii.stl', 'bracket.stl'), 'unit' => 'mm', 'scale' => '100', 'quantity' => '3', ...$printing]),
                $this->printed(['file' => $this->stl('cube-10mm-binary.stl', 'spacer.stl'), 'unit' => 'cm', 'scale' => '10', 'quantity' => '1', ...$printing]),
            ],
            ...$override,
        ];
    }

    /**
     * Позиция печати в формате позиций (правка 02.11, Г8: общих параметров у заказа больше нет): PLA, 100 %, пожелания по
     * умолчанию, обычный срок.
     *
     * @param  array<string, mixed>  $part
     * @return array<string, mixed>
     */
    private function printed(array $part): array
    {
        return ['method' => 'printing', 'material' => 'PLA', 'infill' => '100', 'color' => 'black', 'purpose' => 'visual', 'service' => 'none',
            'surface' => 'standard', 'priority' => 'standard', ...$part];
    }

    /** @param  array<string, mixed>  $payload */
    private function submit(array $payload, string $locale = 'en'): TestResponse
    {
        return $this->post($locale === 'lt' ? '/lt/kalkuliatorius' : '/en/quote', $payload, ['Accept' => 'application/json']);
    }

    /**
     * Отправка и страница подтверждения: номер и гостевая ссылка (если есть); total — серверная оценка заказа (правка 05.11,
     * H-04: на странице подтверждения сумм нет — проверяется здесь при каждой отправке).
     */
    private function submitAndConfirm(array $payload, string $locale = 'en'): array
    {
        $redirect = $this->submit($payload, $locale)->assertCreated()->json('redirect');
        $content = $this->get($redirect)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $page = Html::xpath($content);
        $link = $page->query('//a[@data-guest-link]')->item(0);
        $number = (int) $page->query('//*[@data-order-number]')->item(0)->getAttribute('data-order-number');
        $main = Html::text($page->query('//main')->item(0));
        $this->assertStringNotContainsString('€', $main);
        $this->assertSame(0, $page->query('//*[@data-total] | //*[@data-cents] | //*[@data-part]')->length);

        return [
            'number' => $number,
            'total' => Order::findOrFail(OrderMeta::where('number', $number)->value('order_id'))->total->value,
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
            $this->printed(['file' => $this->stl('cube-10mm-ascii.stl', 'bracket.stl'), 'unit' => 'mm', 'scale' => '100', 'quantity' => '3', 'cents' => '1']),
            $this->printed(['file' => $this->stl('cube-10mm-binary.stl', 'spacer.stl'), 'unit' => 'cm', 'scale' => '10', 'quantity' => '1']),
        ]]));

        $order = Order::with('lines.purchasable')->sole();
        $meta = OrderMeta::sole();
        // Правка 12.11, K-05: номер — случайный 8-значный, а не 1.
        $this->assertMatchesRegularExpression('/^[1-9]\\d{7}$/', $order->reference);
        $this->assertSame((int) $order->reference, $meta->number);
        $this->assertSame(['number' => $meta->number, 'total' => 549], ['number' => $confirmed['number'], 'total' => $confirmed['total']]);
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
        // Правка 02.11, Г6, Г8: параметры позиции — способ printing, дата и заметка позиции пустые.
        $this->assertSame(['method' => 'printing', 'material' => 'PLA', 'infill' => 100, 'infill_consult' => false, 'unit' => 'mm', 'scale' => 100, 'quantity' => 3, 'color' => 'black',
            'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'term' => 'standard', 'needed_by' => null, 'notes' => null], $parts[0]->purchasable->parameters);
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
        $this->assertNull($meta->notes);
        $guestToken = basename($confirmed['guest_url']);
        $this->assertSame(43, strlen($guestToken));
        $this->assertSame(hash('sha256', $guestToken), $meta->guest_token_hash);
        $this->assertStringNotContainsString($guestToken, json_encode(OrderMeta::sole()->getAttributes()));
    }

    /**
     * Шаг 2 по макету заказчика (правка 28.10, E-05): «Reikia konsultacijos» у заполнения считается и хранится как
     * заполнение по умолчанию (20) с пометкой; пожелания и notes сумму не меняют, notes сохраняется дословно.
     */
    public function test_infill_consultation_preferences_and_notes(): void
    {
        config(['aiqme.orders.limit' => 100]);
        // Правка 02.11, Г8: пожелания, срок и заметка — у каждой позиции ($printing — в обе позиции заявки); у заказа notes нет.
        $order = function (array $printing): array {
            $this->token = (string) Str::uuid();
            $total = $this->submitAndConfirm($this->payload([], $printing))['total'];
            $meta = OrderMeta::where('submission_token', $this->token)->sole();
            $part = Order::findOrFail($meta->order_id)->lines->first(fn ($line) => $line->purchasable instanceof StlPart)->purchasable;

            return [$total, $part->parameters, $meta->notes];
        };

        [$at20, $parameters20] = $order(['infill' => '20']);
        [$atConsult, $parametersConsult] = $order(['infill' => 'consult', 'notes' => '']);
        [$at100] = $order([]);
        $this->assertSame($at20, $atConsult);
        $this->assertNotSame($at100, $atConsult);
        $this->assertSame([20, false], [$parameters20['infill'], $parameters20['infill_consult']]);
        $this->assertSame([20, true], [$parametersConsult['infill'], $parametersConsult['infill_consult']]);
        $this->assertNull($parametersConsult['notes']);

        $notes = "Iki penktadienio.\n<b>Matinis</b> paviršius";
        [$wishes, $parameters, $orderNotes] = $order(['purpose' => 'consult', 'service' => 'painting', 'surface' => 'high', 'priority' => 'priority', 'notes' => $notes]);
        $this->assertSame($at100, $wishes);
        $this->assertSame(['consult', 'painting', 'high', 'priority'], [$parameters['purpose'], $parameters['service'], $parameters['surface'], $parameters['term']]);
        $this->assertSame($notes, $parameters['notes']);
        $this->assertNull($orderNotes);

        // Тот же токен с другой заметкой или с консультацией — отказ без записей.
        $count = [Order::count(), StlFile::count(), IntegrationEvent::count()];
        foreach ([['notes' => 'Kita'], ['notes' => $notes, 'infill' => 'consult']] as $override) {
            $this->submit($this->payload([], ['purpose' => 'consult', 'service' => 'painting', 'surface' => 'high', 'priority' => 'priority', ...$override]))
                ->assertUnprocessable()->assertJsonPath('errors.submission_token.0', 'This order has already been submitted with other details. Reload the page and try again.');
        }
        $this->assertSame($count, [Order::count(), StlFile::count(), IntegrationEvent::count()]);
    }

    /**
     * П7: порог предупреждения на сервере — габарит из настроек, 1000 мм. Куб 10 мм в дюймах: масштаб 473 % ≈ 1201 мм
     * (больше 1000 — предупреждение), 315 % ≈ 800 мм (без предупреждения; при прежних 250 мм оно было бы).
     */
    public function test_server_warns_about_parts_over_the_1000_mm_build_size(): void
    {
        $this->submit($this->payload(['parts' => [
            $this->printed(['file' => $this->stl('cube-10mm-binary.stl', 'large.stl'), 'unit' => 'in', 'scale' => '473', 'quantity' => '1']),
            $this->printed(['file' => $this->stl('cube-10mm-binary.stl', 'medium.stl'), 'unit' => 'in', 'scale' => '315', 'quantity' => '1']),
        ]]))->assertCreated();

        [$large, $medium] = StlPart::query()->orderBy('id')->get()->all();
        $this->assertEqualsWithDelta(1201.42, $large->measurement['dimensions_mm'][0], 0.01);
        $this->assertContains('exceeds_build_size', $large->measurement['warnings']);
        $this->assertEqualsWithDelta(800.1, $medium->measurement['dimensions_mm'][0], 0.01);
        $this->assertNotContains('exceeds_build_size', $medium->measurement['warnings']);
    }

    public function test_guest_link_opens_the_order_and_wrong_number_or_token_is_404(): void
    {
        $confirmed = $this->submitAndConfirm($this->payload());
        // Правка 12.11, K-05: номер — случайный 8-значный, а не 1.
        $number = $confirmed['number'];
        $this->assertStringStartsWith(config('app.url').'/en/order/'.$number.'/', $confirmed['guest_url']);

        $page = $this->get($confirmed['guest_url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        // Правка 05.11, H-04: до подтверждения цены суммы нет (была предварительная €5.49) — заметка о подтверждении цены.
        $page->assertSee('Order No. '.$number)->assertSee('bracket.stl')->assertSee('× 3')->assertDontSee('€')
            ->assertSee('We will confirm the final price and production time after reviewing your request.');
        $this->assertStringContainsString('noindex', $page->getContent());
        $token = basename($confirmed['guest_url']);
        $this->get('/lt/uzsakymas/'.$number.'/'.$token)->assertOk()->assertSee('Užsakymas Nr. '.$number);

        $this->get('/en/order/'.$number.'/'.strrev($token))->assertNotFound();
        $this->get('/en/order/'.$number.'/x')->assertNotFound();
        $this->get('/en/order/'.($number + 1).'/'.$token)->assertNotFound();
        // Порядковый номер (Lunar id) доступа к заказу не даёт.
        $this->get('/en/order/'.Order::sole()->id.'/'.$token)->assertNotFound();
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
        $this->get($second['guest_url'])->assertOk()->assertSee('Order No. '.$second['number']); // Правка 12.11, K-05: номер — случайный 8-значный, а не 1.
    }

    public function test_same_token_with_other_data_is_rejected_without_writes(): void
    {
        $this->submitAndConfirm($this->payload());
        $this->submit($this->payload(['phone' => '+370 600 11111']))
            ->assertUnprocessable()->assertJsonPath('errors.submission_token.0', 'This order has already been submitted with other details. Reload the page and try again.');
        $this->submit($this->payload(['parts' => [$this->printed(['file' => $this->stl('cube-10mm-ascii.stl', 'bracket.stl'), 'unit' => 'mm', 'scale' => '100', 'quantity' => '4'])]]))
            ->assertUnprocessable();

        $this->assertSame([1, 2, 1], [Order::count(), StlFile::count(), IntegrationEvent::count()]);
        $this->assertCount(2, Storage::disk('local')->allFiles('stl'));
    }

    /** Правка 12.11, K-05: разные заявки — разные случайные 8-значные номера (reference и order_meta.number), не подряд. */
    public function test_different_submissions_get_random_eight_digit_numbers(): void
    {
        $numbers = [];
        foreach (range(1, 3) as $ignored) {
            $this->token = (string) Str::uuid();
            $numbers[] = $this->submitAndConfirm($this->payload())['number'];
        }

        $this->assertCount(3, array_unique($numbers));
        foreach ($numbers as $number) {
            $this->assertTrue($number >= 10000000 && $number <= 99999999, (string) $number);
        }
        $this->assertNotSame(1, abs($numbers[1] - $numbers[0]));
        $this->assertNotSame(1, abs($numbers[2] - $numbers[1]));
        $this->assertSame(array_map('strval', $numbers), Order::orderBy('id')->pluck('reference')->all());
        $this->assertSame($numbers, OrderMeta::orderBy('id')->pluck('number')->all());
        $this->assertNull(\DB::table('counters')->where('name', 'orders')->value('value'));
    }

    public function test_event_is_recorded_with_the_order_and_mail_goes_to_info(): void
    {
        Mail::fake();
        $this->submitAndConfirm($this->payload());
        $order = Order::sole();

        $event = IntegrationEvent::sole();
        $this->assertSame(['order.submitted', 'lunar_orders', $order->id], [$event->type, $event->entity_type, $event->entity_id]);
        $this->assertSame(['sent', 'pending'], [$event->mail_status, $event->sheets_status]);
        // Правка 12.11, K-05: номер в событии — номер заказа (случайный), а не 1.
        $this->assertSame(['order', OrderMeta::sole()->number, 'Ona Petraitė', 'ona@example.test', 'bracket.stl, spacer.stl', 2, '5.49'],
            [$event->data['kind'], $event->data['number'], $event->data['name'], $event->data['email'], $event->data['summary'], $event->data['attachments'], $event->data['amount']]);
        Mail::assertSent(FormSubmitted::class, function (FormSubmitted $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('info@aiqme.com') && ! $mail->hasTo('ona@example.test') && $mail->hasReplyTo('ona@example.test')
                && str_contains($html, '<title>Naujas gamybos užsakymas</title>')
                // Правка 02.11, Г7: у каждого файла способ; все позиции оценены — без пометки специалиста.
                && str_contains($html, 'bracket.stl — 3D spausdinimas / PLA, spacer.stl — 3D spausdinimas / PLA') && str_contains($html, '5.49 EUR')
                && ! str_contains($html, 'specialisto');
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

        // Правка 12.11, K-05: номер случайный — после отката заявка проходит и получает свой номер.
        OrderMeta::flushEventListeners();
        $number = $this->submitAndConfirm($this->payload())['number'];
        $this->assertSame(OrderMeta::sole()->number, $number);
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

    /**
     * Правка 11.11, J-06: заказчик дважды подряд отправил шаг 3 и получил 429. Одна отправка — одна попытка лимита orders
     * (маршрут без двойного throttle), две подряд с настройками по умолчанию — без 429. Причина — лимит считает и
     * отклонённые проверкой (422) отправки того же IP: после исправлений формы 429 наступает раньше, чем «на пятом заказе».
     */
    public function test_two_step_3_submissions_in_a_row_are_not_throttled_but_rejected_attempts_count(): void
    {
        Mail::fake();
        $key = md5('orders'.'127.0.0.1');
        $this->submit($this->payload())->assertCreated();
        $this->assertSame(1, RateLimiter::attempts($key));
        $this->token = (string) Str::uuid();
        $this->submit($this->payload())->assertCreated();
        $this->assertSame(2, RateLimiter::attempts($key));

        $this->submit($this->payload(['phone' => '']))->assertUnprocessable();
        $this->assertSame(3, RateLimiter::attempts($key));
    }

    public function test_rate_limit_per_ip_returns_429_without_writes(): void
    {
        Mail::fake();
        config(['aiqme.orders.limit' => 2]);
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

    /**
     * Правка 11.11, J-06: по умолчанию 5 заявок за 5 минут с IP; шестая — 429 JSON с текстом на языке адреса и числом минут
     * (его показывает калькулятор), после окна — принимается.
     */
    public function test_orders_allow_five_in_five_minutes_then_429_with_minutes_and_reset(): void
    {
        Mail::fake();
        $this->freezeTime();
        $this->assertSame([5, 5], [config('aiqme.orders.limit'), config('aiqme.orders.limit_minutes')]);
        foreach (range(1, 5) as $ignored) {
            $this->token = (string) Str::uuid();
            $this->submit($this->payload(), 'lt')->assertCreated();
        }
        $this->token = (string) Str::uuid();
        $this->submit($this->payload(), 'lt')->assertTooManyRequests()->assertHeader('Retry-After', '300')
            ->assertExactJson(['message' => 'Per daug užklausų. Bandykite po 5 min.']);
        $this->travel(4)->minutes();
        $this->submit($this->payload())->assertTooManyRequests()->assertExactJson(['message' => 'Too many requests. Try again in 1 min.']);
        $this->assertSame(5, Order::count());

        $this->travel(61)->seconds();
        $this->submit($this->payload())->assertCreated();
        $this->assertSame(6, Order::count());
    }

    public function test_files_over_php_limits_get_a_clear_message(): void
    {
        Mail::fake();
        // upload_max_filesize: PHP отдаёт файл без содержимого с ошибкой загрузки.
        $tooBig = new UploadedFile(base_path('tests/fixtures/stl/cube-10mm-binary.stl'), 'huge.stl', null, UPLOAD_ERR_INI_SIZE, true);
        $this->submit($this->payload(['parts' => [$this->printed(['file' => $tooBig, 'unit' => 'mm', 'scale' => '100', 'quantity' => '1'])]]))
            ->assertUnprocessable()->assertJsonPath('errors', ['parts.0.file' => ['File exceeds the 50 MB limit']]);
        $this->submit($this->payload(['parts' => [$this->printed(['file' => $tooBig, 'unit' => 'mm', 'scale' => '100', 'quantity' => '1'])]]), 'lt')
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
        config(['aiqme.orders.limit' => 100]);
        $part = fn (array $override = [], string $fixture = 'cube-10mm-ascii.stl'): array => $this->printed(['file' => $this->stl($fixture), 'unit' => 'mm', 'scale' => '100', 'quantity' => '1', ...$override]);
        $cases = [
            'parts.0.file' => [['parts' => [$part([], 'truncated-binary.stl')]], 'truncated-binary.stl: Could not read geometry'],
            'parts.1.file' => [['parts' => [$part(), $part([], 'nan-binary.stl')]], 'nan-binary.stl: Could not read geometry'],
            'parts.0.scale' => [['parts' => [$part(['scale' => '0'])]], 'Scale must be between 1 and 1000%.'],
            'parts.0.quantity' => [['parts' => [$part(['quantity' => '101'])]], 'Quantity: from 1 to 100.'],
            // Правка 02.11, Г8: параметры печати, срок и заметка — у позиции (parts.N.*), а не у заказа.
            'parts.0.infill' => [['parts' => [$part(['infill' => '101'])]], 'Choose a value from the list.'],
            'parts.0.purpose' => [['parts' => [$part(['purpose' => 'fine'])]], 'Choose a value from the list.'],
            'parts.0.service' => [['parts' => [$part(['service' => 'smooth'])]], 'Choose a value from the list.'],
            'parts.0.surface' => [['parts' => [$part(['surface' => '0.2'])]], 'Choose a value from the list.'],
            'parts.0.priority' => [['parts' => [$part(['priority' => 'express'])]], 'Choose a value from the list.'],
            'parts.0.notes' => [['parts' => [$part(['notes' => str_repeat('a', 2001)])]], 'Too long: up to 2000 characters.'],
            'parts.0.unit' => [['parts' => [$part(['unit' => 'ft'])]], 'Choose a value from the list.'],
            'parts' => [['parts' => array_map(fn () => $part(), range(1, 11))], 'No more than 10 files.'],
            'parts.0.material' => [['parts' => [$part(['material' => 'Titanium'])]], 'Choose a value from the list.'],
            'phone' => [['phone' => ''], 'Fill in this field.'],
            'city' => [['city' => ''], 'Fill in this field.'],
            'email' => [['email' => 'not-an-email'], 'Enter a valid email address.'],
            'submission_token' => [['submission_token' => 'not-a-uuid'], 'The request was not accepted. Please try again.'],
        ];
        foreach ($cases as $field => [$override, $message]) {
            $response = $this->submit($this->payload($override));
            $this->assertSame([422, $message], [$response->status(), $response->json('errors')[$field][0] ?? null], $field.': '.json_encode($response->json('errors')));
        }
        $response = $this->submit($this->payload([], ['infill' => 'abc']));
        $this->assertSame([422, 'Choose a value from the list.'], [$response->status(), $response->json('errors')['parts.0.infill'][0] ?? null]);
        $this->submit($this->payload(['parts' => [array_diff_key($part(), ['purpose' => 1])]]))->assertUnprocessable()->assertJsonPath('errors', ['parts.0.purpose' => ['Fill in this field.']]);
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
        // Правка 12.11, K-05: номер — случайный 8-значный, а не 1.
        $this->assertStringStartsWith(config('app.url').'/lt/uzsakymas/'.$meta->number.'/', $confirmed['guest_url']);
        $this->token = (string) Str::uuid();
        $this->submitAndConfirm($this->payload());
        $this->assertSame(['lt', 'en'], OrderMeta::orderBy('id')->pluck('locale')->all());
    }

    /**
     * Правка 12.11, K-05: клиент не видит ни Lunar id заказа, ни порядкового номера. id заказов сдвинуты к 7654321, счётчик
     * прежних заказов counters «orders» — 4242 (прежний генератор выдал бы 4243, 4244): этих чисел нет в HTML страницы
     * подтверждения, гостевой страницы и кабинета; счётчик не меняется.
     */
    public function test_pages_show_neither_the_lunar_id_nor_a_sequential_number(): void
    {
        $sequence = \DB::selectOne('select last_value, is_called from lunar_orders_id_seq'); // setval вне транзакции теста — вернуть
        \DB::table('counters')->insert(['name' => 'orders', 'value' => 4242]);
        try {
            \DB::select("select setval('lunar_orders_id_seq', 7654320)");
            $pages = [];
            $guest = $this->submit($this->payload())->assertCreated()->json('redirect');
            $pages['guest confirmation'] = $this->get($guest)->assertOk()->getContent();
            $guestUrl = Html::xpath($pages['guest confirmation'])->query('//a[@data-guest-link]')->item(0)->getAttribute('href');
            $pages['guest page'] = $this->get($guestUrl)->assertOk()->getContent();

            $this->flushSession();
            $client = User::factory()->create(['email' => 'jonas@example.test']);
            $this->token = (string) Str::uuid();
            $redirect = $this->actingAs($client)->submit($this->payload())->assertCreated()->json('redirect');
            $pages['client confirmation'] = $this->get($redirect)->assertOk()->getContent();
            $number = OrderMeta::where('order_id', Order::where('user_id', $client->id)->value('id'))->value('number');
            foreach (['/en/account', '/en/account/orders', '/en/account/orders/'.$number, '/en/account/models', '/en/account/invoices'] as $url) {
                $pages[$url] = $this->get($url)->assertOk()->getContent();
            }

            $this->assertSame([7654321, 7654322], Order::orderBy('id')->pluck('id')->all());
            foreach (OrderMeta::pluck('number') as $own) {
                $this->assertMatchesRegularExpression('/^[1-9]\d{7}$/', (string) $own);
            }
            foreach ($pages as $name => $html) {
                foreach (['7654321', '7654322', '4243', '4244'] as $hidden) {
                    $this->assertDoesNotMatchRegularExpression('/(?<!\w)'.$hidden.'(?!\w)/', $html, $name);
                }
            }
            $this->assertStringContainsString('Order No. '.$number, $pages['client confirmation']);
            $this->assertSame(4242, (int) \DB::table('counters')->where('name', 'orders')->value('value'));
        } finally {
            \DB::select('select setval(\'lunar_orders_id_seq\', ?, ?)', [$sequence->last_value, $sequence->is_called]);
        }
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
        // Правка 12.11, K-05: номер заказа — случайный, а не 1.
        $this->get('/en/order/'.$order->reference.'/'.str_repeat('a', 43))->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/en/order/'.$order->reference.'/'.str_repeat('a', 43))->assertNotFound();
        $this->get($redirect)->assertNotFound();
    }

    public function test_guest_order_is_not_visible_to_other_clients_or_visitors_without_the_token(): void
    {
        $confirmed = $this->submitAndConfirm($this->payload());
        $token = basename($confirmed['guest_url']);
        $this->token = (string) Str::uuid();
        $other = $this->submitAndConfirm($this->payload());

        // Гость с ссылкой на другой заказ, клиент B и посетитель без токена — 404 (§6.1: null === null доступа не даёт).
        // Правка 12.11, K-05: номер — случайный 8-значный, а не 1.
        $number = $confirmed['number'];
        $this->get('/en/order/'.$number.'/'.basename($other['guest_url']))->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/en/order/'.$number.'/'.basename($other['guest_url']))->assertNotFound();
        $this->get('/en/order/'.$number)->assertNotFound();
        $this->get('/en/order/'.$number.'/')->assertNotFound();
        $this->get('/en/order/'.$number.'/'.$token)->assertOk();
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
