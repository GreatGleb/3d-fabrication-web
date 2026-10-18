<?php

namespace Tests\Feature;

use Lunar\Facades\Payments;
use Lunar\Stripe\Actions\UpdateOrderFromIntent;
use Lunar\Stripe\Facades\Stripe;
use Lunar\Stripe\Jobs\ProcessStripeWebhook;
use Lunar\Stripe\StripePaymentType;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\WorkflowTestCase;

/** Real installed driver methods, mocked SDK boundary; no Stripe credentials/network. */
class StripeDriverBoundaryTest extends WorkflowTestCase
{
    private function driverWithIntent(int $amount, string $currency = 'eur'): StripePaymentType
    {
        $intent = PaymentIntent::constructFrom(['id' => 'probe_intent', 'amount' => $amount, 'currency' => $currency, 'status' => 'succeeded', 'payment_method' => null]);
        $service = \Mockery::mock(PaymentIntentService::class);
        $service->shouldReceive('retrieve')->with('probe_intent')->andReturn($intent);
        $client = \Mockery::mock(StripeClient::class);
        $client->shouldReceive('getService')->with('paymentIntents')->andReturn($service);
        Stripe::shouldReceive('getClient')->andReturn($client);

        return new StripePaymentType;
    }

    public function test_native_stripe_driver_refuses_an_already_placed_unpaid_order(): void
    {
        $order = $this->confirmed();
        Stripe::shouldReceive('getClient')->andReturn(\Mockery::mock(StripeClient::class));
        $response = (new StripePaymentType)->order($order)->withData(['payment_intent' => 'probe_intent'])->authorize();
        $this->assertFalse($response->success);
        $this->assertSame('Order already placed', $response->message);
    }

    public function test_native_stripe_default_rejects_advance_smaller_than_draft_order_total(): void
    {
        $order = $this->confirmed();
        $order->update(['placed_at' => null]);
        $response = $this->driverWithIntent(4000)->order($order->fresh())->withData(['payment_intent' => 'probe_intent'])->authorize();
        $this->assertFalse($response->success);
        $this->assertSame('Payment intent amount does not match order total', $response->message);
    }

    public function test_native_stripe_success_mapping_does_not_mean_balance_is_fully_paid(): void
    {
        $order = $this->confirmed();
        $charge = Charge::constructFrom(['id' => 'probe_charge', 'created' => time(), 'captured' => true, 'amount' => 4000, 'amount_refunded' => 0, 'refunded' => false, 'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']], 'failure_code' => null, 'failure_message' => null, 'description' => 'Synthetic advance', 'amount_captured' => 4000, 'status' => 'succeeded']);
        Stripe::shouldReceive('getCharges')->with('probe_intent')->andReturn(collect([$charge]));
        config(['lunar.stripe.sync_addresses' => false]);
        $intent = PaymentIntent::constructFrom(['id' => 'probe_intent', 'status' => 'succeeded', 'payment_method' => null]);
        UpdateOrderFromIntent::execute($order, $intent);
        $this->assertSame('payment-received', $order->fresh()->status);
        $this->assertSame('partial', $this->flow->balance($order->fresh())['payment_state']);
        $this->assertSame(11000, $this->flow->balance($order)['due']);
        $this->assertNull($order->fresh()->production->started_at);
        UpdateOrderFromIntent::execute($order, $intent);
        $this->assertSame(1, $order->captures()->count());
    }

    public function test_native_webhook_job_skips_order_already_placed_after_first_payment(): void
    {
        $order = $this->confirmed();
        Payments::shouldReceive('driver')->never();
        (new ProcessStripeWebhook('second_probe_intent', (string) $order->id))->handle();
        $this->assertSame(0, $order->transactions()->count());
    }
}
