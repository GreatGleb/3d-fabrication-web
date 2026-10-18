<?php

namespace Tests\Feature;

use App\Models\StlPart;
use App\Models\User;
use App\Payments\LocalTestAdapter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Lunar\Exceptions\Carts\CartException;
use Lunar\Models\Cart;
use Lunar\Models\CartAddress;
use Lunar\Models\Currency;
use Tests\WorkflowTestCase;

class OrderWorkflowTest extends WorkflowTestCase
{
    public function test_submission_persists_two_private_stl_lines_without_payment(): void
    {
        $order = $this->submitted();
        $this->assertSame(12000, $order->total->value);
        $this->assertCount(2, $order->lines);
        $this->assertInstanceOf(StlPart::class, $order->lines[0]->purchasable);
        $this->assertNotSame($order->lines[0]->purchasable->sha256, $order->lines[1]->purchasable->sha256);
        $this->assertSame('PETG', $order->lines[1]->purchasable->parameters['material']);
        $this->assertDatabaseCount('lunar_products', 0);
        $this->assertSame(0, $order->transactions()->count());
        $this->assertNull($order->production->confirmed_at);
        Storage::disk('local')->assertExists($order->lines[0]->purchasable->path);
    }

    public function test_advance_and_separate_balance_belong_to_one_order_and_never_start_production(): void
    {
        $order = $this->confirmed();
        $advance = $this->flow->requestPayment($this->admin, $order->id, 4000);
        $this->receipt($advance);
        $this->assertSame(['total' => 15000, 'paid' => 4000, 'due' => 11000, 'overpaid' => 0, 'payment_state' => 'partial'], $this->flow->balance($order->fresh()));
        $this->assertSame('ready', $order->fresh()->production->state);
        $balance = $this->flow->requestPayment($this->admin, $order->id, 11000);
        $this->receipt($balance, 'evt_2', 'test_charge_2');
        $this->assertSame($advance->order_id, $balance->order_id);
        $this->assertSame(2, $order->captures()->count());
        $this->assertSame('paid', $this->flow->balance($order->fresh())['payment_state']);
        $this->assertSame('ready', $order->fresh()->production->state);
    }

    public function test_full_payment_is_also_supported(): void
    {
        $order = $this->confirmed();
        $this->receipt($this->flow->requestPayment($this->admin, $order->id, 15000));
        $this->assertSame(0, $this->flow->balance($order->fresh())['due']);
    }

    public function test_duplicate_event_and_new_event_for_same_charge_do_not_double_capture(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order->id, 4000);
        $this->receipt($request);
        $this->receipt($request);
        $this->receipt($request, 'evt_redelivery');
        $this->assertSame(4000, $this->flow->balance($order)['paid']);
        $this->assertSame(1, $order->captures()->count());
    }

    public function test_failed_and_requires_action_events_do_not_pay_or_start_production(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order->id, 4000);
        $this->receipt($request, 'evt_fail', 'test_charge_1', 'failed');
        $this->receipt($request, 'evt_action', 'test_charge_1', 'requires_action');
        $this->assertSame(0, $this->flow->balance($order)['paid']);
        $this->assertNull($order->fresh()->production->started_at);
        $this->receipt($request, 'evt_retry');
        $this->assertSame(4000, $this->flow->balance($order)['paid']);
    }

    public function test_wrong_provider_amount_is_rejected_even_with_valid_local_signature(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order->id, 4000);
        $adapter = app(LocalTestAdapter::class);
        $event = $adapter->event($request, 'evt_wrong', 'test_charge_wrong');
        $event['amount'] = 1;
        try {
            $adapter->receive($event, $adapter->sign($event));
            $this->fail('Accepted tampered amount');
        } catch (ValidationException) {
        }
        $this->assertSame(0, $order->transactions()->count());
        $this->assertDatabaseCount('payment_events', 0);
        $this->assertNull($order->fresh()->production->started_at);
    }

    public function test_wrong_currency_is_rejected(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order->id, 4000);
        $adapter = app(LocalTestAdapter::class);
        $event = $adapter->event($request, 'evt_wrong', 'test_charge_wrong');
        $event['currency'] = 'USD';
        $this->expectException(ValidationException::class);
        $adapter->receive($event, $adapter->sign($event));
    }

    public function test_invalid_local_signature_is_rejected(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order->id, 4000);
        $adapter = app(LocalTestAdapter::class);
        $event = $adapter->event($request, 'evt_wrong', 'test_charge_wrong');
        $this->expectException(ValidationException::class);
        $adapter->receive($event, 'forged');
    }

    public function test_same_charge_cannot_pay_another_request(): void
    {
        $order = $this->confirmed();
        $first = $this->flow->requestPayment($this->admin, $order->id, 4000);
        $this->receipt($first);
        $next = $this->flow->requestPayment($this->admin, $order->id, 3000);
        try {
            $this->receipt($next, 'evt_another');
            $this->fail('Reused charge');
        } catch (QueryException) {
        }
        $this->assertSame(4000, $this->flow->balance($order)['paid']);
        $this->assertSame(1, $order->captures()->count());
    }

    public function test_production_can_start_with_zero_payment_and_remain_unpaid(): void
    {
        $order = $this->confirmed();
        $this->flow->startProduction($this->admin, $order->id);
        $this->assertSame('in-production', $order->fresh()->production->state);
        $this->assertSame(15000, $this->flow->balance($order)['due']);
        $this->assertSame('unpaid', $this->flow->balance($order)['payment_state']);
    }

    public function test_price_change_after_start_is_rejected(): void
    {
        $order = $this->confirmed();
        $this->flow->startProduction($this->admin, $order->id);
        $this->expectException(ValidationException::class);
        $this->flow->confirmPrice($this->admin, $order->id, array_combine($order->lines->modelKeys(), [5000, 5000]), 'Change', 'agreement');
    }

    public function test_production_requires_confirmed_price(): void
    {
        $order = $this->submitted();
        $this->expectException(ValidationException::class);
        $this->flow->startProduction($this->admin, $order->id);
    }

    public function test_payment_request_requires_confirmed_price(): void
    {
        $order = $this->submitted();
        $this->expectException(ValidationException::class);
        $this->flow->requestPayment($this->admin, $order->id, 100);
    }

    public function test_agreed_revision_after_advance_keeps_history_and_paid_amount(): void
    {
        $order = $this->confirmed();
        $this->receipt($this->flow->requestPayment($this->admin, $order->id, 4000));
        $order = $this->flow->confirmPrice($this->admin, $order->id, array_combine($order->lines->modelKeys(), [7000, 10000]), 'Agreed revised specification', 'synthetic-consent-002');
        $this->assertSame(17000, $order->total->value);
        $this->assertSame(13000, $this->flow->balance($order)['due']);
        $this->assertSame(4000, $this->flow->balance($order)['paid']);
        $this->assertDatabaseCount('price_revisions', 2);
        $this->assertSame(5000, $order->lines[0]->meta['estimate']);
        $this->assertSame('synthetic-consent-002', DB::table('price_revisions')->latest('id')->first()->agreement_reference);
    }

    public function test_revision_below_paid_amount_requires_refund_workflow(): void
    {
        $order = $this->confirmed();
        $this->receipt($this->flow->requestPayment($this->admin, $order->id, 14000));
        $this->expectException(ValidationException::class);
        $this->flow->confirmPrice($this->admin, $order->id, array_combine($order->lines->modelKeys(), [5000, 5000]), 'Reduction', 'agreement');
    }

    public function test_revision_with_pending_request_is_blocked(): void
    {
        $order = $this->confirmed();
        $this->flow->requestPayment($this->admin, $order->id, 4000);
        $this->expectException(ValidationException::class);
        $this->flow->confirmPrice($this->admin, $order->id, array_combine($order->lines->modelKeys(), [5000, 5000]), 'Reduction', 'agreement');
    }

    public function test_pending_requests_reserve_balance(): void
    {
        $order = $this->confirmed();
        $this->flow->requestPayment($this->admin, $order->id, 10000);
        $this->expectException(ValidationException::class);
        $this->flow->requestPayment($this->admin, $order->id, 6000);
    }

    public function test_cancel_does_not_refund_captured_money(): void
    {
        $order = $this->confirmed();
        $this->receipt($this->flow->requestPayment($this->admin, $order->id, 4000));
        $this->flow->cancel($this->admin, $order->id);
        $this->assertSame('cancelled', $order->fresh()->production->state);
        $this->assertSame(4000, $this->flow->balance($order)['paid']);
        $this->assertSame(0, $order->refunds()->count());
        $this->expectException(ValidationException::class);
        $this->flow->startProduction($this->admin, $order->id);
    }

    public function test_owner_and_admin_can_download_stl_but_other_buyer_and_guest_cannot(): void
    {
        $order = $this->submitted();
        $part = $order->lines[0]->purchasable;
        $this->get(route('probe.file', $part))->assertRedirect();
        $this->actingAs($this->buyer)->get(route('probe.file', $part))->assertDownload('bracket.stl');
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('probe.file', $part))->assertForbidden();
        $this->actingAs($other)->get(route('probe.order', $order))->assertForbidden();
        $this->actingAs($this->admin)->get(route('probe.file', $part))->assertDownload();
        $this->assertFalse(file_exists(public_path('storage/'.$part->path)));
    }

    public function test_buyer_cannot_set_payment_amount_or_start_or_confirm_price_via_http(): void
    {
        $order = $this->confirmed();
        $this->actingAs($this->buyer)->postJson(route('probe.payment', $order), ['amount' => 1])->assertForbidden();
        $this->actingAs($this->buyer)->postJson(route('probe.start', $order))->assertForbidden();
        $this->actingAs($this->buyer)->postJson(route('probe.confirm', $order), ['prices' => array_combine($order->lines->modelKeys(), [1, 1]), 'reason' => 'fraud', 'agreement' => 'fake'])->assertForbidden();
        $this->assertSame(15000, $order->fresh()->total->value);
    }

    public function test_http_and_console_service_use_same_order_rules(): void
    {
        $order = $this->confirmed();
        $this->actingAs($this->admin)->postJson(route('probe.payment', $order), ['amount' => 4000])->assertOk()->assertJsonPath('amount', 4000);
        $this->assertDatabaseHas('payment_requests', ['order_id' => $order->id, 'amount' => 4000]);
        $this->actingAs($this->admin)->get(route('probe.order', $order))->assertOk()->assertSee('15000')->assertSee('Due');
    }

    public function test_custom_purchasable_also_works_in_native_lunar_cart_pipeline(): void
    {
        $order = $this->submitted();
        $cart = Cart::create(['user_id' => $this->buyer->id, 'customer_id' => $order->customer_id, 'currency_id' => Currency::getDefault()->id, 'channel_id' => $order->channel_id]);
        $cart->add($order->lines[0]->purchasable, 1);
        $cart->add($order->lines[1]->purchasable, 1);
        $this->assertSame(12000, $cart->recalculate()->total->value);
        $this->assertCount(2, $cart->lines);
    }

    public function test_agreed_price_reduction_above_paid_amount_adjusts_only_debt(): void
    {
        $order = $this->confirmed();
        $this->receipt($this->flow->requestPayment($this->admin, $order->id, 4000));
        $order = $this->flow->confirmPrice($this->admin, $order->id, array_combine($order->lines->modelKeys(), [5000, 5000]), 'Agreed reduction', 'synthetic-consent-reduction');
        $this->assertSame(6000, $this->flow->balance($order)['due']);
        $this->assertSame(4000, $this->flow->balance($order)['paid']);
    }

    public function test_price_change_requires_customer_agreement_reference(): void
    {
        $order = $this->submitted();
        $this->expectException(ValidationException::class);
        $this->flow->confirmPrice($this->admin, $order->id, array_combine($order->lines->modelKeys(), [5000, 5000]), 'Review', '');
    }

    public function test_native_cart_rebuild_updates_draft_but_rejects_placed_order(): void
    {
        $submitted = $this->submitted();
        $cart = Cart::create(['user_id' => $this->buyer->id, 'customer_id' => $submitted->customer_id, 'currency_id' => Currency::whereCode('EUR')->firstOrFail()->id, 'channel_id' => $submitted->channel_id]);
        foreach ($submitted->lines as $line) {
            $cart->add($line->purchasable, 1);
        }
        CartAddress::factory()->create(['cart_id' => $cart->id, 'type' => 'billing']);
        $draft = $cart->createOrder();
        $this->assertTrue($draft->isDraft());
        $part = $submitted->lines[0]->purchasable;
        $part->prices()->update(['price' => 5500]);
        $updated = $cart->fresh()->createOrder(orderIdToUpdate: $draft->id);
        $this->assertSame($draft->id, $updated->id);
        $this->assertSame(12500, $updated->total->value);
        $updated->update(['placed_at' => now()]);
        $this->expectException(CartException::class);
        $cart->fresh()->createOrder(orderIdToUpdate: $updated->id);
    }
}
