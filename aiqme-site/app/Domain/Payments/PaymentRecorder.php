<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\Channel;
use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Invoices\IssueInvoice;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lunar\Models\Order;
use Lunar\Models\Transaction;
use Stripe\PaymentIntent;

/**
 * Внутренний сервис записи оплаты и возврата (§2.6, §3.3 «Запись», «Возвраты»): транзакции Lunar capture и refund только
 * по проверенным данным Stripe — intent, прочитанный через API (W-16), или проверенный webhook (W-28). Публичного
 * метода, маршрута и команды записи нет. Деньги получены — status succeeded, amount_received > 0 и latest_charge. Под
 * блокировкой строк заказа и запроса: заряд, уже записанный по этому запросу, — повтор без эффекта; по другому запросу —
 * без capture, на сверку; валюта не EUR — без capture, на сверку. Иначе capture на amount_received, запрос → paid из
 * любого состояния (§3.3); на сверку (needs_review с review_reason) — другой intent (пустой — только у creating),
 * order_id, сумма, отменённый заказ; переплата (оплачено за вычетом возвратов больше цены) — overpaid при любом исходе.
 * После записи capture и refund — проверка выпуска счёта (IssueInvoice после commit, W-17).
 */
final class PaymentRecorder
{
    /** Денег нет: status не succeeded, amount_received = 0 или нет latest_charge. */
    public const NO_MONEY = 'no_money';

    /** Заряд уже записан по этому запросу. */
    public const ALREADY_RECORDED = 'already_recorded';

    /** Без capture, запрос на сверке: заряд другого запроса или валюта не EUR. */
    public const NOT_RECORDED = 'not_recorded';

    public const CAPTURED = 'captured';

    /** Capture записан, запрос на сверке. */
    public const CAPTURED_FOR_REVIEW = 'captured_for_review';

    public function __construct(private readonly IntegrationEvents $events, private readonly OrderWorkflow $workflow) {}

    public static function captured(string $result): bool
    {
        return in_array($result, [self::CAPTURED, self::CAPTURED_FOR_REVIEW], true);
    }

    /** @return self::* исход записи */
    public function recordCapture(PaymentRequest $request, PaymentIntent $intent): string
    {
        $charge = $intent->latest_charge;
        if ($intent->status !== 'succeeded' || (int) $intent->amount_received <= 0 || ! is_string($charge) || $charge === '') {
            return self::NO_MONEY;
        }

        return DB::transaction(function () use ($request, $intent, $charge): string {
            $order = Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            $request = PaymentRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $recorded = Transaction::where('type', 'capture')->where('reference', $charge)->first();
            if ($recorded !== null && ($recorded->meta['request_id'] ?? null) === $request->public_id) {
                return self::ALREADY_RECORDED;
            }
            if ($recorded !== null || strtoupper((string) $intent->currency) !== 'EUR') {
                $this->review($request, $recorded !== null ? 'charge_of_another_request' : 'currency', ['charge' => $charge]);

                return self::NOT_RECORDED;
            }
            $received = (int) $intent->amount_received;
            Transaction::create([
                'order_id' => $order->id,
                'success' => true,
                'type' => 'capture',
                'driver' => 'stripe',
                'amount' => $received,
                'reference' => $charge,
                'status' => 'succeeded',
                'captured_at' => now(),
                'meta' => ['request_id' => $request->public_id, 'intent_id' => $intent->id],
            ]);
            $this->issueInvoiceAfterCommit($order);
            $balance = $this->workflow->balance($order);
            $reason = match (true) {
                $balance['paid'] > $balance['total'] => 'overpaid',
                $request->stripe_intent_id === null ? $request->state !== PaymentRequest::CREATING : $request->stripe_intent_id !== $intent->id => 'intent_mismatch',
                (string) ($intent->metadata['order_id'] ?? '') !== (string) $order->id => 'order_mismatch',
                (int) $intent->amount !== $request->amount || $received !== $request->amount => 'amount_mismatch',
                ProductionState::where('order_id', $order->id)->value('stage') === ProductionStage::Cancelled => 'cancelled_order',
                default => null,
            };
            $intentFree = $request->stripe_intent_id === null && ! PaymentRequest::where('stripe_intent_id', $intent->id)->exists();
            $request->update([
                'state' => PaymentRequest::PAID,
                ...($intentFree ? ['stripe_intent_id' => $intent->id] : []),
            ]);
            $this->event('payment.succeeded', $request, 'Payment received', $received, ['charge' => $charge, 'review_reason' => $reason]);
            AuditLog::record('payment.succeeded', $order, Channel::Stripe, null, [
                'public_id' => $request->public_id,
                'amount' => $received,
                'review_reason' => $reason,
            ]);
            if ($reason === null) {
                return self::CAPTURED;
            }
            $this->review($request, $reason, ['charge' => $charge]);

            return self::CAPTURED_FOR_REVIEW;
        });
    }

    /**
     * charge.refunded (§3.3 «Возвраты»): refund = amount_refunded (накопительный) − уже записанные refund этого заряда,
     * под блокировкой строк заказа и запроса capture; разница ≤ 0 — без эффекта.
     *
     * @return bool true — refund записан этим вызовом
     */
    public function recordRefund(Transaction $capture, int $amountRefunded): bool
    {
        return DB::transaction(function () use ($capture, $amountRefunded): bool {
            $order = Order::whereKey($capture->order_id)->lockForUpdate()->firstOrFail();
            $request = PaymentRequest::where('public_id', $capture->meta['request_id'] ?? null)->lockForUpdate()->first();
            $refunded = (int) Transaction::where('type', 'refund')->where('reference', $capture->reference)->sum('amount');
            $amount = $amountRefunded - $refunded;
            if ($amount <= 0) {
                return false;
            }
            Transaction::create([
                'order_id' => $order->id,
                'parent_transaction_id' => $capture->id,
                'success' => true,
                'type' => 'refund',
                'driver' => 'stripe',
                'amount' => $amount,
                'reference' => $capture->reference,
                'status' => 'refunded',
                'meta' => ['request_id' => $capture->meta['request_id'] ?? null],
            ]);
            $this->issueInvoiceAfterCommit($order);
            $this->event('payment.refunded', $request ?? $order, 'Refund recorded', $amount, ['charge' => $capture->reference]);
            AuditLog::record('payment.refunded', $order, Channel::Stripe, null, ['charge' => $capture->reference, 'amount' => $amount]);

            return true;
        });
    }

    /** Единственная точка вызова issueIfDue после оплаты и возврата (§3.5): задание очереди после commit записи. */
    private function issueInvoiceAfterCommit(Order $order): void
    {
        IssueInvoice::dispatch($order->id)->afterCommit();
    }

    /**
     * Запрос на сверку (§3.3 «Аномалии»): флаг needs_review с причиной, событие §5.1 «на сверку» и журнал.
     *
     * @param  array<string, mixed>  $data
     */
    public function review(PaymentRequest $request, string $reason, array $data = []): void
    {
        $request->update(['needs_review' => true, 'review_reason' => $reason]);
        $this->event('payment.needs_review', $request, 'Payment needs review: '.$reason, null, ['review_reason' => $reason, ...$data]);
        AuditLog::record('payment.needs_review', Order::findOrFail($request->order_id), Channel::Stripe, null, [
            'public_id' => $request->public_id,
            'review_reason' => $reason,
        ]);
    }

    /**
     * Событие §5.1 оплаты и платёжного запроса (Payments + Orders + Events, без письма) — в транзакции изменения.
     * Сущность — запрос или заказ (возврат по capture без запроса).
     *
     * @param  array<string, mixed>  $data
     */
    public function event(string $type, Model $entity, string $summary, ?int $amount = null, array $data = []): void
    {
        $request = $entity instanceof PaymentRequest ? $entity : null;
        $orderId = $request?->order_id ?? ($entity instanceof Order ? $entity->id : null);
        $amount ??= $request?->amount;
        $this->events->record($type, $entity, [
            'number' => $orderId === null ? null : OrderMeta::where('order_id', $orderId)->value('number'),
            'summary' => Str::limit($summary, 200),
            'amount' => $amount === null ? null : number_format($amount / 100, 2, '.', ''),
            'public_id' => $request?->public_id,
            'state' => $request?->state,
            ...$data,
        ], mail: false);
    }
}
