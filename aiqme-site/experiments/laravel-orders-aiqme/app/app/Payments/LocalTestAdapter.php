<?php

namespace App\Payments;

use App\Models\PaymentRequest;
use App\Services\OrderWorkflow;
use Illuminate\Validation\ValidationException;

/** Not Stripe. No network, card data, authorization holds or real payments. */
class LocalTestAdapter
{
    private const SECRET = 'synthetic-local-fixture-never-a-stripe-secret';

    public function __construct(private OrderWorkflow $orders) {}

    public function event(PaymentRequest $request, string $id, string $reference, string $status = 'succeeded'): array
    {
        return ['id' => $id, 'request' => $request->public_id, 'reference' => $reference, 'amount' => $request->amount, 'currency' => $request->currency, 'status' => $status];
    }

    public function sign(array $event): string
    {
        return hash_hmac('sha256', json_encode($event), self::SECRET);
    }

    public function receive(array $event, string $signature): void
    {
        if (! hash_equals($this->sign($event), $signature)) {
            throw ValidationException::withMessages(['signature' => 'Invalid local test signature.']);
        }
        $this->orders->recordVerifiedTestReceipt($event);
    }
}
