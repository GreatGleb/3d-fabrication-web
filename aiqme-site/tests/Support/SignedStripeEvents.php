<?php

namespace Tests\Support;

use App\Domain\Payments\Models\PaymentRequest;
use Illuminate\Testing\TestResponse;
use Stripe\Util\ApiVersion;

/**
 * Подписанные фикстуры webhook Stripe (§5.5, уровень L): тело события и заголовок Stripe-Signature t=…,v1=… — HMAC-SHA256
 * от "{t}.{payload}" явно фиктивным секретом (не whsec_…); проверяет их настоящий Webhook::constructEvent SDK.
 * Фикстура оплаты берёт intent из запроса (stripe_intent_id сохраняет сам тест, как pi_local эксперимента).
 */
trait SignedStripeEvents
{
    protected const WEBHOOK_SECRET = 'test-webhook-secret-not-real';

    protected function useWebhookSecret(): void
    {
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    /**
     * PaymentIntent запроса: деньги получены полностью, заряд ch_test_<public_id>.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function intentObject(PaymentRequest $request, array $overrides = []): array
    {
        $request = $request->fresh();

        return array_replace([
            'id' => $request->stripe_intent_id,
            'object' => 'payment_intent',
            'amount' => $request->amount,
            'amount_received' => $request->amount,
            'currency' => 'eur',
            'status' => 'succeeded',
            'latest_charge' => 'ch_test_'.$request->public_id,
            'livemode' => false,
            'metadata' => ['request_id' => $request->public_id, 'order_id' => (string) $request->order_id],
        ], $overrides);
    }

    /**
     * Тело события (версия API endpoint §5.5 по умолчанию).
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $overrides  поля события (api_version, livemode, pending_webhooks)
     */
    protected function eventPayload(string $id, string $type, array $object, array $overrides = []): string
    {
        return json_encode(array_replace([
            'id' => $id,
            'object' => 'event',
            'api_version' => ApiVersion::CURRENT,
            'created' => time(),
            'livemode' => false,
            'pending_webhooks' => 1,
            'type' => $type,
            'data' => ['object' => $object],
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    /**
     * payment_intent.succeeded по запросу.
     *
     * @param  array<string, mixed>  $overrides  поля intent
     */
    protected function succeededPayload(PaymentRequest $request, string $eventId, array $overrides = []): string
    {
        return $this->eventPayload($eventId, 'payment_intent.succeeded', $this->intentObject($request, $overrides));
    }

    /** POST /webhooks/stripe; $secret = null — без заголовка подписи. */
    protected function sendStripe(string $payload, ?string $secret = self::WEBHOOK_SECRET, ?int $timestamp = null): TestResponse
    {
        $timestamp ??= time();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_STRIPE_SIGNATURE'] = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        }

        return $this->call('POST', '/webhooks/stripe', [], [], [], $server, $payload);
    }
}
