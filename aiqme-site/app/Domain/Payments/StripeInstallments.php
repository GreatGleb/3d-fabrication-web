<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Models\PaymentRequest;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClientInterface;

/**
 * Граница Stripe SDK (§2.6, §3.3): PaymentIntent платёжного запроса — автоматическое списание (manual capture не
 * используется), только карта (D-33), сумма и валюта — только из payment_requests, metadata request_id/order_id,
 * ключ идемпотентности aiqme-request-<public_id>. Каждый метод — сетевой вызов: вызывать только вне транзакции БД
 * (PaymentRequestService), ошибки — ApiErrorException SDK (таймаут — ApiConnectionException).
 */
final class StripeInstallments
{
    public function __construct(private readonly StripeClientInterface $client) {}

    /**
     * Ключи заданы; live-ключ вне рабочей среды не принимается (§2.6: live — только в production).
     */
    public static function configured(): bool
    {
        $key = (string) config('services.stripe.key');
        $secret = (string) config('services.stripe.secret');

        return $key !== '' && $secret !== '' && (app()->isProduction() || ! str_contains($key.$secret, '_live_'));
    }

    /** @throws ApiErrorException */
    public function create(PaymentRequest $request): PaymentIntent
    {
        return $this->intents()->create([
            'amount' => $request->amount,
            'currency' => strtolower($request->currency),
            'capture_method' => 'automatic',
            'payment_method_types' => ['card'],
            'metadata' => ['request_id' => $request->public_id, 'order_id' => (string) $request->order_id],
        ], ['idempotency_key' => $request->idempotency_key]);
    }

    /** @throws ApiErrorException */
    public function retrieve(string $intentId): PaymentIntent
    {
        return $this->intents()->retrieve($intentId);
    }

    /** @throws ApiErrorException «уже оплачен» — InvalidRequestException с кодом payment_intent_unexpected_state */
    public function cancel(string $intentId): PaymentIntent
    {
        return $this->intents()->cancel($intentId);
    }

    /**
     * Intent запроса по metadata.request_id (Search API отстаёт до минуты — «не найден» не окончательный ответ).
     *
     * @throws ApiErrorException
     */
    public function find(PaymentRequest $request): ?PaymentIntent
    {
        $found = $this->intents()->search(['query' => "metadata['request_id']:'{$request->public_id}'", 'limit' => 1]);

        return $found->first();
    }

    private function intents(): PaymentIntentService
    {
        return new PaymentIntentService($this->client);
    }
}
