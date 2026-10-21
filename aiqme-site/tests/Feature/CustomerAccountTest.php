<?php

namespace Tests\Feature;

use App\Domain\Orders\Models\OrderMessage;
use App\Domain\Orders\OrderSubmission;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\SubmittedOrder;
use App\Models\OrderCharge;
use App\Models\StlFile;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Lunar\Models\OrderLine;
use Lunar\Models\Transaction;
use Tests\Support\Html;
use Tests\Support\LunarCapture;
use Tests\TestCase;

/**
 * Кабинет клиента и гостевая страница заказа (W-15, §1.3, §6.1, §6.2, §9.2 «Права»): вкладки Overview, Orders, Saved
 * models, Invoices; карточка заказа (стадии, оплата, открытые запросы без ссылки, платежи и возвраты, трек, сообщение и
 * три ответа, повторный заказ); право гостя в сессии и скачивание STL по нему; изоляция клиентов A/B, посетителя и гостя
 * с правом на другой заказ — 404 на GET/HEAD/Range, на гостевую страницу и на ответ; ограничение частоты по IP.
 * Заказы создаются штатной отправкой заявки (OrderSubmission), изменения — OrderWorkflow.
 */
class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $clientA;

    private User $clientB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        $this->seed(PrototypeContentSeeder::class);
        Storage::fake('local');
        $this->clientA = User::factory()->create(['name' => 'Jonas Petraitis']);
        $this->clientB = User::factory()->create(['name' => 'Ieva Kazlauskienė']);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    /** Заявка W-12: кубы 10 мм (PLA, 100 %); гость — $user = null. */
    private function submit(?User $user, array $names = ['bracket.stl'], string $delivery = 'ship'): SubmittedOrder
    {
        $cube = file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));

        return app(OrderSubmission::class)->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => 'en',
            'contact' => ['name' => 'Ona', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => null, 'company_code' => null, 'vat_code' => null],
            'delivery' => $delivery,
            'address' => $delivery === 'ship' ? ['country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gedimino pr. 1', 'postcode' => 'LT-01103'] : null,
            'material' => 'PLA',
            'infill' => 100,
            'preferences' => ['color' => 'black', 'quality' => 'standard', 'layer' => '0.2', 'finish' => 'none', 'term' => 'standard'],
            'parts' => array_map(fn (string $name): array => [
                'file' => UploadedFile::fake()->createWithContent($name, $cube), 'unit' => 'mm', 'scale' => 100, 'quantity' => 2,
            ], $names),
        ], $user);
    }

    private function guestUrl(SubmittedOrder $submitted): string
    {
        return '/en/order/'.$submitted->meta->number.'/'.$submitted->guestToken;
    }

    /** Новый посетитель: без входа и с пустой сессией. */
    private function visitor(): static
    {
        Auth::forgetGuards();
        $this->flushSession();

        return $this;
    }

    /** Цена подтверждена администратором: детали по 30 EUR, плата 5 EUR, доставка 30 EUR. */
    private function confirm(SubmittedOrder $submitted): void
    {
        $ids = $submitted->order->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
            ->modelKeys();
        // Детали по 30 EUR, плата за заказ (последняя строка) 5 EUR.
        $prices = array_fill_keys($ids, 3000);
        $prices[end($ids)] = 500;
        app(OrderWorkflow::class)->confirmPrice($this->admin, $submitted->order, $prices, 3000);
    }

    /** @return list<TestResponse> GET, HEAD и GET с Range того же адреса */
    private function fetchAll(string $url): array
    {
        return [$this->get($url), $this->call('HEAD', $url), $this->get($url, ['Range' => 'bytes=0-9'])];
    }

    private function assertAllNotFound(string $url, string $who): void
    {
        foreach ($this->fetchAll($url) as $i => $response) {
            $this->assertSame(404, $response->getStatusCode(), $who.': '.['GET', 'HEAD', 'Range'][$i].' '.$url);
            $this->assertFalse($response->headers->has('Content-Disposition'), $who);
        }
    }

    public function test_tabs_show_only_own_orders_and_models_with_real_counters(): void
    {
        $a1 = $this->submit($this->clientA, ['a-first.stl']);
        $a2 = $this->submit($this->clientA, ['a-second.stl', 'a-third.stl'], 'pickup');
        $this->submit($this->clientB, ['b-secret.stl']);
        $this->submit(null, ['guest-secret.stl']);

        $overview = $this->actingAs($this->clientA)->get('/en/account')->assertOk()
            ->assertSee('Welcome, Jonas Petraitis.')->assertSee('Your orders and next steps, at a glance.')
            ->assertDontSee('discount')->assertSee('Customer account');
        $xpath = Html::xpath($overview->getContent());
        $this->assertSame('2', trim($xpath->query('//*[@data-count="orders"]')->item(0)->textContent));
        $this->assertSame('3', trim($xpath->query('//*[@data-count="models"]')->item(0)->textContent));
        // Последний заказ — второй заказ клиента A.
        $this->assertSame([(string) $a2->meta->number], array_map(fn ($n) => $n->value, iterator_to_array($xpath->query('//*[@id="account-overview"]//tr/@data-order'))));

        $orders = Html::xpath($this->get('/lt/paskyra/orders')->assertOk()->assertSee('Užsakymų istorija')->getContent());
        $this->assertSame([(string) $a2->meta->number, (string) $a1->meta->number],
            array_map(fn ($n) => $n->value, iterator_to_array($orders->query('//tr/@data-order'))));
        $row = $orders->query('//tr[@data-order="'.$a1->meta->number.'"]')->item(0)->textContent;
        foreach (['Gamyba', 'Techninė patikra', 'Neapmokėta', now()->format('Y-m-d')] as $text) {
            $this->assertStringContainsString($text, $row);
        }

        $models = $this->get('/en/account/models')->assertOk()->assertSee(['a-first.stl', 'a-second.stl', 'a-third.stl', 'Recalculate', 'Download STL', 'Remove from list'])
            ->assertDontSee('b-secret.stl')->assertDontSee('guest-secret.stl');
        $ids = StlFile::where('user_id', $this->clientA->id)->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $models->assertSee('/stl/'.$id, false)->assertSee('/en/quote?models='.$id, false);
        }

        $this->get('/en/account/invoices')->assertOk()->assertSee('Invoices')->assertSee('No invoices yet.');

        // Клиент B: только свой заказ; новый клиент — пустые состояния.
        $bOrders = Html::xpath($this->actingAs($this->clientB)->get('/en/account/orders')->getContent())->query('//tr/@data-order');
        $this->assertSame(1, $bOrders->length);
        $this->assertNotContains($bOrders->item(0)->value, [(string) $a1->meta->number, (string) $a2->meta->number]);
        $this->actingAs($this->clientB)->get('/en/account/models')->assertSee('b-secret.stl')->assertDontSee('a-first.stl');
        $this->actingAs(User::factory()->create())->get('/en/account/models')->assertSee('No saved models yet')->assertSee('Upload your first model');
        $this->get('/en/account/orders')->assertSee('No orders yet.');
    }

    public function test_order_card_belongs_to_its_owner_only(): void
    {
        $a = $this->submit($this->clientA);
        $guest = $this->submit(null);
        $card = '/en/account/orders/'.$a->meta->number;

        $this->actingAs($this->clientA)->get($card)->assertOk()->assertSee('Order progress')->assertSee('bracket.stl');
        $this->actingAs($this->clientB)->get($card)->assertNotFound();
        $this->actingAs($this->admin)->get($card)->assertNotFound();
        // Гостевой заказ в кабинете не открывается никому (владельца нет).
        $this->actingAs($this->clientA)->get('/en/account/orders/'.$guest->meta->number)->assertNotFound();
        $this->actingAs($this->clientA)->get('/en/account/orders/999')->assertNotFound();
        $this->visitor()->get($card)->assertOk()->assertSee('id="auth-form"', false)->assertDontSee('bracket.stl');
        $this->get('/en/account/orders/DEMO-1047')->assertNotFound();
    }

    public function test_card_shows_stages_payment_open_requests_transactions_tracking_and_reorder(): void
    {
        $a = $this->submit($this->clientA, ['bracket.stl', 'cover.stl']);
        $card = '/en/account/orders/'.$a->meta->number;

        // До подтверждения: предварительная сумма и стадия review.
        $this->actingAs($this->clientA)->get($card)->assertSee('Estimated total')->assertSee('Technical review')
            ->assertSee('Current status')->assertSee('Tracking information appears after shipment preparation.')
            ->assertDontSee('Outstanding');

        $this->confirm($a);
        $flow = app(OrderWorkflow::class);
        $flow->requestPayment($this->admin, $a->order, 4000);
        LunarCapture::create($a->order, 2500, 'test-charge-1-not-real');
        Transaction::create(['order_id' => $a->order->id, 'success' => true, 'type' => 'refund', 'driver' => 'test', 'amount' => 500,
            'reference' => 'test-refund-not-real', 'status' => 'refunded', 'meta' => []]);
        Transaction::create(['order_id' => $a->order->id, 'success' => false, 'type' => 'capture', 'driver' => 'test', 'amount' => 9900,
            'reference' => 'test-failed-not-real', 'status' => 'failed', 'meta' => []]);
        $flow->setTracking($this->admin, $a->order, 'DPD', 'LT000000001');

        $page = $this->get($card)->assertOk();
        $html = Html::xpath($page->getContent());
        $this->assertSame('confirmed', $html->query('//*[@data-stage]/@data-stage')->item(0)->value);
        $this->assertSame(7, $html->query('//div[@class="timeline"]/div')->length);
        $this->assertSame(2, $html->query('//div[@class="timeline"]/div[@class="active"]')->length);
        $this->assertSame('€95.00', trim($html->query('//*[@data-total]')->item(0)->textContent));
        $this->assertSame('€20.00', trim($html->query('//*[@data-paid]')->item(0)->textContent));
        $this->assertSame('€75.00', trim($html->query('//*[@data-due]')->item(0)->textContent));
        $page->assertSee('Partly paid')->assertSee('Payment requests')->assertSee('Tracking: DPD LT000000001');
        $request = $html->query('//*[@data-payment-request]')->item(0);
        $this->assertStringContainsString('€40.00', $request->textContent);
        $this->assertSame(0, $html->query('.//a', $request)->length);
        $page->assertDontSee('/checkout', false)->assertDontSee('/apmokejimas', false);

        // Платежи и возвраты (D-04): дата, вид, сумма; неуспешная транзакция не показывается.
        $transactions = iterator_to_array($html->query('//*[@data-transaction]'));
        $this->assertCount(2, $transactions);
        $this->assertStringContainsString(now()->format('Y-m-d').' · Payment', $transactions[0]->textContent);
        $this->assertStringContainsString('€25.00', $transactions[0]->textContent);
        $this->assertStringContainsString('Refund', $transactions[1]->textContent);
        $this->assertStringContainsString('−€5.00', $transactions[1]->textContent);
        $page->assertDontSee('€99.00');

        // Файлы — защищённая выдача; повторный заказ — калькулятор с id файлов заказа.
        $files = StlFile::where('order_id', $a->order->id)->orderBy('id')->pluck('id');
        foreach ($files as $id) {
            $page->assertSee('href="'.route('stl.download', $id).'"', false);
        }
        $this->assertSame(route('en.calculator', ['models' => $files->join(',')]), $html->query('//a[@data-reorder]/@href')->item(0)->value);
        $page->assertSee('Reorder with current pricing');
    }

    public function test_guest_page_grants_the_session_right_and_others_get_404_on_page_files_and_reply(): void
    {
        $guest = $this->submit(null, ['guest-part.stl']);
        $other = $this->submit(null, ['other-part.stl']);
        $file = '/stl/'.StlFile::where('order_id', $guest->order->id)->sole()->id;
        $number = $guest->meta->number;
        $tokenless = ['/en/order/'.$number, '/en/order/'.$number.'/', '/en/order/'.$number.'/'.str_repeat('A', 43), '/lt/uzsakymas/'.$number.'/'.$other->guestToken];

        // Посетитель без права, клиент B, клиент A (владелец другого заказа) и гость с правом на другой заказ — 404.
        $this->visitor();
        $who = [
            'посетитель' => fn () => $this->visitor(),
            'клиент B' => fn () => $this->visitor()->actingAs($this->clientB),
            'гость другого заказа' => fn () => $this->visitor()->get($this->guestUrl($other))->assertOk(),
        ];
        foreach ($who as $name => $setUp) {
            $setUp();
            foreach ($tokenless as $url) {
                $this->get($url)->assertNotFound();
            }
            $this->assertAllNotFound($file, $name);
            $this->post('/en/order/'.$number.'/reply', ['kind' => 'agree'])->assertNotFound();
        }
        $this->assertSame(0, OrderMessage::count());

        // Верный токен: страница без списка других заказов и без повторного заказа; право в сессии — скачивание без токена в адресе.
        $page = $this->visitor()->get($this->guestUrl($guest))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Order progress')->assertSee('Order No. '.$number)->assertSee('guest-part.stl')
            ->assertDontSee('other-part.stl')->assertDontSee('data-reorder', false)->assertSee('<meta name="robots" content="noindex', false);
        $this->assertStringNotContainsString($guest->guestToken, Str::after($page->getContent(), '<main'));
        [$get, $head, $range] = $this->fetchAll($file);
        $get->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=guest-part.stl');
        $head->assertOk();
        $range->assertStatus(206);
        $this->get('/en/order/'.$number)->assertNotFound();

        // Перевыпуск ссылки администратором: прежнее право в сессии больше не действует.
        $newToken = app(OrderWorkflow::class)->reissueGuestLink($this->admin, $guest->order);
        $this->assertAllNotFound($file, 'гость после перевыпуска');
        $this->get('/en/order/'.$number.'/'.$newToken)->assertOk();
        $this->get($file)->assertOk();
    }

    public function test_wrong_guest_tokens_are_rate_limited_by_ip(): void
    {
        config(['aiqme.orders.guest_per_minute' => 3]);
        $guest = $this->submit(null);
        $wrong = '/en/order/'.$guest->meta->number.'/'.str_repeat('B', 43);

        foreach (range(1, 3) as $attempt) {
            $this->get($wrong)->assertNotFound();
        }
        $this->get($wrong)->assertStatus(429);
        // Даже верный токен с того же IP — 429 до конца минуты; другой IP — работает.
        $this->get($this->guestUrl($guest))->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->get($this->guestUrl($guest))->assertOk();
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get($this->guestUrl($guest))->assertOk();
    }

    public function test_admin_message_and_three_replies_for_owner_and_guest_with_the_right(): void
    {
        $flow = app(OrderWorkflow::class);
        $a = $this->submit($this->clientA);
        $guest = $this->submit(null);
        $flow->postAdminMessage($this->admin, $a->order, 'The wall is too thin; we propose 1.2 mm.');
        $flow->postAdminMessage($this->admin, $guest->order, 'Nurodykite spalvą.');
        $card = '/en/account/orders/'.$a->meta->number;

        $page = $this->actingAs($this->clientA)->get($card)->assertSee('Your decision is needed')->assertSee('The wall is too thin; we propose 1.2 mm.');
        $form = Html::xpath($page->getContent())->query('//form[@id="order-reply"]')->item(0);
        $this->assertSame(route('en.order.reply', $a->meta->number), $form->getAttribute('action'));
        $this->assertSame(['agree', 'question', 'cancel_request'], array_map(fn ($b) => $b->getAttribute('value'), iterator_to_array(Html::xpath($page->getContent())->query('//form[@id="order-reply"]//button'))));
        $page->assertSee(['Approve the proposed revision', 'Request clarification', 'Request cancellation']);

        // Клиент B, администратор, посетитель — 404 без записи; неизвестный ответ — ошибка без записи.
        $this->actingAs($this->clientB)->post('/en/order/'.$a->meta->number.'/reply', ['kind' => 'agree'])->assertNotFound();
        $this->actingAs($this->admin)->post('/en/order/'.$a->meta->number.'/reply', ['kind' => 'agree'])->assertNotFound();
        $this->visitor()->post('/en/order/'.$a->meta->number.'/reply', ['kind' => 'agree'])->assertNotFound();
        $this->actingAs($this->clientA)->from($card)->post('/en/order/'.$a->meta->number.'/reply', ['kind' => 'refund'])->assertSessionHasErrors('kind');
        $this->assertSame(0, OrderMessage::where('kind', '!=', OrderMessage::ADMIN)->count());

        // Владелец: ответ записан, заказ не отменён, на карточке — выбранный ответ и пояснение прототипа.
        $this->actingAs($this->clientA)->from($card)->post('/en/order/'.$a->meta->number.'/reply', ['kind' => 'cancel_request'])
            ->assertRedirect($card);
        $reply = OrderMessage::where('kind', 'cancel_request')->sole();
        $this->assertSame([$a->order->id, $this->clientA->id], [$reply->order_id, $reply->user_id]);
        $this->get($card)->assertSee('Your choice has been recorded. A team member must review it; production does not start automatically.')
            ->assertSee('data-reply="cancel_request"', false)->assertSee('Technical review');

        // Гость с правом на свой заказ отвечает на свой; на заказ клиента A — 404.
        $this->visitor()->get($this->guestUrl($guest))->assertSee('Nurodykite spalvą.')->assertSee('id="order-reply"', false);
        $this->from($this->guestUrl($guest))->post('/en/order/'.$guest->meta->number.'/reply', ['kind' => 'question'])->assertRedirect($this->guestUrl($guest));
        $this->post('/en/order/'.$a->meta->number.'/reply', ['kind' => 'question'])->assertNotFound();
        $guestReply = OrderMessage::where('kind', 'question')->sole();
        $this->assertSame([$guest->order->id, null], [$guestReply->order_id, $guestReply->user_id]);
        $this->assertSame(3, OrderMessage::whereIn('kind', OrderMessage::REPLIES)->count() + 1);
    }

    public function test_card_without_admin_message_has_no_reply_buttons(): void
    {
        $a = $this->submit($this->clientA);
        $this->actingAs($this->clientA)->get('/en/account/orders/'.$a->meta->number)->assertOk()
            ->assertDontSee('id="order-reply"', false)->assertDontSee('Your decision is needed');
    }

    public function test_hiding_a_model_removes_it_from_the_list_and_keeps_the_order_file(): void
    {
        $a = $this->submit($this->clientA, ['keep.stl', 'hide.stl']);
        $file = StlFile::where('original_name', 'hide.stl')->sole();
        $b = $this->submit($this->clientB, ['b.stl']);
        $bFile = StlFile::where('order_id', $b->order->id)->sole();

        // Чужую модель скрыть нельзя (404, без изменений); посетитель — на вход.
        $this->actingAs($this->clientA)->post('/en/account/models/'.$bFile->id.'/hide')->assertNotFound();
        $this->assertNull($bFile->fresh()->hidden_at);
        $this->visitor()->post('/en/account/models/'.$file->id.'/hide')->assertRedirect(route('en.account.login'));

        $this->actingAs($this->clientA)->post('/en/account/models/'.$file->id.'/hide')
            ->assertRedirect(route('en.account.models'))->assertSessionHas('status', 'The model was removed from the list. The order file is kept.');
        $this->get('/en/account/models')->assertSee('keep.stl')->assertDontSee('hide.stl');
        $this->assertSame('1', trim(Html::xpath($this->get('/en/account')->getContent())->query('//*[@data-count="models"]')->item(0)->textContent));

        $file->refresh();
        $this->assertNotNull($file->hidden_at);
        $this->assertSame($a->order->id, $file->order_id);
        Storage::disk('local')->assertExists($file->path);
        $this->get('/en/account/orders/'.$a->meta->number)->assertSee('hide.stl');
        $this->get('/stl/'.$file->id)->assertOk();
        // Повторное скрытие — 404 (модели в списке уже нет).
        $this->post('/en/account/models/'.$file->id.'/hide')->assertNotFound();
    }

    public function test_calculator_with_models_query_downloads_only_own_files(): void
    {
        $a = $this->submit($this->clientA, ['mine.stl']);
        $b = $this->submit($this->clientB, ['theirs.stl']);
        $mine = StlFile::where('order_id', $a->order->id)->sole();
        $theirs = StlFile::where('order_id', $b->order->id)->sole();

        // Страница калькулятора с ?models= не раскрывает чужие файлы: файлы берёт JS через /stl/<id> (W-32).
        $this->actingAs($this->clientA)->get('/en/quote?models='.$mine->id.','.$theirs->id)->assertOk()
            ->assertDontSee('theirs.stl')->assertDontSee('mine.stl');
        $this->get('/stl/'.$mine->id)->assertOk();
        $this->assertAllNotFound('/stl/'.$theirs->id, 'клиент A — чужой id');
    }
}
