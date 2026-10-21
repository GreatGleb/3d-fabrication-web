<?php

namespace Tests\Feature;

use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Orders\Models\OrderMessage;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\PriceRevision;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderSubmission;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentRequest;
use App\Livewire\Admin\Customers;
use App\Livewire\Admin\Orders\Card;
use App\Livewire\Admin\Orders\Index;
use App\Livewire\Admin\Overview;
use App\Models\OrderCharge;
use App\Models\StlFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Tests\Support\LunarCapture;
use Tests\TestCase;

/**
 * Админка W-14 (§1.4 Overview, Orders, Customers): вкладки только администратору; метрики и предупреждение о неудачных
 * фоновых заданиях; список с поиском и фильтром; карточка заказа — каждое действие идёт через OrderWorkflow (его правила
 * и отказы видны в карточке), опасные действия — со вторым нажатием, STL — только ссылки защищённой выдачи, лист задания
 * Bambu, сообщения и ответы клиента, перевыпуск гостевой ссылки; клиенты и гостевые контакты.
 * Заказ — штатная заявка W-12 (два куба 10 мм, PLA, плата 5 EUR); подтверждение 60 + 55 + 5 EUR и доставка 30 EUR = 150 EUR.
 */
class AdminOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        Storage::fake('local');
        Livewire::flushState();
        app()->setLocale('en');
        $this->client = User::factory()->create(['name' => 'Ona Kazlauskienė']);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    /** @return array{0: Order, 1: ?string} заказ и открытый гостевой токен (у заказа клиента — null) */
    private function submitted(bool $guest = false, string $delivery = 'ship'): array
    {
        $cube = file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));
        $part = fn (string $name, int $quantity): array => [
            'file' => UploadedFile::fake()->createWithContent($name, $cube),
            'unit' => 'mm',
            'scale' => 100,
            'quantity' => $quantity,
        ];
        $result = app(OrderSubmission::class)->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => 'en',
            'contact' => ['name' => 'Ona', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => 'UAB Detalė', 'company_code' => null, 'vat_code' => null],
            'delivery' => $delivery,
            'address' => $delivery === 'ship' ? ['country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gatvė 1', 'postcode' => '01100'] : null,
            'material' => 'PLA',
            'infill' => 100,
            'preferences' => ['color' => 'blue', 'quality' => 'fine', 'layer' => '0.12', 'finish' => 'smooth', 'term' => 'priority'],
            'parts' => [$part('bracket.stl', 1), $part('cover.stl', 2)],
        ], $guest ? null : $this->client);

        return [$result->order, $result->guestToken];
    }

    private function order(bool $guest = false, string $delivery = 'ship'): Order
    {
        return $this->submitted($guest, $delivery)[0];
    }

    /** @return list<int> id строк без delivery: STL, STL, fee */
    private function lineIds(Order $order): array
    {
        return $order->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
            ->modelKeys();
    }

    private function card(Order $order, ?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->admin)->test(Card::class, ['orderId' => $order->id]);
    }

    /** Карточка с ценами 60 / 55 / 5 EUR и доставкой 30 EUR, подтверждёнными через форму карточки. */
    private function confirmedCard(Order $order): Testable
    {
        [$a, $b, $fee] = $this->lineIds($order);

        return $this->card($order)
            ->set("prices.$a", '60')->set("prices.$b", '55.00')->set("prices.$fee", '5')->set('delivery', '30')
            ->call('confirmPrice')
            ->assertHasNoErrors();
    }

    private function state(Order $order): ProductionState
    {
        return ProductionState::where('order_id', $order->id)->sole();
    }

    public function test_tabs_are_admin_only_with_prototype_headings_and_without_demo_texts(): void
    {
        $order = $this->order();
        foreach ([
            '/en/admin/orders' => ['Orders &amp; production review', 'Search by order number', 'Order search', 'Manufacturing', 'Manage', 'Technical review'],
            '/lt/administravimas/orders' => ['Užsakymai ir gamybos patikra', 'Ieškoti pagal užsakymo numerį', 'Užsakymų paieška', 'Gamyba', 'Valdyti', 'Techninė patikra'],
            '/en/admin/customers' => ['Customers', 'Guest contacts', 'Ona Kazlauskienė'],
            '/lt/administravimas/customers' => ['Klientai', 'Svečių kontaktai'],
            '/en/admin' => ['Your working day, at a glance.', 'Awaiting technical review', 'All orders ↗'],
            '/lt/administravimas' => ['Darbo diena viename vaizde.', 'Laukia techninės patikros', 'Visi užsakymai ↗'],
            '/en/admin/orders?order='.$order->id => ['Files &amp; production settings', 'A team member must check the file', 'Bambu Studio staff handoff'],
        ] as $path => $texts) {
            $this->get($path)->assertNotFound();
            $this->actingAs($this->client)->get($path)->assertNotFound();
            $html = $this->actingAs($this->admin)->get($path)->assertOk()->getContent();
            foreach ($texts as $text) {
                $this->assertStringContainsString($text, $html, $path);
            }
            foreach (['DEMO-', 'Demo', 'demo', 'CSV', 'simulated', 'imituojama', 'example.invalid', 'Customer view', 'label'] as $phrase) {
                $this->assertStringNotContainsString('>'.$phrase, $html, $path);
                $this->assertStringNotContainsString($phrase.' ', strip_tags($html), $path);
            }
            auth()->logout();
        }
        $this->actingAs($this->admin)->get('/en/admin/orders?order=999999')->assertNotFound();
    }

    public function test_components_and_their_actions_are_404_for_customer(): void
    {
        $order = $this->order();
        Livewire::actingAs($this->client)->test(Overview::class)->assertStatus(404);
        Livewire::actingAs($this->client)->test(Index::class)->assertStatus(404);
        Livewire::actingAs($this->client)->test(Customers::class)->assertStatus(404);
        $this->card($order, $this->client)->assertStatus(404);
        $this->assertSame(ProductionStage::Review, $this->state($order)->stage);
    }

    public function test_overview_counts_orders_by_stage_payment_and_recent_forms_and_warns_about_failed_tasks(): void
    {
        $review = $this->order();
        $confirmed = $this->order();
        $production = $this->order();
        $flow = app(OrderWorkflow::class);
        foreach ([$confirmed, $production] as $order) {
            $flow->confirmPrice($this->admin, $order, array_fill_keys($this->lineIds($order), 1000), null);
        }
        $flow->requestPayment($this->admin, $confirmed, 1000);
        $flow->requestPayment($this->admin, $confirmed, 1000);
        $flow->startProduction($this->admin, $production, debtConfirmed: true);
        ContactRequest::forceCreate(['locale' => 'en', 'name' => 'A', 'email' => 'a@example.test', 'phone' => '1', 'service' => 'modelling', 'description' => 'x', 'attachments' => []]);
        $old = ContactRequest::forceCreate(['locale' => 'en', 'name' => 'B', 'email' => 'b@example.test', 'phone' => '1', 'service' => 'modelling', 'description' => 'x', 'attachments' => []]);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();

        $metrics = fn (Testable $test): array => $test->viewData('metrics');
        $overview = Livewire::actingAs($this->admin)->test(Overview::class);
        $this->assertSame(['review' => 1, 'payment' => 1, 'production' => 1, 'enquiries' => 1, 'registrations' => 0], $metrics($overview));
        $overview->assertDontSeeHtml('id="overview-failed"')
            ->assertSeeInOrder([(string) OrderMeta::where('order_id', $production->id)->value('number'), (string) OrderMeta::where('order_id', $review->id)->value('number')]);

        IntegrationEvent::whereKey(IntegrationEvent::where('type', 'order.submitted')->value('id'))->update(['mail_status' => 'failed']);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'sheets', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        Livewire::actingAs($this->admin)->test(Overview::class)
            ->assertSeeHtml('id="overview-failed"')->assertSee('Failed background tasks: 2.');

        // Ссылка из письма о заявке (W-12) ведёт в карточку заказа.
        $this->actingAs($this->admin)->get('/lt/administravimas?order='.$review->id)
            ->assertRedirect(route('lt.admin.orders', ['order' => $review->id]));
    }

    public function test_order_list_searches_by_number_and_filters_by_stage(): void
    {
        $first = $this->order();
        $second = $this->order();
        app(OrderWorkflow::class)->confirmPrice($this->admin, $second, array_fill_keys($this->lineIds($second), 1000), null);
        $number = fn (Order $order): int => OrderMeta::where('order_id', $order->id)->value('number');
        $listed = fn (Testable $test): array => $test->viewData('orders')->pluck('number')->map(fn ($n): int => (int) $n)->all();

        $list = Livewire::actingAs($this->admin)->test(Index::class);
        $this->assertSame([$number($second), $number($first)], $listed($list));
        $this->assertSame([$number($first)], $listed($list->set('search', '#'.$number($first))));
        $this->assertSame([], $listed($list->set('search', '999999')));
        $this->assertSame([$number($second)], $listed($list->set('search', '')->set('stage', 'confirmed')));
        $this->assertSame([$number($first)], $listed($list->set('stage', 'review')));
        $list->assertSeeHtml('href="'.route('en.admin.orders', ['order' => $first->id]).'#order-card"');
    }

    public function test_price_is_confirmed_by_lines_with_delivery_through_the_workflow(): void
    {
        $order = $this->order();
        $this->confirmedCard($order)->assertSee('Price confirmed.')->assertSee('Approved for production')
            ->assertSee('€150.00');

        $state = $this->state($order);
        $this->assertSame(ProductionStage::Confirmed, $state->stage);
        $this->assertSame(1, $state->revision);
        $this->assertSame(15000, Order::find($order->id)->total->value);
        $this->assertSame(1, OrderCharge::where('order_id', $order->id)->where('kind', OrderCharge::DELIVERY)->count());
        $this->assertSame(1, IntegrationEvent::where('type', 'order.price_confirmed')->count());
    }

    public function test_workflow_rejections_are_shown_in_the_card_without_changes(): void
    {
        $order = $this->order();
        [$a, $b, $fee] = $this->lineIds($order);
        // StlPart с ценой 0 — правило OrderWorkflow «Invalid line price».
        $this->card($order)->set("prices.$a", '0')->set("prices.$b", '5')->set("prices.$fee", '5')->call('confirmPrice')
            ->assertHasErrors('workflow')->assertSee(__('orders.workflow.line_price'));
        $this->card($order)->set("prices.$a", 'abc')->call('confirmPrice')->assertHasErrors("prices.$a");
        $this->assertSame(ProductionStage::Review, $this->state($order)->stage);

        // Запрос больше долга — отказ правила, запроса нет.
        $card = $this->confirmedCard($order)->set('amount', '150.01')->call('requestPayment')
            ->assertSee(__('orders.workflow.request_too_large'));
        $this->assertSame(0, PaymentRequest::count());
        // Цена ниже оплаченного (100 < 120 EUR) — отказ правила (после подтверждения изменения).
        LunarCapture::create($order, 12000);
        $card->set("prices.$a", '10')->set('reason', 'Smaller part')->set('agreement', 'agreed by phone')
            ->call('changePrice')->call('changePrice')->assertSee(__('orders.workflow.below_paid'));
        $this->assertSame(15000, Order::find($order->id)->total->value);
    }

    public function test_price_change_asks_for_confirmation_and_records_reason_and_agreement(): void
    {
        $order = $this->order();
        [$a] = $this->lineIds($order);
        $card = $this->confirmedCard($order)->set("prices.$a", '70')->set('reason', 'Thicker wall')->set('agreement', 'agreed by phone 18.10')
            ->call('changePrice')
            ->assertSet('confirming', 'change')->assertSeeHtml('id="order-confirm"')->assertSee('Change the confirmed price?');
        $this->assertSame(1, PriceRevision::where('order_id', $order->id)->count());
        $this->assertSame(15000, Order::find($order->id)->total->value);

        $card->call('back')->assertSet('confirming', null)->assertDontSeeHtml('id="order-confirm"');
        $card->call('changePrice')->call('changePrice')->assertHasNoErrors()->assertSee('Price changed.')->assertSet('confirming', null);
        $revision = PriceRevision::where('order_id', $order->id)->where('revision', 2)->sole();
        $this->assertSame(['Thicker wall', 'agreed by phone 18.10'], [$revision->reason, $revision->agreement]);
        $this->assertSame(16000, Order::find($order->id)->total->value);
        $card->assertSee('Price revision 2');
    }

    public function test_payment_request_is_created_and_cancelled_without_intent(): void
    {
        $order = $this->order();
        $card = $this->confirmedCard($order)->set('amount', '50')->call('requestPayment')->assertHasNoErrors()->assertSee('Payment request created.');
        $request = PaymentRequest::where('order_id', $order->id)->sole();
        $this->assertSame([5000, PaymentRequest::PENDING, null], [$request->amount, $request->state, $request->stripe_intent_id]);
        $card->assertSee('Open')->assertSee('Cancel request');

        $card->call('cancelRequest', $request->id)->assertSee('Payment request cancelled.');
        $this->assertSame(PaymentRequest::VOID, $request->fresh()->state);

        // Запрос с intent (W-16) здесь не отменяется: кнопки нет, вызов отклоняет правило.
        $intent = PaymentRequest::create(['public_id' => (string) Str::uuid(), 'order_id' => $order->id, 'amount' => 1000, 'currency' => 'EUR', 'state' => PaymentRequest::PENDING, 'stripe_intent_id' => 'pi_test_not_real']);
        $card->call('cancelRequest', $intent->id)->assertSee(__('orders.workflow.request_not_cancellable'));
        $this->assertSame(PaymentRequest::PENDING, $intent->fresh()->state);
        // Запрос другого заказа — 404.
        $other = PaymentRequest::create(['public_id' => (string) Str::uuid(), 'order_id' => $this->order()->id, 'amount' => 1000, 'currency' => 'EUR', 'state' => PaymentRequest::PENDING]);
        $card->call('cancelRequest', $other->id)->assertStatus(404);
        $this->assertSame(PaymentRequest::PENDING, $other->fresh()->state);
    }

    public function test_start_production_with_debt_asks_for_confirmation_and_without_debt_does_not(): void
    {
        $order = $this->order();
        $card = $this->confirmedCard($order)->call('startProduction')
            ->assertSet('confirming', 'start')->assertSee('The order is not fully paid (outstanding €150.00)');
        $this->assertSame(ProductionStage::Confirmed, $this->state($order)->stage);
        $card->call('startProduction')->assertSee('Stage changed.')->assertSee('Next stage: Quality inspection');
        $this->assertSame(ProductionStage::InProduction, $this->state($order)->stage);

        $paid = $this->order();
        $paidCard = $this->confirmedCard($paid);
        LunarCapture::create($paid, 15000);
        $paidCard->call('startProduction')->assertSet('confirming', null)->assertSee('Stage changed.');
        $this->assertSame(ProductionStage::InProduction, $this->state($paid)->stage);
    }

    public function test_stages_tracking_and_pickup_go_through_the_workflow(): void
    {
        $order = $this->order();
        $card = $this->confirmedCard($order)->call('startProduction')->call('startProduction')
            ->call('advance', 'quality_check')->call('advance', 'ready')
            ->call('advance', 'shipped')->assertSee(__('orders.workflow.tracking_required'));
        $this->assertSame(ProductionStage::Ready, $this->state($order)->stage);
        $card->call('advance', 'completed')->assertSee(__('orders.workflow.stage_invalid'));

        $card->set('carrier', 'DPD')->set('trackingNumber', 'LT123456')->call('setTracking')->assertSee('Tracking saved.')
            ->call('advance', 'shipped')->assertHasNoErrors()->call('advance', 'completed');
        $state = $this->state($order);
        $this->assertSame([ProductionStage::Completed, 'DPD', 'LT123456'], [$state->stage, $state->carrier, $state->tracking_number]);
        $card->assertDontSeeHtml('id="order-cancel-form"')->assertSee('LT123456');

        // Самовывоз: формы трека нет, ready → completed.
        $pickup = $this->order(delivery: 'pickup');
        [$a, $b, $fee] = $this->lineIds($pickup);
        $pickupCard = $this->card($pickup)->set("prices.$a", '60')->set("prices.$b", '55')->set("prices.$fee", '5')->call('confirmPrice')
            ->assertDontSeeHtml('id="order-tracking-form"')
            ->call('startProduction')->call('startProduction')->call('advance', 'quality_check')->call('advance', 'ready')
            ->assertSee('Next stage: Completed')->call('advance', 'completed');
        $this->assertSame(ProductionStage::Completed, $this->state($pickup)->stage);
        $pickupCard->assertHasNoErrors();
    }

    public function test_cancel_asks_for_confirmation_requires_reason_and_voids_open_requests(): void
    {
        $order = $this->order();
        $card = $this->confirmedCard($order)->set('amount', '20')->call('requestPayment');
        $card->call('cancelOrder')->assertSet('confirming', 'cancel')->assertSee('Cancel the order?');
        $this->assertSame(ProductionStage::Confirmed, $this->state($order)->stage);
        $card->call('cancelOrder')->assertSee(__('orders.workflow.reason_required'));
        $this->assertSame(ProductionStage::Confirmed, $this->state($order)->stage);

        $card->set('cancelReason', 'Customer withdrew')->call('cancelOrder')->assertSee('Order cancelled.')
            ->assertSee('Cancelled: Customer withdrew')->assertDontSeeHtml('id="order-request-form"');
        $this->assertSame(ProductionStage::Cancelled, $this->state($order)->stage);
        $this->assertSame(PaymentRequest::VOID, PaymentRequest::where('order_id', $order->id)->sole()->state);
    }

    public function test_message_to_customer_and_customer_replies_are_shown_in_the_card(): void
    {
        $order = $this->order();
        $card = $this->card($order)->call('postMessage')->assertSee(__('orders.workflow.message_invalid'));
        $this->assertSame(0, OrderMessage::count());

        $card->set('message', "The wall is too thin.\nWe propose 2 mm.")->call('postMessage')->assertSee('Message saved.')->assertSet('message', '');
        $this->assertSame(['admin', "The wall is too thin.\nWe propose 2 mm."], [OrderMessage::sole()->kind, OrderMessage::sole()->body]);

        $flow = app(OrderWorkflow::class);
        foreach (OrderMessage::REPLIES as $kind) {
            $flow->recordCustomerReply($order, $kind, $this->client);
        }
        $this->card($order)->assertSeeInOrder([
            'AIQME:', 'The wall is too thin.',
            'Customer response:', 'Approve the proposed revision',
            'Customer response:', 'Request clarification',
            'Customer response:', 'Request cancellation',
        ])->assertSee('Message to the customer');
        app()->setLocale('lt');
        $this->card($order)->assertSee('Kliento atsakymas:')->assertSee('Prašyti paaiškinimo');
    }

    public function test_stl_links_go_only_through_the_protected_download(): void
    {
        $order = $this->order();
        $files = StlFile::where('order_id', $order->id)->orderBy('id')->get();
        $card = $this->card($order);
        foreach ($files as $file) {
            $card->assertSeeHtml('href="'.route('stl.download', $file).'"')->assertDontSee($file->path);
        }
        $this->actingAs($this->admin)->get(route('stl.download', $files[0]))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=bracket.stl');
        auth()->logout();
        $this->get(route('stl.download', $files[0]))->assertNotFound();
    }

    public function test_bambu_job_sheet_shows_parameters_preferences_and_protected_links_without_storage_paths(): void
    {
        $order = $this->order();
        $files = StlFile::where('order_id', $order->id)->orderBy('id')->get();
        $card = $this->card($order)->assertDontSeeHtml('id="bambu-job"')->call('toggleJob')->assertSeeHtml('id="bambu-job"');
        $html = $card->html();
        $job = substr($html, strpos($html, 'id="bambu-job"'));
        $job = substr($job, 0, strpos($job, '</div>', strpos($job, 'Automatic print launch')) ?: null);

        foreach (['Staff job handoff', OrderMeta::where('order_id', $order->id)->value('number').' · Bambu Studio', 'bracket.stl', 'cover.stl',
            'PLA', 'Infill 100%', 'Colour: Blue', 'Quality level: Finer surface', 'Layer height: 0.12 mm', 'Post-processing: Surface smoothing',
            'Requested schedule: Priority', 'Units: mm', 'Scale, % 100', 'Automatic print launch is not connected.'] as $text) {
            $this->assertStringContainsString(e($text), $job, $text);
        }
        foreach ($files as $file) {
            $this->assertStringContainsString('href="'.route('stl.download', $file).'"', $job);
            $this->assertStringNotContainsString($file->path, $html);
        }
        $this->assertStringNotContainsString('storage', $job);
        $this->assertDoesNotMatchRegularExpression('#'.StlFile::DIRECTORY.'/[0-9a-f-]{36}#', $html);
        // Передачи на принтер нет: в листе только ссылки на файлы, без кнопок и форм отправки.
        $this->assertStringNotContainsString('<form', $job);
        $this->assertStringNotContainsString('wire:click', $job);
    }

    public function test_guest_link_reissue_shows_the_new_link_once_and_the_old_link_stops_working(): void
    {
        [$order, $oldToken] = $this->submitted(guest: true);
        $number = OrderMeta::where('order_id', $order->id)->value('number');
        $this->get(route('en.order.guest', [$number, $oldToken]))->assertOk();

        $card = $this->card($order)->assertSee('Issue a new guest link')->assertDontSeeHtml('id="guest-link"')
            ->call('reissueGuestLink')->assertSee('shown only once')->assertSeeHtml('id="guest-link"');
        $link = $card->get('guestLink');
        $this->assertStringStartsWith(substr(route('en.order.guest', [$number, 'x']), 0, -1), $link);
        $card->assertSeeHtml('href="'.$link.'"');

        $this->get(route('en.order.guest', [$number, $oldToken]))->assertNotFound();
        $this->get($link)->assertOk();
        $this->assertStringNotContainsString(basename($link), json_encode(DB::table('activity_log')->get()));

        // Следующий запрос карточки и новая страница ссылку больше не показывают.
        $card->call('toggleJob')->assertSet('guestLink', null)->assertDontSee(basename($link));
        $this->card($order)->assertDontSee(basename($link));
        $this->get($link)->assertOk();
    }

    public function test_customer_order_has_no_guest_link(): void
    {
        $order = $this->order();
        $this->card($order)->assertDontSee('Issue a new guest link')
            ->call('reissueGuestLink')->assertSee(__('orders.workflow.not_guest'))->assertSet('guestLink', null)
            ->assertSeeHtml('href="'.route('en.admin.customers', ['customer' => $this->client->id]).'"');
    }

    public function test_customers_list_card_and_guest_contacts(): void
    {
        $this->order();
        $this->order();
        [$guestOrder] = $this->submitted(guest: true);
        $this->client->profile()->create(['company' => 'UAB Detalė', 'vat_code' => 'LT100000000']);
        $other = User::factory()->create(['name' => 'Petras Jonaitis']);

        $list = Livewire::actingAs($this->admin)->test(Customers::class);
        $rows = $list->viewData('customers')->mapWithKeys(fn (User $user): array => [$user->name => (int) $user->orders])->all();
        $this->assertSame(['Petras Jonaitis' => 0, 'Ona Kazlauskienė' => 2], $rows);
        $list->assertSeeInOrder(['Guest contacts', (string) OrderMeta::where('order_id', $guestOrder->id)->value('number'), 'Ona', 'ona@example.test', '+370 600 00000', 'UAB Detalė'])
            ->assertDontSeeHtml('id="customer-card"');

        $card = Livewire::actingAs($this->admin)->test(Customers::class, ['customerId' => $this->client->id])->assertSeeHtml('id="customer-card"')
            ->assertSee('Addresses &amp; company details', false)->assertSee('LT100000000');
        $this->assertCount(2, $card->viewData('customerOrders'));
        $this->assertCount(0, Livewire::actingAs($this->admin)->test(Customers::class, ['customerId' => $other->id])->viewData('customerOrders'));

        $this->actingAs($this->admin)->get('/en/admin/customers?customer='.$this->admin->id)->assertNotFound();
        $this->actingAs($this->admin)->get('/en/admin/customers?customer='.$this->client->id)->assertOk()->assertSee('LT100000000');
    }
}
