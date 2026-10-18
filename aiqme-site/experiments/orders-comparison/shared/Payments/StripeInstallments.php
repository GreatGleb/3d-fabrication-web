<?php

namespace Comparison\Payments;

use App\Models\PaymentRequest;
use App\Services\OrderWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClientInterface;
use Stripe\Webhook;

class StripeInstallments
{
    public function __construct(private OrderWorkflow $workflow) {}

    public function create(PaymentRequest $payment): array
    {
        return DB::transaction(function () use ($payment) {
            $payment = PaymentRequest::lockForUpdate()->findOrFail($payment->id);
            if ($payment->state !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'Request is no longer payable.']);
            }
            $service = app(StripeClientInterface::class)->getService('paymentIntents');
            $intent = $payment->stripe_intent_id
                ? $service->retrieve($payment->stripe_intent_id)
                : $service->create(['amount' => $payment->amount, 'currency' => strtolower($payment->currency), 'capture_method' => 'automatic', 'automatic_payment_methods' => ['enabled' => true], 'metadata' => ['request_id' => $payment->public_id, 'order_id' => (string) $payment->order_id]], ['idempotency_key' => 'aiqme-request-'.$payment->public_id]);
            if ($intent->livemode !== false || $intent->amount !== $payment->amount || strtoupper($intent->currency) !== $payment->currency) {
                throw ValidationException::withMessages(['payment' => 'Test-mode intent amount/currency mismatch.']);
            }
            $payment->update(['stripe_intent_id' => $intent->id]);

            return ['intent_id' => $intent->id, 'client_secret' => $intent->client_secret];
        });
    }

    public function webhook(string $payload, string $signature): void
    {
        $secret = config('services.stripe.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503, 'Test webhook signing secret is not configured.');
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            abort(400, 'Invalid webhook signature or payload.');
        }
        abort_unless($event->livemode === false, 400, 'Live payments are disabled in this experiment.');
        if (! in_array($event->type, ['payment_intent.succeeded', 'payment_intent.payment_failed'], true)) {
            return;
        }
        $intent = $event->data->object;
        $request = PaymentRequest::wherePublicId($intent->metadata->request_id)->firstOrFail();
        $succeeded = $event->type === 'payment_intent.succeeded';
        abort_unless($request->stripe_intent_id === $intent->id && (string) $request->order_id === (string) $intent->metadata->order_id, 422, 'Intent/request association mismatch.');
        abort_unless(! $succeeded || ($intent->status === 'succeeded' && $intent->amount_received === $request->amount && is_string($intent->latest_charge) && str_starts_with($intent->latest_charge, 'ch_')), 422, 'Successful charge is not settled as requested.');
        $this->workflow->recordVerifiedReceipt(['id' => $event->id, 'request' => $request->public_id, 'reference' => $intent->latest_charge ?? 'unsettled_'.$intent->id, 'intent' => $intent->id, 'amount' => $intent->amount, 'currency' => strtoupper($intent->currency), 'status' => $succeeded ? 'succeeded' : 'failed'], 'stripe-installments');
    }
}
