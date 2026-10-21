<?php

namespace Tests\Feature;

use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Orders\Models\OrderMessage;
use App\Domain\Orders\Models\PriceRevision;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderSubmission;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentRequest;
use App\Models\OrderCharge;
use App\Models\StlPart;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\LunarCapture;
use Tests\TestCase;

/**
 * Правила заказа (W-13, §3.2–§3.4, §9.2 «Цена и производство», платёжные запросы без intent): тесты эксперимента
 * (подтверждение, ревизии, производство без оплаты, блокировка после старта, отмена без возврата) и исправления плана.
 * «Оплачено» — только тестовая фабрика capture (LunarCapture). Заказ — штатная заявка W-12: два STL, плата за заказ 5 EUR;
 * подтверждение по умолчанию 60 + 55 + 5 EUR и доставка 30 EUR = 150 EUR (сценарий 2 §9.3).
 */
class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private OrderWorkflow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        Storage::fake('local');
        $this->client = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        $this->flow = app(OrderWorkflow::class);
    }

    /** Заявка через OrderSubmission (W-12): два куба 10 мм, PLA; гость — $user = null. */
    private function submitted(?User $user = null, string $delivery = 'ship', bool $guest = false): Order
    {
        $cube = file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));
        $part = fn (string $name, int $quantity): array => [
            'file' => UploadedFile::fake()->createWithContent($name, $cube),
            'unit' => 'mm',
            'scale' => 100,
            'quantity' => $quantity,
        ];

        return app(OrderSubmission::class)->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => 'en',
            'contact' => ['name' => 'Ona', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => null, 'company_code' => null, 'vat_code' => null],
            'delivery' => $delivery,
            'address' => $delivery === 'ship' ? ['country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gatvė 1', 'postcode' => '01100'] : null,
            'material' => 'PLA',
            'infill' => 100,
            'preferences' => ['color' => 'black', 'quality' => 'standard', 'layer' => '0.2', 'finish' => 'none', 'term' => 'standard'],
            'parts' => [$part('bracket.stl', 1), $part('cover.stl', 2)],
        ], $guest ? null : ($user ?? $this->client))->order;
    }

    /**
     * Цены по id строк заказа без delivery, в порядке строк: STL, STL, fee.
     *
     * @return array<int, int>
     */
    private function prices(Order $order, int ...$cents): array
    {
        $ids = $order->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
            ->modelKeys();

        return array_combine($ids, $cents);
    }

    private function confirmed(string $delivery = 'ship'): Order
    {
        $order = $this->submitted(delivery: $delivery);

        return $this->flow->confirmPrice($this->admin, $order, $this->prices($order, 6000, 5500, 500), $delivery === 'ship' ? 3000 : null);
    }

    private function state(Order $order): ProductionState
    {
        return ProductionState::where('order_id', $order->id)->sole();
    }

    private function total(Order $order): int
    {
        return Order::findOrFail($order->id)->total->value;
    }

    /** Действие отклонено правилом с понятным сообщением (lang orders.workflow.<code>). */
    private function assertRejected(string $code, Closure $action): void
    {
        try {
            $action();
            $this->fail("Expected rejection {$code}");
        } catch (ValidationException $exception) {
            $this->assertSame([__('orders.workflow.'.$code)], $exception->errors()['workflow']);
            $this->assertStringNotContainsString('orders.workflow', __('orders.workflow.'.$code));
        }
    }

    private function request(Order $order, string $state, int $amount = 1000, ?string $intent = null): PaymentRequest
    {
        return PaymentRequest::create(['public_id' => (string) Str::uuid(), 'order_id' => $order->id, 'amount' => $amount, 'currency' => 'EUR', 'state' => $state, 'stripe_intent_id' => $intent]);
    }

    public function test_submission_starts_in_review_without_payment(): void
    {
        $order = $this->submitted();
        $state = $this->state($order);
        $this->assertSame(ProductionStage::Review, $state->stage);
        $this->assertNull($state->confirmed_at);
        $this->assertSame(0, $state->revision);
        $this->assertSame(0, $order->transactions()->count());
        $this->assertSame('unpaid', $this->flow->balance($order)['payment_state']);
    }

    public function test_confirmation_prices_every_line_including_fee_and_adds_one_delivery_line(): void
    {
        $submitted = $this->submitted();
        $estimates = $submitted->lines()->orderBy('id')->get()->mapWithKeys(fn (OrderLine $line): array => [$line->id => $line->unit_price->value])->all();
        $order = $this->flow->confirmPrice($this->admin, $submitted, $this->prices($submitted, 6000, 5500, 500), 3000);

        $this->assertSame(15000, $order->total->value);
        $this->assertSame(15000, $order->sub_total->value);
        $lines = $order->lines()->orderBy('id')->get();
        $this->assertCount(4, $lines);
        $this->assertSame([6000, 5500, 500, 3000], $lines->map(fn (OrderLine $line): int => $line->total->value)->all());
        $delivery = $lines->last()->purchasable;
        $this->assertInstanceOf(OrderCharge::class, $delivery);
        $this->assertSame(['delivery', 3000, $order->id], [$delivery->kind, $delivery->amount, $delivery->order_id]);
        $this->assertSame(500, $lines[2]->purchasable->amount, 'fee получила подтверждённую цену');
        $this->assertInstanceOf(StlPart::class, $lines[0]->purchasable);
        $this->assertSame($estimates[$lines[0]->id], $lines[0]->purchasable->estimate, 'серверная оценка сохраняется');

        $state = $this->state($order);
        $this->assertSame(ProductionStage::Confirmed, $state->stage);
        $this->assertNotNull($state->confirmed_at);
        $this->assertSame(1, $state->revision);
        $revision = PriceRevision::sole();
        $this->assertSame([1, $this->admin->id, null, null], [$revision->revision, $revision->actor_id, $revision->reason, $revision->agreement]);
        $this->assertEquals($estimates, $revision->before);
        $this->assertEquals(array_combine($lines->modelKeys(), [6000, 5500, 500, 3000]), $revision->after);
        $this->assertSame(['total' => 15000, 'paid' => 0, 'due' => 15000, 'payment_state' => 'unpaid'], $this->flow->balance($order));
    }

    public function test_fee_can_be_confirmed_at_zero_but_stl_line_cannot(): void
    {
        $order = $this->submitted(delivery: 'pickup');
        $this->assertRejected('line_price', fn () => $this->flow->confirmPrice($this->admin, $order, $this->prices($order, 0, 5500, 500), null));
        $this->assertRejected('line_price', fn () => $this->flow->confirmPrice($this->admin, $order, $this->prices($order, -1, 5500, 500), null));
        $this->assertSame(ProductionStage::Review, $this->state($order)->stage);

        $order = $this->flow->confirmPrice($this->admin, $order, $this->prices($order, 6000, 5500, 0), null);
        $this->assertSame(11500, $order->total->value);
        $this->assertCount(3, $order->lines, 'без доставки строка delivery не создаётся');
    }

    public function test_every_line_needs_a_price_and_confirmation_happens_once(): void
    {
        $order = $this->submitted();
        $prices = $this->prices($order, 6000, 5500, 500);
        $this->assertRejected('every_line', fn () => $this->flow->confirmPrice($this->admin, $order, array_slice($prices, 0, 2, true), 3000));
        $this->assertRejected('every_line', fn () => $this->flow->confirmPrice($this->admin, $order, $prices + [999999 => 100], 3000));
        $this->assertDatabaseCount('price_revisions', 0);
        $this->assertSame(ProductionStage::Review, $this->state($order)->stage);

        $this->flow->confirmPrice($this->admin, $order, $prices, 3000);
        $this->assertRejected('already_confirmed', fn () => $this->flow->confirmPrice($this->admin, $order, $prices, 3000));
    }

    public function test_price_change_requires_confirmed_price_reason_and_agreement(): void
    {
        $order = $this->submitted();
        $this->assertRejected('not_confirmed', fn () => $this->flow->changePrice($this->admin, $order, $this->prices($order, 5000, 5000, 500), null, 'Review', 'phone'));

        $order = $this->confirmed();
        $prices = $this->prices($order, 5000, 5000, 500);
        $this->assertRejected('reason_required', fn () => $this->flow->changePrice($this->admin, $order, $prices, 3000, 'Thinner walls', ' '));
        $this->assertRejected('reason_required', fn () => $this->flow->changePrice($this->admin, $order, $prices, 3000, '', 'agreed by phone 18.10'));
        $this->assertSame(15000, $this->total($order));
        $this->assertSame(1, PriceRevision::count());
    }

    public function test_revision_of_order_with_delivery_changes_its_delivery_line_instead_of_adding_another(): void
    {
        $order = $this->confirmed();
        $order = $this->flow->changePrice($this->admin, $order, $this->prices($order, 7000, 5500, 500), 2000, 'Thicker walls', 'agreed by phone 18.10');

        $deliveryLines = $order->lines->filter(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY);
        $this->assertCount(1, $deliveryLines);
        $this->assertSame(2000, $deliveryLines->first()->unit_price->value);
        $this->assertSame(2000, $deliveryLines->first()->purchasable->amount);
        $this->assertSame(1, OrderCharge::where('kind', OrderCharge::DELIVERY)->count());
        $this->assertSame(15000, $order->total->value);
        $this->assertCount(4, $order->lines);

        $this->assertRejected('delivery_required', fn () => $this->flow->changePrice($this->admin, $order, $this->prices($order, 7000, 5500, 500), null, 'No delivery', 'agreed'));
        $this->assertCount(4, $order->fresh()->lines);
        $revision = PriceRevision::where('revision', 2)->sole();
        $this->assertSame(['Thicker walls', 'agreed by phone 18.10', $this->admin->id], [$revision->reason, $revision->agreement, $revision->actor_id]);
        $this->assertSame(3000, $revision->before[$deliveryLines->first()->id]);
        $this->assertSame(2000, $revision->after[$deliveryLines->first()->id]);
    }

    public function test_revision_can_add_delivery_to_order_confirmed_without_it(): void
    {
        $order = $this->confirmed('pickup');
        $this->assertCount(3, $order->lines);
        $order = $this->flow->changePrice($this->admin, $order, $this->prices($order, 6000, 5500, 500), 1500, 'Customer asked for delivery', 'agreed by email');
        $this->assertCount(4, $order->lines);
        $this->assertSame(13500, $order->total->value);
    }

    public function test_agreed_revision_after_advance_keeps_history_and_paid_amount(): void
    {
        $order = $this->confirmed();
        LunarCapture::create($order, 4000);
        $order = $this->flow->changePrice($this->admin, $order, $this->prices($order, 7000, 10000, 500), 3000, 'Agreed revised specification', 'customer reply «agree»');
        $this->assertSame(20500, $order->total->value);
        $this->assertSame(['total' => 20500, 'paid' => 4000, 'due' => 16500, 'payment_state' => 'partial'], $this->flow->balance($order));
        $this->assertDatabaseCount('price_revisions', 2);
        $this->assertSame(2, $this->state($order)->revision);

        $order = $this->flow->changePrice($this->admin, $order, $this->prices($order, 3000, 2000, 500), 0, 'Agreed reduction', 'agreed by phone');
        $this->assertSame(['total' => 5500, 'paid' => 4000, 'due' => 1500, 'payment_state' => 'partial'], $this->flow->balance($order));
        $this->assertSame(3, $this->state($order)->revision);
    }

    public function test_revision_below_paid_amount_is_rejected(): void
    {
        $order = $this->confirmed();
        LunarCapture::create($order, 14000);
        $this->assertRejected('below_paid', fn () => $this->flow->changePrice($this->admin, $order, $this->prices($order, 5000, 5000, 500), 3000, 'Reduction', 'agreed'));
        $this->assertSame(15000, $this->total($order));
        $this->assertSame(1, PriceRevision::count());
    }

    public function test_revision_leaving_remainder_of_1_to_49_cents_is_rejected(): void
    {
        $order = $this->confirmed();
        LunarCapture::create($order, 4000);
        foreach ([1, 30, 49] as $rest) {
            $this->assertRejected('rest_too_small', fn () => $this->flow->changePrice($this->admin, $order, $this->prices($order, 2000, 1000 + $rest, 500), 500, 'Reduction', 'agreed'));
        }
        $this->assertSame(15000, $this->total($order));

        $this->flow->changePrice($this->admin, $order, $this->prices($order, 2000, 1050, 500), 500, 'Reduction', 'agreed');
        $this->assertSame(50, $this->flow->balance($order->fresh())['due']);
        $this->flow->changePrice($this->admin, $order, $this->prices($order, 2000, 1000, 500), 500, 'Paid exactly', 'agreed');
        $this->assertSame(['total' => 4000, 'paid' => 4000, 'due' => 0, 'payment_state' => 'paid'], $this->flow->balance($order->fresh()));
    }

    public function test_open_requests_in_creating_pending_and_cancelling_block_price_change(): void
    {
        $order = $this->confirmed();
        $prices = $this->prices($order, 5000, 5000, 500);
        foreach (PaymentRequest::OPEN as $state) {
            $request = $this->request($order, $state, intent: $state === PaymentRequest::PENDING ? null : 'pi_test_not_real_'.$state);
            $this->assertRejected('open_request', fn () => $this->flow->changePrice($this->admin, $order, $prices, 3000, 'Reduction', 'agreed'));
            $request->update(['state' => PaymentRequest::VOID]);
        }
        $this->request($order, PaymentRequest::PAID);
        $this->flow->changePrice($this->admin, $order, $prices, 3000, 'Reduction', 'agreed');
        $this->assertSame(13500, $this->total($order));
    }

    public function test_price_change_after_production_start_is_rejected(): void
    {
        $order = $this->confirmed();
        $this->flow->startProduction($this->admin, $order, debtConfirmed: true);
        $this->assertRejected('price_locked', fn () => $this->flow->changePrice($this->admin, $order, $this->prices($order, 5000, 5000, 500), 3000, 'Change', 'agreed'));
        $this->assertSame(15000, $this->total($order));
    }

    public function test_production_requires_confirmed_price(): void
    {
        $order = $this->submitted();
        $this->assertRejected('not_confirmed', fn () => $this->flow->startProduction($this->admin, $order, debtConfirmed: true));
        $this->assertSame(ProductionStage::Review, $this->state($order)->stage);
    }

    public function test_production_can_start_without_payment_after_debt_confirmation_and_payment_does_not_start_it(): void
    {
        $order = $this->confirmed();
        LunarCapture::create($order, 4000);
        $this->assertSame(ProductionStage::Confirmed, $this->state($order)->stage, 'оплата не меняет стадию');
        $this->assertRejected('debt_unconfirmed', fn () => $this->flow->startProduction($this->admin, $order));
        $this->assertNull($this->state($order)->started_at);

        $this->flow->startProduction($this->admin, $order, debtConfirmed: true);
        $state = $this->state($order);
        $this->assertSame(ProductionStage::InProduction, $state->stage);
        $this->assertNotNull($state->started_at);
        $this->assertSame(['total' => 15000, 'paid' => 4000, 'due' => 11000, 'payment_state' => 'partial'], $this->flow->balance($order));

        $unpaid = $this->confirmed();
        $this->flow->startProduction($this->admin, $unpaid, debtConfirmed: true);
        $this->assertSame('unpaid', $this->flow->balance($unpaid)['payment_state']);

        $paid = $this->confirmed();
        LunarCapture::create($paid, 15000);
        $this->flow->startProduction($this->admin, $paid);
        $this->assertSame(ProductionStage::InProduction, $this->state($paid)->stage, 'без долга подтверждение не нужно');
    }

    public function test_transitions_go_forward_one_stage_and_shipping_needs_tracking(): void
    {
        $order = $this->confirmed();
        $this->assertRejected('stage_invalid', fn () => $this->flow->advance($this->admin, $order, ProductionStage::QualityCheck));
        $this->flow->startProduction($this->admin, $order, debtConfirmed: true);
        $this->assertRejected('stage_invalid', fn () => $this->flow->startProduction($this->admin, $order, debtConfirmed: true));
        $this->assertRejected('stage_invalid', fn () => $this->flow->advance($this->admin, $order, ProductionStage::Ready));
        $this->assertRejected('stage_invalid', fn () => $this->flow->advance($this->admin, $order, ProductionStage::Confirmed));
        $this->flow->advance($this->admin, $order, ProductionStage::QualityCheck);
        $this->assertRejected('stage_invalid', fn () => $this->flow->advance($this->admin, $order, ProductionStage::InProduction));
        $this->flow->advance($this->admin, $order, ProductionStage::Ready);
        $this->assertRejected('stage_invalid', fn () => $this->flow->advance($this->admin, $order, ProductionStage::Completed));
        $this->assertRejected('tracking_required', fn () => $this->flow->advance($this->admin, $order, ProductionStage::Shipped));
        $this->assertRejected('tracking_required', fn () => $this->flow->setTracking($this->admin, $order, 'DPD', ' '));
        $this->assertSame(ProductionStage::Ready, $this->state($order)->stage);

        $this->flow->setTracking($this->admin, $order, ' DPD ', ' 05123456789 ');
        $this->flow->advance($this->admin, $order, ProductionStage::Shipped);
        $state = $this->state($order);
        $this->assertSame([ProductionStage::Shipped, 'DPD', '05123456789'], [$state->stage, $state->carrier, $state->tracking_number]);
        $this->assertRejected('stage_invalid', fn () => $this->flow->advance($this->admin, $order, ProductionStage::Ready));
        $this->flow->advance($this->admin, $order, ProductionStage::Completed);
        $this->assertRejected('stage_invalid', fn () => $this->flow->advance($this->admin, $order, ProductionStage::Cancelled));
        $this->assertRejected('not_cancellable', fn () => $this->flow->cancel($this->admin, $order, 'Late'));
        $this->assertSame(ProductionStage::Completed, $this->state($order)->stage);
    }

    public function test_pickup_goes_from_ready_straight_to_completed_without_tracking(): void
    {
        $order = $this->confirmed('pickup');
        $this->flow->startProduction($this->admin, $order, debtConfirmed: true);
        $this->flow->advance($this->admin, $order, ProductionStage::QualityCheck);
        $this->flow->advance($this->admin, $order, ProductionStage::Ready);
        $this->assertRejected('stage_invalid', fn () => $this->flow->advance($this->admin, $order, ProductionStage::Shipped));
        $this->assertRejected('tracking_pickup', fn () => $this->flow->setTracking($this->admin, $order, 'DPD', '123'));
        $this->flow->advance($this->admin, $order, ProductionStage::Completed);
        $this->assertSame(ProductionStage::Completed, $this->state($order)->stage);
    }

    public function test_payment_request_requires_confirmed_price_and_active_order(): void
    {
        $order = $this->submitted();
        $this->assertRejected('not_confirmed', fn () => $this->flow->requestPayment($this->admin, $order, 1000));

        $order = $this->confirmed();
        $this->flow->cancel($this->admin, $order, 'Customer changed mind');
        $this->assertRejected('cancelled', fn () => $this->flow->requestPayment($this->admin, $order, 1000));
        $this->assertDatabaseCount('payment_requests', 0);
    }

    public function test_payment_request_is_created_pending_without_stripe(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order, 4000);
        $request->refresh();
        $this->assertSame([PaymentRequest::PENDING, 4000, 'EUR', $order->id, null, null, false], [
            $request->state, $request->amount, $request->currency, $request->order_id, $request->stripe_intent_id, $request->idempotency_key, $request->needs_review,
        ]);
        $this->assertTrue(Str::isUuid($request->public_id));
        $this->assertSame(0, $order->transactions()->count());
    }

    public function test_payment_request_amount_limits(): void
    {
        $order = $this->confirmed();
        $this->assertRejected('request_too_small', fn () => $this->flow->requestPayment($this->admin, $order, 49));
        $this->assertRejected('request_rest_too_small', fn () => $this->flow->requestPayment($this->admin, $order, 14970));
        $this->assertRejected('request_too_large', fn () => $this->flow->requestPayment($this->admin, $order, 15001));
        $this->assertDatabaseCount('payment_requests', 0);

        $this->flow->requestPayment($this->admin, $order, 50);
        $this->flow->requestPayment($this->admin, $order, 9950);
        $this->assertRejected('request_too_large', fn () => $this->flow->requestPayment($this->admin, $order, 6000));
        $this->assertRejected('request_rest_too_small', fn () => $this->flow->requestPayment($this->admin, $order, 4970));
        $this->flow->requestPayment($this->admin, $order, 5000);
        $this->assertRejected('request_too_large', fn () => $this->flow->requestPayment($this->admin, $order, 50));
        $this->assertSame(3, PaymentRequest::count());
    }

    public function test_requests_in_any_open_state_and_payments_reduce_what_can_be_requested(): void
    {
        $order = $this->confirmed();
        LunarCapture::create($order, 4000);
        $this->request($order, PaymentRequest::CREATING, 3000);
        $this->request($order, PaymentRequest::CANCELLING, 3000, 'pi_test_not_real_1');
        $this->request($order, PaymentRequest::VOID, 5000);
        $this->request($order, PaymentRequest::PAID, 5000);
        $this->assertRejected('request_too_large', fn () => $this->flow->requestPayment($this->admin, $order, 5001));
        $this->flow->requestPayment($this->admin, $order, 5000);
    }

    public function test_cancelling_request_without_intent_voids_it_and_frees_the_amount(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order, 15000);
        $this->flow->cancelPaymentRequest($this->admin, $request);
        $this->assertSame(PaymentRequest::VOID, $request->fresh()->state);
        $this->assertRejected('request_not_cancellable', fn () => $this->flow->cancelPaymentRequest($this->admin, $request));
        $this->flow->requestPayment($this->admin, $order, 15000);

        $withIntent = $this->request($order, PaymentRequest::PENDING, 100, 'pi_test_not_real_2');
        $this->assertRejected('request_not_cancellable', fn () => $this->flow->cancelPaymentRequest($this->admin, $withIntent));
        $this->assertSame(PaymentRequest::PENDING, $withIntent->fresh()->state, 'запрос с intent отменяет W-16');
    }

    public function test_cancel_voids_pending_requests_without_intent_and_does_not_refund(): void
    {
        $order = $this->confirmed();
        LunarCapture::create($order, 4000);
        $pending = $this->flow->requestPayment($this->admin, $order, 5000);
        $withIntent = $this->request($order, PaymentRequest::PENDING, 1000, 'pi_test_not_real_3');
        $creating = $this->request($order, PaymentRequest::CREATING, 1000);

        $this->assertRejected('reason_required', fn () => $this->flow->cancel($this->admin, $order, ' '));
        $this->flow->cancel($this->admin, $order, 'Customer changed mind');

        $state = $this->state($order);
        $this->assertSame([ProductionStage::Cancelled, 'Customer changed mind'], [$state->stage, $state->cancel_reason]);
        $this->assertSame(PaymentRequest::VOID, $pending->fresh()->state);
        $this->assertSame(PaymentRequest::PENDING, $withIntent->fresh()->state);
        $this->assertSame(PaymentRequest::CREATING, $creating->fresh()->state);
        $this->assertSame(4000, $this->flow->balance($order)['paid']);
        $this->assertSame(0, $order->transactions()->where('type', 'refund')->count());
        $this->assertSame(1, $order->transactions()->count());

        $this->assertRejected('cancelled', fn () => $this->flow->startProduction($this->admin, $order, debtConfirmed: true));
        $this->assertRejected('cancelled', fn () => $this->flow->changePrice($this->admin, $order, $this->prices($order, 5000, 5000, 500), 3000, 'x', 'y'));
        $this->assertRejected('not_cancellable', fn () => $this->flow->cancel($this->admin, $order, 'Again'));
    }

    public function test_order_can_be_cancelled_in_production(): void
    {
        $order = $this->confirmed();
        $this->flow->startProduction($this->admin, $order, debtConfirmed: true);
        $this->flow->cancel($this->admin, $order, 'Material unavailable');
        $this->assertSame(ProductionStage::Cancelled, $this->state($order)->stage);
        $this->assertRejected('cancelled', fn () => $this->flow->advance($this->admin, $order, ProductionStage::QualityCheck));
    }

    public function test_direct_service_calls_by_non_admin_are_rejected_without_changes(): void
    {
        $order = $this->submitted();
        $prices = $this->prices($order, 1, 1, 0);
        $confirmed = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $confirmed, 1000);
        $actions = [
            fn () => $this->flow->confirmPrice($this->client, $order, $prices, null),
            fn () => $this->flow->changePrice($this->client, $confirmed, $this->prices($confirmed, 1, 1, 0), 3000, 'fraud', 'fake'),
            fn () => $this->flow->requestPayment($this->client, $confirmed, 50),
            fn () => $this->flow->cancelPaymentRequest($this->client, $request),
            fn () => $this->flow->startProduction($this->client, $confirmed, debtConfirmed: true),
            fn () => $this->flow->advance($this->client, $confirmed, ProductionStage::InProduction),
            fn () => $this->flow->setTracking($this->client, $confirmed, 'DPD', '1'),
            fn () => $this->flow->cancel($this->client, $confirmed, 'fraud'),
            fn () => $this->flow->postAdminMessage($this->client, $confirmed, 'Hello'),
        ];
        foreach ($actions as $index => $action) {
            try {
                $action();
                $this->fail("Action {$index} accepted for a non-admin");
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame(ProductionStage::Review, $this->state($order)->stage);
        $this->assertSame(ProductionStage::Confirmed, $this->state($confirmed)->stage);
        $this->assertSame(15000, $this->total($confirmed));
        $this->assertSame([PaymentRequest::PENDING], PaymentRequest::pluck('state')->all());
        $this->assertNull($this->state($confirmed)->carrier);
        $this->assertDatabaseCount('order_messages', 0);
    }

    public function test_order_commands_are_not_registered(): void
    {
        $commands = array_filter(array_keys(Artisan::all()), fn (string $name): bool => str_starts_with($name, 'aiqme:'));
        $this->assertNotEmpty($commands);
        $this->assertSame([], array_values(array_filter($commands, fn (string $name): bool => str_contains($name, 'order') || str_contains($name, 'payment'))));
    }

    public function test_events_are_written_with_the_change_without_mail(): void
    {
        $order = $this->submitted();
        $before = IntegrationEvent::count();
        $order = $this->flow->confirmPrice($this->admin, $order, $this->prices($order, 6000, 5500, 500), 3000);
        $order = $this->flow->changePrice($this->admin, $order, $this->prices($order, 6000, 6500, 500), 3000, 'Bigger part', 'agreed');
        $request = $this->flow->requestPayment($this->admin, $order, 4000);
        $this->flow->cancelPaymentRequest($this->admin, $request);
        $this->flow->requestPayment($this->admin, $order, 4000);
        $this->flow->startProduction($this->admin, $order, debtConfirmed: true);
        $this->flow->setTracking($this->admin, $order, 'DPD', '0512');
        $this->flow->cancel($this->admin, $order, 'Customer changed mind');

        $events = IntegrationEvent::orderBy('id')->skip($before)->take(100)->get();
        $this->assertSame([
            'order.price_confirmed', 'order.price_changed', 'payment_request.created', 'payment_request.cancelled',
            'payment_request.created', 'order.stage_changed', 'order.tracking_set', 'payment_request.cancelled', 'order.cancelled',
        ], $events->pluck('type')->all());
        foreach ($events as $event) {
            $this->assertSame(['none', 'pending'], [$event->mail_status, $event->sheets_status], $event->type);
            $this->assertSame((int) $order->reference, $event->data['number']);
            $this->assertNotEmpty($event->data['summary']);
        }
        $this->assertSame(['lunar_orders', $order->id, '150.00'], [$events[0]->entity_type, $events[0]->entity_id, $events[0]->data['amount']]);
        $this->assertSame(['payment_requests', $request->id, '40.00', $request->public_id], [$events[2]->entity_type, $events[2]->entity_id, $events[2]->data['amount'], $events[2]->data['public_id']]);
        $this->assertSame('in_production', $events[5]->data['stage']);
        $this->assertSame(['agreed', 2], [$events[1]->data['agreement'], $events[1]->data['revision']]);

        $audit = Activity::inLog('audit')->where('subject_id', $order->id)->where('subject_type', $order->getMorphClass())->orderBy('id')->pluck('event')->all();
        $this->assertSame(['order.price_confirmed', 'order.price_changed', 'order.stage_changed', 'order.tracking_set', 'order.cancelled'], $audit);
    }

    public function test_failed_event_write_rolls_back_the_change(): void
    {
        $order = $this->confirmed();
        $levels = [];
        IntegrationEvent::creating(function (IntegrationEvent $event) use (&$levels): void {
            $levels[] = DB::transactionLevel();
            if ($event->type === 'order.stage_changed' || $event->type === 'order.cancelled') {
                throw new RuntimeException('outbox down');
            }
        });
        $base = DB::transactionLevel();

        foreach ([fn () => $this->flow->startProduction($this->admin, $order, debtConfirmed: true), fn () => $this->flow->cancel($this->admin, $order, 'x')] as $action) {
            try {
                $action();
                $this->fail('Change committed without its event');
            } catch (RuntimeException $exception) {
                $this->assertSame('outbox down', $exception->getMessage());
            }
        }
        $state = $this->state($order);
        $this->assertSame([ProductionStage::Confirmed, null], [$state->stage, $state->started_at]);

        IntegrationEvent::flushEventListeners();
        IntegrationEvent::creating(function (IntegrationEvent $event) use (&$levels): void {
            $levels[] = DB::transactionLevel();
            throw new RuntimeException('outbox down');
        });
        try {
            $this->flow->changePrice($this->admin, $order, $this->prices($order, 1000, 1000, 500), 3000, 'x', 'y');
        } catch (RuntimeException) {
        }
        $this->assertSame(15000, $this->total($order));
        $this->assertSame(1, PriceRevision::count());
        $pending = $this->request($order, PaymentRequest::PENDING);
        try {
            $this->flow->cancelPaymentRequest($this->admin, $pending);
        } catch (RuntimeException) {
        }
        $this->assertSame(PaymentRequest::PENDING, $pending->fresh()->state);
        IntegrationEvent::flushEventListeners();
        $this->assertNotEmpty($levels);
        $this->assertSame(array_fill(0, count($levels), $base + 1), $levels, 'событие пишется внутри транзакции изменения');
    }

    public function test_admin_message_and_customer_replies_are_recorded_and_change_nothing(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order, 4000);
        $this->assertRejected('message_invalid', fn () => $this->flow->postAdminMessage($this->admin, $order, '  '));
        $message = $this->flow->postAdminMessage($this->admin, $order, ' Wall thickness changed, new price 160 EUR. ');
        $this->assertSame([OrderMessage::ADMIN, 'Wall thickness changed, new price 160 EUR.', $this->admin->id], [$message->kind, $message->body, $message->user_id]);

        foreach (OrderMessage::REPLIES as $kind) {
            $reply = $this->flow->recordCustomerReply($order, $kind, $this->client);
            $this->assertSame([$kind, $this->client->id, null], [$reply->kind, $reply->user_id, $reply->body]);
        }
        $this->assertSame(ProductionStage::Confirmed, $this->state($order)->stage, '«прошу отменить» не отменяет заказ');
        $this->assertSame(PaymentRequest::PENDING, $request->fresh()->state, '«прошу отменить» не отменяет запрос');
        $this->assertSame(15000, $this->total($order));
        $this->assertSame(['admin', 'agree', 'question', 'cancel_request'], OrderMessage::where('order_id', $order->id)->orderBy('id')->pluck('kind')->all());
        $this->assertSame(
            ['order.message_posted', 'order.customer_replied', 'order.customer_replied', 'order.customer_replied'],
            Activity::inLog('audit')->where('event', 'like', 'order.%')->where('event', '!=', 'order.price_confirmed')->orderBy('id')->pluck('event')->all(),
        );
        $this->assertSame($this->client->id, Activity::where('event', 'order.customer_replied')->first()->causer_id);
        $this->assertRejected('reply_invalid', fn () => $this->flow->recordCustomerReply($order, 'refund', $this->client));
    }

    public function test_reply_from_anyone_but_the_owner_is_rejected_without_record(): void
    {
        $order = $this->submitted();
        $guestOrder = $this->submitted(guest: true);
        $attempts = [
            [$order, User::factory()->create()],
            [$order, null],
            [$order, $this->admin],
            [$guestOrder, $this->client],
            [$guestOrder, null],
        ];
        foreach ($attempts as $index => [$target, $actor]) {
            try {
                $this->flow->recordCustomerReply($target, 'cancel_request', $actor);
                $this->fail("Reply {$index} accepted");
            } catch (AuthorizationException) {
            }
        }
        $this->assertDatabaseCount('order_messages', 0);
        $this->assertSame(0, Activity::where('event', 'order.customer_replied')->count());
    }
}
