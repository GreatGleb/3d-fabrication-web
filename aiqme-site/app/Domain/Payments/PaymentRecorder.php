<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\Channel;
use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lunar\Models\Order;
use Lunar\Models\Transaction;
use Stripe\PaymentIntent;

/**
 * Внутренний сервис записи оплаты (§2.6, §3.3 «Запись»): транзакция Lunar capture только по проверенным данным Stripe —
 * intent, прочитанный через API (W-16), или проверенный webhook (W-28). Публичного метода, маршрута и команды записи нет.
 * Деньги получены — status succeeded, amount_received > 0 и latest_charge. Под блокировкой строки запроса уже записанный
 * заряд (capture с reference = latest_charge) пропускается. Запрос → paid из любого состояния (§3.3); на сверку
 * (needs_review) — валюта не EUR (без capture), другой intent или сумма, отменённый заказ, переплата.
 */
final class PaymentRecorder
{
    public function __construct(private readonly IntegrationEvents $events, private readonly OrderWorkflow $workflow) {}

    /** @return bool true — capture записан этим вызовом */
    public function recordCapture(PaymentRequest $request, PaymentIntent $intent): bool
    {
        $charge = $intent->latest_charge;
        if ($intent->status !== 'succeeded' || (int) $intent->amount_received <= 0 || ! is_string($charge) || $charge === '') {
            return false;
        }

        return DB::transaction(function () use ($request, $intent, $charge): bool {
            $order = Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            $request = PaymentRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (Transaction::where('type', 'capture')->where('reference', $charge)->exists()) {
                return false;
            }
            if (strtoupper((string) $intent->currency) !== 'EUR') {
                $request->update(['needs_review' => true, 'review_reason' => 'currency']);

                return false;
            }
            Transaction::create([
                'order_id' => $order->id,
                'success' => true,
                'type' => 'capture',
                'driver' => 'stripe',
                'amount' => (int) $intent->amount_received,
                'reference' => $charge,
                'status' => 'succeeded',
                'captured_at' => now(),
                'meta' => ['request_id' => $request->public_id, 'intent_id' => $intent->id],
            ]);
            $balance = $this->workflow->balance($order);
            $reason = match (true) {
                $request->stripe_intent_id !== null && $request->stripe_intent_id !== $intent->id => 'intent_mismatch',
                (int) $intent->amount_received !== $request->amount => 'amount_mismatch',
                ProductionState::where('order_id', $order->id)->value('stage') === ProductionStage::Cancelled => 'cancelled_order',
                $balance['paid'] > $balance['total'] => 'overpaid',
                default => null,
            };
            $request->update([
                'state' => PaymentRequest::PAID,
                'stripe_intent_id' => $request->stripe_intent_id ?? $intent->id,
                ...($reason === null ? [] : ['needs_review' => true, 'review_reason' => $reason]),
            ]);
            $this->event('payment.succeeded', $request, 'Payment received', (int) $intent->amount_received, ['charge' => $charge]);
            AuditLog::record('payment.succeeded', $order, Channel::Stripe, null, [
                'public_id' => $request->public_id,
                'amount' => (int) $intent->amount_received,
                'review_reason' => $reason,
            ]);

            return true;
        });
    }

    /**
     * Событие §5.1 платёжного запроса (Payments + Orders + Events, без письма) — в транзакции изменения.
     *
     * @param  array<string, mixed>  $data
     */
    public function event(string $type, PaymentRequest $request, string $summary, ?int $amount = null, array $data = []): void
    {
        $amount ??= $request->amount;
        $this->events->record($type, $request, [
            'number' => OrderMeta::where('order_id', $request->order_id)->value('number'),
            'summary' => Str::limit($summary, 200),
            'amount' => number_format($amount / 100, 2, '.', ''),
            'public_id' => $request->public_id,
            'state' => $request->state,
            ...$data,
        ], mail: false);
    }
}
