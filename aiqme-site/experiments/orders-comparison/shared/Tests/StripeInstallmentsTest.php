<?php

namespace Comparison\Tests;

use App\Models\PaymentRequest;
use App\Models\User;
use Comparison\Payments\StripeInstallments;
use Comparison\ReadModel;
use Illuminate\Testing\TestResponse;
use Mockery;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClientInterface;
use Tests\WorkflowTestCase;

class StripeInstallmentsTest extends WorkflowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => 'local-signature-fixture-only']);
    }

    private function mockCreate(PaymentRequest $request, string $id): void
    {
        $service = Mockery::mock(PaymentIntentService::class);
        $service->shouldReceive('create')->once()->with(Mockery::on(fn ($p) => $p['amount'] === $request->amount && $p['currency'] === 'eur' && $p['capture_method'] === 'automatic' && $p['metadata']['request_id'] === $request->public_id && $p['metadata']['order_id'] === (string) $request->order_id), ['idempotency_key' => 'aiqme-request-'.$request->public_id])->andReturn(PaymentIntent::constructFrom(['id' => $id, 'client_secret' => 'local-client-secret-fixture', 'amount' => $request->amount, 'currency' => 'eur', 'livemode' => false]));
        $client = Mockery::mock(StripeClientInterface::class);
        $client->shouldReceive('getService')->with('paymentIntents')->andReturn($service);
        app()->instance(StripeClientInterface::class, $client);
    }

    private function payload(PaymentRequest $request, string $id, string $type = 'payment_intent.succeeded', array $overrides = []): string
    {
        return json_encode(['id' => $id, 'object' => 'event', 'livemode' => false, 'type' => $type, 'data' => ['object' => array_replace_recursive(['id' => $request->fresh()->stripe_intent_id, 'object' => 'payment_intent', 'amount' => $request->amount, 'amount_received' => $request->amount, 'currency' => 'eur', 'status' => 'succeeded', 'latest_charge' => 'ch_local_'.$request->public_id, 'metadata' => ['request_id' => $request->public_id, 'order_id' => (string) $request->order_id]], $overrides)]]);
    }

    private function send(string $payload, bool $valid = true): TestResponse
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $valid ? 'local-signature-fixture-only' : 'wrong-signature');

        return $this->call('POST', route('comparison.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature], $payload);
    }

    public function test_two_automatic_intents_and_signed_webhooks_pay_one_order_in_two_steps(): void
    {
        $order = $this->confirmed();
        foreach ([4000, 11000] as $index => $amount) {
            $request = $this->flow->requestPayment($this->admin, $order->id, $amount);
            $this->mockCreate($request, 'pi_local_'.$index);
            $this->actingAs($this->buyer)->postJson(route('comparison.checkout', $request->public_id), ['amount' => 1])->assertOk()->assertJsonPath('intent_id', 'pi_local_'.$index);
            $payload = $this->payload($request, 'evt_local_'.$index);
            $this->send($payload)->assertOk();
            $this->send($payload)->assertOk();
            $this->assertSame($index === 0 ? 'partial' : 'paid', $this->flow->balance($order->fresh())['payment_state']);
            $this->assertNull(ReadModel::state($order->fresh())->started_at);
        }
        $this->assertSame(2, $order->captures()->count());
        $this->assertSame(15000, $this->flow->balance($order)['paid']);
    }

    public function test_bad_signature_and_wrong_amount_currency_or_intent_are_rejected(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order->id, 4000);
        $request->update(['stripe_intent_id' => 'pi_local']);
        $payload = $this->payload($request, 'evt_bad');
        $this->send($payload, false)->assertStatus(400);
        foreach ([['amount' => 1], ['currency' => 'usd'], ['id' => 'pi_other'], ['amount_received' => 0], ['status' => 'processing']] as $index => $change) {
            $this->send($this->payload($request, 'evt_wrong_'.$index, overrides: $change))->assertUnprocessable();
        }
        $this->assertSame(0, $order->captures()->count());
        $this->assertNull(ReadModel::state($order->fresh())->started_at);
    }

    public function test_failed_event_then_success_then_late_failure_does_not_undo_capture(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order->id, 4000);
        $request->update(['stripe_intent_id' => 'pi_local']);
        $this->send($this->payload($request, 'evt_failed', 'payment_intent.payment_failed'))->assertOk();
        $this->assertSame(0, $this->flow->balance($order)['paid']);
        $this->send($this->payload($request, 'evt_paid'))->assertOk();
        $this->send($this->payload($request, 'evt_late_failed', 'payment_intent.payment_failed'))->assertOk();
        $this->assertSame(4000, $this->flow->balance($order)['paid']);
        $this->assertSame('paid', $request->fresh()->state);
    }

    public function test_checkout_is_idempotent_and_does_not_create_a_second_intent(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order->id, 4000);
        $this->mockCreate($request, 'pi_local');
        app(StripeInstallments::class)->create($request);
        $service = Mockery::mock(PaymentIntentService::class);
        $service->shouldReceive('retrieve')->once()->with('pi_local')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_local', 'client_secret' => 'local-fixture', 'livemode' => false, 'amount' => 4000, 'currency' => 'eur']));
        $client = Mockery::mock(StripeClientInterface::class);
        $client->shouldReceive('getService')->with('paymentIntents')->andReturn($service);
        app()->instance(StripeClientInterface::class, $client);
        $this->assertSame('pi_local', app(StripeInstallments::class)->create($request)['intent_id']);
    }

    public function test_other_buyer_cannot_create_intent_for_someone_elses_request(): void
    {
        $order = $this->confirmed();
        $request = $this->flow->requestPayment($this->admin, $order->id, 4000);
        $this->actingAs(User::factory()->create())->postJson(route('comparison.checkout', $request->public_id))->assertForbidden();
    }

    public function test_live_event_is_rejected_even_with_valid_local_signature(): void
    {
        $payload = json_encode(['id' => 'evt_live', 'livemode' => true, 'type' => 'payment_intent.succeeded', 'data' => ['object' => []]]);
        $this->send($payload)->assertStatus(400);
    }
}
