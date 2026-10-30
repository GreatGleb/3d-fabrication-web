<?php

namespace Tests\Feature;

use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderSubmission;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Domain\Orders\SubmittedOrder;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Models\PaymentRequest;
use App\Livewire\Admin\Overview;
use App\Models\OrderCharge;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Lunar\Models\Transaction;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Stripe\StripeClientInterface;
use Tests\Support\FakeStripe;
use Tests\Support\SignedStripeEvents;
use Tests\TestCase;

/**
 * Webhook Stripe на уровне L (W-28, §3.3 «Проверки события», «Ответ webhook», «Запись», «Аномалии», «Возвраты», §6.3,
 * §6.5, §9.2 «Оплата»): подпись и livemode, запись payment_events, исходы 1–5, тот же заряд и второе списание, succeeded
 * для cancelling/void, переплата, возвраты charge.refunded, события §5.1, «платежи на сверку» в Overview. Тесты эксперимента
 * StripeInstallmentsTest (webhook) и AcceptanceTest (оплата через webhook) — с исходами §3.3 вместо 422 и исключений.
 * События — подписанные фикстуры (SignedStripeEvents, фиктивный секрет); запросов к Stripe нет. Заказ — штатная заявка
 * W-12, цена 30 + 30 + 5 + доставка 30 = 95 EUR; запрос — requestPayment W-13 с сохранённым stripe_intent_id.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;
    use SignedStripeEvents;

    private User $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        Storage::fake('local');
        $this->client = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        $this->useWebhookSecret();
    }

    private function order(): SubmittedOrder
    {
        $cube = file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));
        $submitted = app(OrderSubmission::class)->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => 'en',
            'contact' => ['name' => 'Ona', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => null, 'company_code' => null, 'vat_code' => null],
            'delivery' => 'ship',
            'address' => ['country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gedimino pr. 1', 'postcode' => 'LT-01103'],
            'material' => 'PLA',
            'infill' => 100,
            'preferences' => ['color' => 'black', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'term' => 'standard'],
            'parts' => array_map(fn (string $name): array => [
                'file' => UploadedFile::fake()->createWithContent($name, $cube), 'unit' => 'mm', 'scale' => 100, 'quantity' => 2,
            ], ['bracket.stl', 'cover.stl']),
        ], $this->client);
        $ids = $submitted->order->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
            ->modelKeys();
        $prices = array_fill_keys($ids, 3000);
        $prices[end($ids)] = 500;
        $this->flow()->confirmPrice($this->admin, $submitted->order, $prices, 3000);

        return $submitted;
    }

    private function flow(): OrderWorkflow
    {
        return app(OrderWorkflow::class);
    }

    /** Запрос в pending с intent, как его оставляет первое открытие страницы оплаты (W-16). */
    private function pending(Order $order, int $amount = 4000, ?string $intent = null): PaymentRequest
    {
        $request = $this->flow()->requestPayment($this->admin, $order, $amount);
        $request->update(['stripe_intent_id' => $intent ?? 'pi_test_'.$request->id]);

        return $request;
    }

    private function paid(Order $order): int
    {
        return $this->flow()->balance(Order::findOrFail($order->id))['paid'];
    }

    private function captures(Order $order): int
    {
        return Transaction::where('order_id', $order->id)->where('type', 'capture')->count();
    }

    private function outcome(string $eventId): string
    {
        return PaymentEvent::findOrFail($eventId)->result;
    }

    /** @return list<string> типы событий outbox по порядку */
    private function events(): array
    {
        return IntegrationEvent::orderBy('id')->pluck('type')->filter(fn (string $type): bool => str_starts_with($type, 'payment.'))->values()->all();
    }

    private function refundPayload(string $eventId, string $charge, int $amountRefunded): string
    {
        return $this->eventPayload($eventId, 'charge.refunded', [
            'id' => $charge, 'object' => 'charge', 'amount' => 9500, 'amount_refunded' => $amountRefunded, 'currency' => 'eur', 'refunded' => false,
        ]);
    }

    public function test_two_automatic_intents_and_signed_webhooks_pay_one_order_in_two_steps(): void
    {
        $stripe = new FakeStripe;
        $this->app->instance(StripeClientInterface::class, $stripe);
        config(['services.stripe.key' => 'test-stripe-publishable-not-real', 'services.stripe.secret' => 'test-stripe-secret-not-real']);
        $order = $this->order()->order;
        foreach ([4000, 5500] as $index => $amount) {
            $request = $this->flow()->requestPayment($this->admin, $order, $amount);
            // Intent создаётся первым открытием страницы оплаты (W-16), webhook приходит на него.
            $this->actingAs($this->client)->get(route('en.checkout', $request->public_id))->assertOk();
            $this->assertSame('pi_fake_'.($index + 1), $request->fresh()->stripe_intent_id);
            $payload = $this->succeededPayload($request, 'evt_test_'.$index);
            $this->sendStripe($payload)->assertOk()->assertContent('');
            $this->sendStripe($payload)->assertOk();
            $this->assertSame(PaymentEvent::APPLIED, $this->outcome('evt_test_'.$index));
            $this->assertSame([PaymentRequest::PAID, false], [$request->fresh()->state, $request->fresh()->needs_review]);
            $this->assertSame($index === 0 ? 'partial' : 'paid', $this->flow()->balance($order->fresh())['payment_state']);
            $this->assertSame(ProductionStage::Confirmed, ProductionState::where('order_id', $order->id)->value('stage'));
            $this->assertNull(ProductionState::where('order_id', $order->id)->value('started_at'));
        }
        $this->assertSame(2, $this->captures($order));
        $this->assertSame(9500, $this->paid($order));
        $this->assertSame(['create', 'create'], $stripe->operations(), 'webhook не обращается к Stripe');
    }

    public function test_capture_stores_the_charge_on_the_lunar_transaction_and_the_charge_is_unique_among_captures(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        // Тело строится один раз: в нём created = time(), повторная сборка на границе секунды даёт другой digest.
        $payload = $this->succeededPayload($request, 'evt_test_paid');
        $this->sendStripe($payload)->assertOk();

        $capture = Transaction::where('order_id', $order->id)->where('type', 'capture')->sole();
        $this->assertSame(['ch_test_'.$request->public_id, 4000, 'stripe', true], [$capture->reference, $capture->amount->value, $capture->driver, (bool) $capture->success]);
        $this->assertSame($request->public_id, $capture->meta['request_id']);
        $this->assertSame($request->id, PaymentEvent::findOrFail('evt_test_paid')->payment_request_id);
        $this->assertSame(hash('sha256', $payload), PaymentEvent::findOrFail('evt_test_paid')->digest);

        // Частичный уникальный индекс (reference) WHERE type = 'capture': второй capture того же заряда невозможен,
        // refund с тем же reference — допустим.
        Transaction::create(['order_id' => $order->id, 'success' => true, 'type' => 'refund', 'driver' => 'stripe', 'amount' => 100, 'reference' => $capture->reference, 'status' => 'refunded', 'meta' => []]);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => Transaction::create(['order_id' => $order->id, 'success' => true, 'type' => 'capture', 'driver' => 'stripe', 'amount' => 100, 'reference' => $capture->reference, 'status' => 'succeeded', 'meta' => []]));
    }

    public function test_unsigned_foreign_secret_expired_or_malformed_events_get_400_without_a_record(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $payload = $this->succeededPayload($request, 'evt_test_bad');

        $this->sendStripe($payload, null)->assertStatus(400);
        $this->sendStripe($payload, 'test-other-secret-not-real')->assertStatus(400);
        $this->sendStripe($payload, timestamp: time() - 3600)->assertStatus(400);
        $this->sendStripe('not json')->assertStatus(400);
        config(['services.stripe.webhook_secret' => '']);
        $this->sendStripe($payload, '')->assertStatus(400);

        $this->assertSame(0, PaymentEvent::count());
        $this->assertSame(0, $this->captures($order));
        $this->assertSame(PaymentRequest::PENDING, $request->fresh()->state);
    }

    public function test_wrong_amount_still_captures_amount_received_and_flags_for_review(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $this->sendStripe($this->succeededPayload($request, 'evt_test_amount', ['amount' => 1]))->assertOk();

        $this->assertSame(PaymentEvent::NEEDS_REVIEW, $this->outcome('evt_test_amount'));
        $this->assertSame([PaymentRequest::PAID, true, 'amount_mismatch'], [$request->fresh()->state, $request->fresh()->needs_review, $request->fresh()->review_reason]);
        $this->assertSame(4000, $this->paid($order));
    }

    public function test_wrong_currency_records_no_capture_and_flags_event_and_request(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $this->sendStripe($this->succeededPayload($request, 'evt_test_usd', ['currency' => 'usd']))->assertOk();

        $this->assertSame(PaymentEvent::NEEDS_REVIEW, $this->outcome('evt_test_usd'));
        $this->assertSame([PaymentRequest::PENDING, true, 'currency'], [$request->fresh()->state, $request->fresh()->needs_review, $request->fresh()->review_reason]);
        $this->assertSame(0, $this->captures($order));
        $this->assertSame(['payment.needs_review'], $this->events());
    }

    public function test_other_intent_still_captures_and_flags_for_review(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $this->sendStripe($this->succeededPayload($request, 'evt_test_other', ['id' => 'pi_other']))->assertOk();

        $this->assertSame(PaymentEvent::NEEDS_REVIEW, $this->outcome('evt_test_other'));
        $this->assertSame([PaymentRequest::PAID, true, 'intent_mismatch'], [$request->fresh()->state, $request->fresh()->needs_review, $request->fresh()->review_reason]);
        $this->assertSame('pi_test_'.$request->id, $request->fresh()->stripe_intent_id, 'intent запроса не перезаписывается');
        $this->assertSame(4000, $this->paid($order));
    }

    public function test_zero_amount_received_is_rejected_without_changes(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $this->sendStripe($this->succeededPayload($request, 'evt_test_zero', ['amount_received' => 0]))->assertOk();

        $this->assertSame(PaymentEvent::REJECTED, $this->outcome('evt_test_zero'));
        $this->assertSame([PaymentRequest::PENDING, false], [$request->fresh()->state, $request->fresh()->needs_review]);
        $this->assertSame(0, $this->captures($order));
    }

    public function test_processing_status_is_rejected_without_changes(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $this->sendStripe($this->succeededPayload($request, 'evt_test_processing', ['status' => 'processing']))->assertOk();

        $this->assertSame(PaymentEvent::REJECTED, $this->outcome('evt_test_processing'));
        $this->assertSame([PaymentRequest::PENDING, false], [$request->fresh()->state, $request->fresh()->needs_review]);
        $this->assertSame(0, $this->captures($order));
        $this->assertSame([], $this->events());
    }

    public function test_wrong_order_id_or_empty_intent_of_a_pending_request_still_captures_for_review(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $this->sendStripe($this->succeededPayload($request, 'evt_test_order', ['metadata' => ['request_id' => $request->public_id, 'order_id' => '999999']]))->assertOk();
        $this->assertSame([PaymentRequest::PAID, 'order_mismatch'], [$request->fresh()->state, $request->fresh()->review_reason]);

        // Пустой intent у pending — несовпадение (исход 2); у creating — совпадение, id берётся из события.
        $empty = $this->flow()->requestPayment($this->admin, $order, 2000);
        $this->sendStripe($this->succeededPayload($empty, 'evt_test_empty', ['id' => 'pi_test_from_event_1']))->assertOk();
        $this->assertSame([PaymentRequest::PAID, true, 'intent_mismatch'], [$empty->fresh()->state, $empty->fresh()->needs_review, $empty->fresh()->review_reason]);
        $this->assertSame('pi_test_from_event_1', $empty->fresh()->stripe_intent_id);

        $creating = $this->flow()->requestPayment($this->admin, $order, 3500);
        $creating->update(['state' => PaymentRequest::CREATING]);
        $this->sendStripe($this->succeededPayload($creating, 'evt_test_creating', ['id' => 'pi_test_from_event_2']))->assertOk();
        $this->assertSame(PaymentEvent::APPLIED, $this->outcome('evt_test_creating'));
        $this->assertSame([PaymentRequest::PAID, false, 'pi_test_from_event_2'], [$creating->fresh()->state, $creating->fresh()->needs_review, $creating->fresh()->stripe_intent_id]);
        $this->assertSame(9500, $this->paid($order));
    }

    public function test_old_api_version_without_latest_charge_is_left_for_review_without_capture(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $intent = $this->intentObject($request);
        unset($intent['latest_charge']);
        $this->sendStripe($this->eventPayload('evt_test_old', 'payment_intent.succeeded', $intent, ['api_version' => '2020-08-27']))->assertOk();

        $this->assertSame(PaymentEvent::NEEDS_REVIEW, $this->outcome('evt_test_old'));
        $this->assertSame($request->id, PaymentEvent::findOrFail('evt_test_old')->payment_request_id);
        $this->assertSame(PaymentRequest::PENDING, $request->fresh()->state);
        $this->assertSame(0, $this->captures($order));
        $this->assertSame(['payment.needs_review'], $this->events());
    }

    public function test_full_payment_is_also_supported(): void
    {
        $order = $this->order()->order;
        $this->sendStripe($this->succeededPayload($this->pending($order, 9500), 'evt_test_full'))->assertOk();
        $this->assertSame(['due' => 0, 'payment_state' => 'paid'], array_intersect_key($this->flow()->balance($order->fresh()), ['due' => 0, 'payment_state' => 0]));
    }

    public function test_duplicate_event_and_new_event_for_same_charge_do_not_double_capture(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $payload = $this->succeededPayload($request, 'evt_test_1');
        $this->sendStripe($payload)->assertOk();
        $this->sendStripe($payload)->assertOk();
        // Тот же event_id с действительной подписью и другим телом — 200 без эффекта; digest не меняется.
        $changed = $this->eventPayload('evt_test_1', 'payment_intent.succeeded', $this->intentObject($request, ['latest_charge' => 'ch_test_changed']), ['pending_webhooks' => 3]);
        $this->sendStripe($changed)->assertOk();
        $this->assertSame(hash('sha256', $payload), PaymentEvent::findOrFail('evt_test_1')->digest);
        // Новое событие того же заряда по тому же запросу — повтор без эффекта.
        $this->sendStripe($this->succeededPayload($request, 'evt_test_redelivery'))->assertOk();

        $this->assertSame(PaymentEvent::IGNORED, $this->outcome('evt_test_redelivery'));
        $this->assertSame(4000, $this->paid($order));
        $this->assertSame(1, $this->captures($order));
        $this->assertFalse($request->fresh()->needs_review);
        $this->assertSame(2, PaymentEvent::count());
        $this->assertSame(['payment.succeeded'], $this->events());
    }

    public function test_failed_and_requires_action_events_do_not_pay_or_start_production(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $this->sendStripe($this->eventPayload('evt_test_fail', 'payment_intent.payment_failed', $this->intentObject($request, ['status' => 'requires_payment_method', 'amount_received' => 0, 'latest_charge' => 'ch_test_declined'])))->assertOk();
        $this->sendStripe($this->succeededPayload($request, 'evt_test_action', ['status' => 'requires_action', 'amount_received' => 0, 'latest_charge' => null]))->assertOk();
        $this->assertSame([PaymentEvent::IGNORED, PaymentEvent::REJECTED], [$this->outcome('evt_test_fail'), $this->outcome('evt_test_action')]);
        $this->assertSame(0, $this->paid($order));
        $this->assertNull(ProductionState::where('order_id', $order->id)->value('started_at'));

        $this->sendStripe($this->succeededPayload($request, 'evt_test_retry'))->assertOk();
        $this->assertSame(4000, $this->paid($order));
    }

    public function test_failed_event_then_success_then_late_failure_does_not_undo_capture(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $failed = fn (string $id): string => $this->eventPayload($id, 'payment_intent.payment_failed', $this->intentObject($request, ['status' => 'requires_payment_method', 'amount_received' => 0, 'latest_charge' => null]));

        $this->sendStripe($failed('evt_test_failed'))->assertOk();
        $this->assertSame(0, $this->paid($order));
        $this->assertSame(['payment.failed'], $this->events(), '«оплата не прошла» по неоплаченному запросу');
        $this->sendStripe($this->succeededPayload($request, 'evt_test_paid'))->assertOk();
        $this->sendStripe($failed('evt_test_late_failed'))->assertOk();

        $this->assertSame(PaymentEvent::IGNORED, $this->outcome('evt_test_late_failed'));
        $this->assertSame(4000, $this->paid($order));
        $this->assertSame(PaymentRequest::PAID, $request->fresh()->state);
        $this->assertSame(['payment.failed', 'payment.succeeded'], $this->events(), 'после paid «не прошла» не пишется');
    }

    public function test_live_event_is_rejected_outside_production_even_with_a_valid_signature(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $this->sendStripe($this->eventPayload('evt_test_live', 'payment_intent.succeeded', $this->intentObject($request), ['livemode' => true]))->assertStatus(400);

        $this->assertSame(0, PaymentEvent::count());
        $this->assertSame(0, $this->captures($order));
    }

    public function test_same_charge_cannot_pay_another_request(): void
    {
        $order = $this->order()->order;
        $first = $this->pending($order);
        $this->sendStripe($this->succeededPayload($first, 'evt_test_first'))->assertOk();
        $next = $this->pending($order, 3000);
        $this->sendStripe($this->succeededPayload($next, 'evt_test_another', ['latest_charge' => 'ch_test_'.$first->public_id]))->assertOk();

        $this->assertSame(PaymentEvent::NEEDS_REVIEW, $this->outcome('evt_test_another'));
        $this->assertSame([PaymentRequest::PENDING, true, 'charge_of_another_request'], [$next->fresh()->state, $next->fresh()->needs_review, $next->fresh()->review_reason]);
        $this->assertFalse($first->fresh()->needs_review);
        $this->assertSame(4000, $this->paid($order));
        $this->assertSame(1, $this->captures($order));
    }

    public function test_second_charge_on_the_same_request_gets_its_own_capture_for_review(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $this->sendStripe($this->succeededPayload($request, 'evt_test_first'))->assertOk();
        $this->sendStripe($this->succeededPayload($request, 'evt_test_second', ['id' => 'pi_test_second', 'latest_charge' => 'ch_test_second']))->assertOk();

        $this->assertSame(PaymentEvent::NEEDS_REVIEW, $this->outcome('evt_test_second'));
        $this->assertSame(2, $this->captures($order));
        $this->assertSame(8000, $this->paid($order));
        $this->assertSame([true, 'intent_mismatch'], [$request->fresh()->needs_review, $request->fresh()->review_reason]);
    }

    public function test_succeeded_for_cancelling_or_void_request_is_recorded_and_flagged_when_the_order_is_cancelled(): void
    {
        $order = $this->order()->order;
        $cancelling = $this->pending($order, 2000);
        $cancelling->update(['state' => PaymentRequest::CANCELLING]);
        $void = $this->pending($order, 3000);
        $void->update(['state' => PaymentRequest::VOID]);
        $this->sendStripe($this->succeededPayload($cancelling, 'evt_test_cancelling'))->assertOk();
        $this->sendStripe($this->succeededPayload($void, 'evt_test_void'))->assertOk();

        $this->assertSame([PaymentEvent::APPLIED, PaymentEvent::APPLIED], [$this->outcome('evt_test_cancelling'), $this->outcome('evt_test_void')]);
        $this->assertSame([PaymentRequest::PAID, false], [$cancelling->fresh()->state, $cancelling->fresh()->needs_review]);
        $this->assertSame([PaymentRequest::PAID, false], [$void->fresh()->state, $void->fresh()->needs_review]);
        $this->assertSame(5000, $this->paid($order));

        // Отменённый заказ: запрос с intent уходит в cancelling (без ключей Stripe так и остаётся), поздняя оплата —
        // capture, paid и needs_review.
        $other = $this->order()->order;
        $late = $this->pending($other);
        $this->flow()->cancel($this->admin, $other, 'Customer changed mind');
        $this->assertSame(PaymentRequest::CANCELLING, $late->fresh()->state);
        $this->sendStripe($this->succeededPayload($late, 'evt_test_late'))->assertOk();
        $this->assertSame(PaymentEvent::NEEDS_REVIEW, $this->outcome('evt_test_late'));
        $this->assertSame([PaymentRequest::PAID, true, 'cancelled_order'], [$late->fresh()->state, $late->fresh()->needs_review, $late->fresh()->review_reason]);
        $this->assertSame(4000, $this->paid($other));
        $this->assertSame(ProductionStage::Cancelled, ProductionState::where('order_id', $other->id)->value('stage'));
    }

    public function test_unknown_request_is_recorded_without_capture_and_without_a_request_link(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        foreach (['evt_test_unknown' => (string) Str::uuid(), 'evt_test_garbage' => 'not-a-uuid'] as $id => $publicId) {
            $this->sendStripe($this->succeededPayload($request, $id, ['metadata' => ['request_id' => $publicId, 'order_id' => (string) $order->id]]))->assertOk();
            $this->assertSame(PaymentEvent::NEEDS_REVIEW, $this->outcome($id));
            $this->assertNull(PaymentEvent::findOrFail($id)->payment_request_id);
        }
        $this->assertSame(0, $this->captures($order));
        $this->assertSame([PaymentRequest::PENDING, false], [$request->fresh()->state, $request->fresh()->needs_review]);
    }

    public function test_payment_above_the_confirmed_price_is_flagged_overpaid(): void
    {
        $order = $this->order()->order;
        $this->sendStripe($this->succeededPayload($this->pending($order), 'evt_test_advance'))->assertOk();
        // Остаток 55 EUR запрошен дважды: первый запрос отменён (void), но его intent всё же оплачен.
        $first = $this->pending($order, 5500);
        $first->update(['state' => PaymentRequest::VOID]);
        $second = $this->pending($order, 5500);
        $this->sendStripe($this->succeededPayload($second, 'evt_test_rest'))->assertOk();
        $this->assertFalse($second->fresh()->needs_review);
        $this->sendStripe($this->succeededPayload($first, 'evt_test_rest_again'))->assertOk();

        $this->assertSame(PaymentEvent::NEEDS_REVIEW, $this->outcome('evt_test_rest_again'));
        $this->assertSame([PaymentRequest::PAID, true, 'overpaid'], [$first->fresh()->state, $first->fresh()->needs_review, $first->fresh()->review_reason]);
        $this->assertSame(['paid' => 15000, 'payment_state' => 'overpaid'], array_intersect_key($this->flow()->balance($order->fresh()), ['paid' => 0, 'payment_state' => 0]));
    }

    public function test_partial_refunds_reduce_paid_once_and_are_found_by_their_capture(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order, 9500);
        $this->sendStripe($this->succeededPayload($request, 'evt_test_paid'))->assertOk();
        $charge = 'ch_test_'.$request->public_id;
        $capture = Transaction::where('type', 'capture')->where('reference', $charge)->sole();

        $this->sendStripe($this->refundPayload('evt_test_refund_10', $charge, 1000))->assertOk();
        $this->sendStripe($this->refundPayload('evt_test_refund_25', $charge, 2500))->assertOk();
        $this->sendStripe($this->refundPayload('evt_test_refund_25', $charge, 2500))->assertOk();
        $this->sendStripe($this->refundPayload('evt_test_refund_late_10', $charge, 1000))->assertOk();

        $refunds = Transaction::where('order_id', $order->id)->where('type', 'refund')->orderBy('id')->get();
        $this->assertSame([1000, 1500], $refunds->map(fn (Transaction $refund): int => $refund->amount->value)->all());
        $this->assertSame([$capture->id, $capture->id], $refunds->pluck('parent_transaction_id')->all());
        $this->assertSame([$charge, $charge], $refunds->pluck('reference')->all());
        $this->assertSame(7000, $this->paid($order));
        $this->assertSame(PaymentEvent::APPLIED, $this->outcome('evt_test_refund_25'));
        $this->assertSame($request->id, PaymentEvent::findOrFail('evt_test_refund_25')->payment_request_id);
        $this->assertSame(['payment.succeeded', 'payment.refunded', 'payment.refunded'], $this->events());

        // Возврат лишнего второго списания по тому же запросу находится по своей capture.
        $this->sendStripe($this->succeededPayload($request, 'evt_test_second', ['id' => 'pi_test_second', 'latest_charge' => 'ch_test_second']))->assertOk();
        $this->sendStripe($this->refundPayload('evt_test_refund_second', 'ch_test_second', 9500))->assertOk();
        $second = Transaction::where('type', 'refund')->where('reference', 'ch_test_second')->sole();
        $this->assertSame(Transaction::where('type', 'capture')->where('reference', 'ch_test_second')->value('id'), $second->parent_transaction_id);
        $this->assertSame(7000, $this->paid($order));
    }

    public function test_refund_of_an_unknown_charge_needs_review_and_is_reprocessed_when_resent(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $charge = 'ch_test_'.$request->public_id;
        // Возврат пришёл раньше оплаты (или заряд без capture) — needs_review, «оплачено» не меняется.
        $refund = $this->refundPayload('evt_test_early_refund', $charge, 1500);
        $this->sendStripe($refund)->assertOk();
        $this->assertSame(PaymentEvent::NEEDS_REVIEW, $this->outcome('evt_test_early_refund'));
        $this->assertNull(PaymentEvent::findOrFail('evt_test_early_refund')->payment_request_id);

        $this->sendStripe($this->succeededPayload($request, 'evt_test_paid'))->assertOk();
        $this->sendStripe($refund)->assertOk();
        $this->assertSame(PaymentEvent::APPLIED, $this->outcome('evt_test_early_refund'));
        $this->assertSame(2500, $this->paid($order));

        // Повтор после этого — без эффекта; повтор события, разобранного администратором, — тоже.
        $this->sendStripe($refund)->assertOk();
        $this->assertSame(1, Transaction::where('type', 'refund')->count());
        $this->sendStripe($this->refundPayload('evt_test_reviewed', 'ch_test_unknown', 500))->assertOk();
        PaymentEvent::findOrFail('evt_test_reviewed')->update(['reviewed_at' => now()]);
        $this->sendStripe($this->refundPayload('evt_test_reviewed', 'ch_test_unknown', 500))->assertOk();
        $this->assertSame(1, Transaction::where('type', 'refund')->count());
    }

    public function test_payment_event_is_written_in_the_same_transaction_as_the_change(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        IntegrationEvent::creating(function (IntegrationEvent $event): void {
            if ($event->type === 'payment.succeeded') {
                throw new RuntimeException('outbox write failed (test)');
            }
        });
        $this->sendStripe($this->succeededPayload($request, 'evt_test_rollback'))->assertServerError();

        $this->assertSame(0, $this->captures($order));
        $this->assertSame(PaymentRequest::PENDING, $request->fresh()->state);
        $this->assertSame(0, PaymentEvent::count(), 'событие не записано — Stripe повторит доставку');
    }

    public function test_route_is_outside_the_web_group_without_cookies_and_csrf(): void
    {
        $this->post('/stripe/webhook')->assertNotFound();
        $this->assertNotContains('web', Route::getRoutes()->getByName('webhooks.stripe')->gatherMiddleware());
        $this->assertSame(url('/webhooks/stripe'), route('webhooks.stripe'));

        // Проверка CSRF включена, как в рабочей среде (в PHPUnit middleware её пропускает).
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->post('/lt/kontaktai', [])->assertStatus(419);
        $request = $this->pending($this->order()->order);
        $bad = $this->sendStripe($this->succeededPayload($request, 'evt_test_csrf'), 'test-other-secret-not-real')->assertStatus(400);
        $this->assertFalse($bad->headers->has('Set-Cookie'));
        $good = $this->sendStripe($this->succeededPayload($request, 'evt_test_csrf'))->assertOk();
        $this->assertFalse($good->headers->has('Set-Cookie'));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_overview_lists_payments_to_review_and_resolving_them_is_logged(): void
    {
        $order = $this->order()->order;
        $request = $this->pending($order);
        $this->sendStripe($this->succeededPayload($request, 'evt_test_usd', ['currency' => 'usd']))->assertOk();
        $this->sendStripe($this->refundPayload('evt_test_unknown_refund', 'ch_test_unknown', 500))->assertOk();
        $this->sendStripe($this->succeededPayload($request, 'evt_test_rejected', ['amount_received' => 0]))->assertOk();

        app()->setLocale('en');
        $overview = Livewire::actingAs($this->admin)->test(Overview::class)
            ->assertSeeHtml('id="overview-payment-review"')
            ->assertSeeHtml('data-review-request="'.$request->id.'"')
            ->assertSeeHtml('data-review-event="evt_test_usd"')
            ->assertSeeHtml('data-review-event="evt_test_unknown_refund"')
            ->assertDontSeeHtml('data-review-event="evt_test_rejected"')
            ->assertSee('Payments to review: 3.');
        $this->actingAs($this->admin)->get('/en/admin')->assertOk()->assertSee('id="overview-payment-review"', false);

        $overview->call('resolveRequest', $request->id)->assertDontSeeHtml('data-review-request=');
        $this->assertSame([false, PaymentRequest::PENDING], [$request->fresh()->needs_review, $request->fresh()->state]);
        $overview->call('resolveEvent', 'evt_test_usd')->call('resolveEvent', 'evt_test_unknown_refund')
            ->assertDontSeeHtml('id="overview-payment-review"');
        $this->assertNotNull(PaymentEvent::findOrFail('evt_test_usd')->reviewed_at);
        $this->assertNotNull(PaymentEvent::findOrFail('evt_test_unknown_refund')->reviewed_at);

        $log = Activity::whereIn('event', ['payment.review_resolved', 'payment.event_reviewed'])->orderBy('id')->get();
        $this->assertSame(['payment.review_resolved', 'payment.event_reviewed', 'payment.event_reviewed'], $log->pluck('event')->all());
        $this->assertSame([$this->admin->id], $log->pluck('causer_id')->unique()->values()->all());
        $this->assertSame([$order->id, $order->id], [$log[0]->subject_id, $log[1]->subject_id]);
        $this->assertSame('evt_test_unknown_refund', $log[2]->properties['event_id']);

        // Уже разобранное повторно не разбирается; клиенту действие недоступно.
        $this->expectException(ModelNotFoundException::class);
        $overview->call('resolveEvent', 'evt_test_usd');
    }

    public function test_overview_review_block_is_admin_only_and_hidden_in_the_demo_build(): void
    {
        $request = $this->pending($this->order()->order);
        $this->sendStripe($this->succeededPayload($request, 'evt_test_usd', ['currency' => 'usd']))->assertOk();

        $this->actingAs($this->client)->get('/en/admin')->assertNotFound();
        $this->app['env'] = 'demo';
        Livewire::actingAs($this->admin)->test(Overview::class)->assertDontSeeHtml('overview-payment-review')->assertDontSeeHtml('data-review-');
    }
}
