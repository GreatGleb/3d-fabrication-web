<?php

namespace App\Services;

use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Order;

/** Shared application boundary for HTTP, console and a future Telegram adapter. */
class OrderWorkflow
{
    public function submit(User $buyer, array $items): Order
    {
        $this->require(count($items) >= 1 && count($items) <= 10, 'Between 1 and 10 STL files required.');
        $baskets = app(BasketService::class);
        $basket = $baskets->open($buyer);
        foreach ($items as $item) {
            $baskets->add($buyer, $basket->id, $item);
        }

        return $baskets->submit($buyer, $basket->id);
    }

    /** Unit prices in integer EUR cents; acceptance reference is synthetic in this probe. */
    public function confirmPrice(User $admin, int $orderId, array $unitPrices, string $reason, string $agreement): Order
    {
        $this->admin($admin);

        return DB::transaction(function () use ($admin, $orderId, $unitPrices, $reason, $agreement) {
            $order = $this->locked($orderId);
            $state = $order->production;
            $this->require(! $state->started_at && $state->state !== 'cancelled', 'Changes are locked after production starts or cancellation.');
            $this->require(trim($reason) !== '' && trim($agreement) !== '', 'Reason and customer agreement reference are required.');
            $this->require(! PaymentRequest::whereOrderId($orderId)->whereState('pending')->exists(), 'Resolve pending payment requests before changing price.');
            $lines = $order->lines;
            $this->require(count($unitPrices) === $lines->count(), 'Every line needs a price.');
            $before = $lines->mapWithKeys(fn ($line) => [$line->id => $line->unit_price->value])->all();
            $total = 0;
            foreach ($lines as $line) {
                $unit = $unitPrices[$line->id] ?? null;
                $this->require(is_int($unit) && $unit > 0 && $unit <= 10000000, 'Invalid server price.');
                $total += $unit * $line->quantity;
            }
            $this->require($total >= $this->balance($order)['paid'], 'Price below captured amount requires a separate refund workflow.');
            foreach ($lines as $line) {
                $unit = $unitPrices[$line->id];
                $line->update(['unit_price' => $unit, 'sub_total' => $unit * $line->quantity, 'total' => $unit * $line->quantity]);
            }
            DB::table('price_revisions')->insert([
                'order_id' => $orderId, 'actor_id' => $admin->id, 'revision' => $state->revision + 1,
                'before' => json_encode($before), 'after' => json_encode($unitPrices),
                'reason' => $reason, 'agreement_reference' => $agreement, 'created_at' => now(),
            ]);
            $state->update(['confirmed_at' => now(), 'revision' => $state->revision + 1, 'state' => 'ready']);
            $order->update(['total' => $total, 'sub_total' => $total, 'status' => 'confirmed']);

            return $order->fresh();
        });
    }

    public function requestPayment(User $admin, int $orderId, int $amount): PaymentRequest
    {
        $this->admin($admin);

        return DB::transaction(function () use ($orderId, $amount) {
            $order = $this->locked($orderId);
            $this->require($order->production->confirmed_at && $order->production->state !== 'cancelled', 'Confirmed active order required.');
            $reserved = PaymentRequest::whereOrderId($orderId)->whereState('pending')->sum('amount');
            $this->require($amount > 0 && $amount <= $this->balance($order)['due'] - $reserved, 'Amount exceeds unreserved balance.');

            return PaymentRequest::create(['order_id' => $orderId, 'public_id' => (string) Str::uuid(), 'amount' => $amount, 'currency' => 'EUR']);
        });
    }

    /** Called only after adapter authentication; no public webhook endpoint in the probe. */
    public function recordVerifiedTestReceipt(array $event): void
    {
        $this->require(app()->environment(['local', 'testing']), 'Test receipts are disabled outside local/testing.');
        $this->recordVerifiedReceipt($event, 'local-test');
    }

    public function recordVerifiedReceipt(array $event, string $driver): void
    {
        $request = PaymentRequest::wherePublicId($event['request'])->firstOrFail();
        DB::transaction(function () use ($event, $request, $driver) {
            $order = $this->locked($request->order_id);
            $request->refresh();
            $digest = hash('sha256', json_encode($event));
            $previous = DB::table('payment_events')->where('event_id', $event['id'])->first();
            if ($previous) {
                $this->require(hash_equals($previous->digest, $digest), 'Event identity collision.');

                return;
            }
            $this->require($event['amount'] === $request->amount && $event['currency'] === $request->currency, 'Provider amount/currency mismatch.');
            $this->require(in_array($driver, ['local-test', 'stripe-installments'], true), 'Unknown adapter.');
            if ($driver === 'local-test') {
                $this->require(str_starts_with($event['reference'], 'test_charge_'), 'Synthetic reference required.');
            } else {
                $this->require($request->stripe_intent_id === ($event['intent'] ?? null), 'Wrong Stripe intent.');
            }
            DB::table('payment_events')->insert(['event_id' => $event['id'], 'request_id' => $request->id, 'digest' => $digest, 'created_at' => now()]);
            if ($event['status'] !== 'succeeded') {
                return;
            }
            if ($request->state === 'paid') {
                $this->require($request->provider_reference === $event['reference'], 'Different charge for already paid request requires reconciliation.');

                return;
            }
            $this->require($order->production->state !== 'cancelled', 'Late payment on cancelled order requires reconciliation.');
            $this->require($request->amount <= $this->balance($order)['due'], 'Overpayment requires reconciliation.');
            $transaction = $order->transactions()->create([
                'type' => 'capture', 'success' => true, 'driver' => $driver,
                'amount' => $request->amount, 'reference' => $event['reference'], 'status' => 'settled',
                'captured_at' => now(), 'meta' => ['request_id' => $request->public_id],
            ]);
            $request->update(['state' => 'paid', 'transaction_id' => $transaction->id, 'provider_reference' => $event['reference']]);
        });
    }

    public function startProduction(User $admin, int $orderId): void
    {
        $this->admin($admin);
        DB::transaction(function () use ($orderId) {
            $order = $this->locked($orderId);
            $this->require($order->production->confirmed_at && $order->production->state === 'ready', 'Price must be confirmed and production not started/cancelled.');
            $order->production->update(['state' => 'in-production', 'started_at' => now()]);
        });
    }

    public function cancel(User $admin, int $orderId): void
    {
        $this->admin($admin);
        DB::transaction(function () use ($orderId) {
            $order = $this->locked($orderId);
            $this->require(! PaymentRequest::whereOrderId($orderId)->whereState('pending')->exists(), 'Resolve pending payment requests first.');
            $order->production->update(['state' => 'cancelled']);
            $order->update(['status' => 'cancelled']);
        });
    }

    public function balance(Order $order): array
    {
        $paid = (int) $order->transactions()->whereType('capture')->whereSuccess(true)->sum('amount');
        $refund = (int) $order->transactions()->whereType('refund')->whereSuccess(true)->sum('amount');
        $net = $paid - $refund;
        $total = $order->total->value;

        return ['total' => $total, 'paid' => $net, 'due' => max(0, $total - $net), 'overpaid' => max(0, $net - $total), 'payment_state' => $net >= $total ? 'paid' : ($net > 0 ? 'partial' : 'unpaid')];
    }

    public function authorizeView(User $user, Order $order): void
    {
        if (! $user->is_admin && $order->user_id !== $user->id) {
            throw new AuthorizationException;
        }
    }

    private function locked(int $id): Order
    {
        return Order::lockForUpdate()->findOrFail($id);
    }

    private function admin(User $user): void
    {
        if (! $user->is_admin) {
            throw new AuthorizationException;
        }
    }

    private function require(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
