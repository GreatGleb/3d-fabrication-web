<?php

namespace App\Domain\Orders;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\Channel;
use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Invoices\IssueInvoice;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Orders\Models\OrderMessage;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\PriceRevision;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Payments\Models\PaymentRequest;
use App\Domain\Payments\PaymentRequestService;
use App\Models\OrderCharge;
use App\Models\StlPart;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lunar\Base\ValueObjects\Cart\TaxBreakdown;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;

/**
 * Правила заказа (§3.2–§3.4, W-13) — единая граница для админки, кабинета и гостевой страницы: подтверждение и изменение
 * цены (цена — сумма всех строк, включая служебные OrderCharge; строка delivery одна на заказ), платёжные запросы без
 * Stripe (создание в pending без intent, отмена pending без intent → void), стадии производства и трек, отмена заказа,
 * сообщения администратора и ответы клиента. Права проверяются здесь: действия администратора — Gate "admin", ответ
 * клиента — владелец заказа (OrderAccess). Каждое изменение — под блокировкой строки заказа, с событием outbox §5.1
 * (только Sheets, без письма) и записью журнала в той же транзакции. Оплату этот сервис не записывает: capture создаёт
 * только внутренний сервис записи по проверенным данным Stripe (W-16, W-28).
 */
final class OrderWorkflow
{
    /** Верхний предел цены одной строки, центы (как в эксперименте). */
    public const MAX_LINE_CENTS = 10_000_000;

    public function __construct(
        private readonly IntegrationEvents $events,
        private readonly OrderAccess $access,
    ) {}

    /**
     * Подтверждение цены заказа в стадии review: цена каждой строки (StlPart > 0, плата fee ≥ 0) и при доставке — строка
     * delivery. Заказ получает confirmed_at, стадию confirmed и ревизию 1.
     *
     * @param  array<int, int>  $linePrices  цена в центах по id строки заказа (все строки, кроме delivery)
     * @param  ?int  $delivery  цена строки доставки в центах; null — без доставки
     */
    public function confirmPrice(User $admin, Order $order, array $linePrices, ?int $delivery): Order
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($admin, $order, $linePrices, $delivery): Order {
            [$order, $state] = $this->lock($order);
            $this->requireNotCancelled($state);
            $this->require($state->stage === ProductionStage::Review, 'already_confirmed');
            $revision = $this->setPrices($admin, $order, $state, $linePrices, $delivery, null, null);
            $state->update(['stage' => ProductionStage::Confirmed, 'confirmed_at' => now()]);
            $this->recordOrderEvent('order.price_confirmed', $order, $admin, 'Price confirmed', $order->total->value, ['revision' => $revision->revision]);

            return $order->fresh();
        });
    }

    /**
     * Изменение подтверждённой цены до начала производства: с основанием и ссылкой на согласование, без открытых платёжных
     * запросов и до выпуска счёта (W-17); новая цена не ниже оплаченного и оставляет остаток 0 или ≥ 0,50 EUR. Строка delivery меняет цену (или
     * создаётся), удалить её нельзя.
     *
     * @param  array<int, int>  $linePrices  цена в центах по id строки заказа (все строки, кроме delivery)
     */
    public function changePrice(User $admin, Order $order, array $linePrices, ?int $delivery, string $reason, string $agreement): Order
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($admin, $order, $linePrices, $delivery, $reason, $agreement): Order {
            [$order, $state] = $this->lock($order);
            $this->requireNotCancelled($state);
            $this->require($state->stage !== ProductionStage::Review, 'not_confirmed');
            $this->require($state->stage === ProductionStage::Confirmed && $state->started_at === null, 'price_locked');
            $this->require(trim($reason) !== '' && trim($agreement) !== '', 'reason_required');
            $this->require(! $this->openRequests($order)->exists(), 'open_request');
            $this->require(! Invoice::where('order_id', $order->id)->exists(), 'invoice_issued');
            $revision = $this->setPrices($admin, $order, $state, $linePrices, $delivery, trim($reason), trim($agreement));
            $this->recordOrderEvent('order.price_changed', $order, $admin, 'Price changed (revision '.$revision->revision.')', $order->total->value, [
                'revision' => $revision->revision,
                'reason' => $revision->reason,
                'agreement' => $revision->agreement,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Платёжный запрос на произвольную сумму (аванс, остаток или всё): после подтверждения цены, не для отменённого заказа;
     * сумма ≥ 0,50 EUR и ≤ долг − открытые запросы, остаток после запроса 0 или ≥ 0,50 EUR. Создаётся сразу в pending без
     * обращения к Stripe (intent — при первом открытии страницы оплаты, W-16).
     */
    public function requestPayment(User $admin, Order $order, int $amount): PaymentRequest
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($order, $amount): PaymentRequest {
            [$order, $state] = $this->lock($order);
            $this->requireNotCancelled($state);
            $this->require($state->confirmed_at !== null, 'not_confirmed');
            $available = $this->balance($order)['due'] - (int) $this->openRequests($order)->sum('amount');
            $this->require($amount >= PaymentRequest::MINIMUM, 'request_too_small');
            $this->require($amount <= $available, 'request_too_large');
            $rest = $available - $amount;
            $this->require($rest === 0 || $rest >= PaymentRequest::MINIMUM, 'request_rest_too_small');
            $request = PaymentRequest::create([
                'public_id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'amount' => $amount,
                'currency' => 'EUR',
                'state' => PaymentRequest::PENDING,
            ]);
            $this->recordRequestEvent('payment_request.created', $order, $request, 'Payment request created');

            return $request;
        });
    }

    /** Отмена запроса без intent: pending → void в одной транзакции, без Stripe. Запрос с intent — PaymentRequestService. */
    public function cancelPaymentRequest(User $admin, PaymentRequest $request): PaymentRequest
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($request): PaymentRequest {
            [$order] = $this->lock(Order::findOrFail($request->order_id));
            $request = PaymentRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->require($request->state === PaymentRequest::PENDING && $request->stripe_intent_id === null, 'request_not_cancellable');
            $request->update(['state' => PaymentRequest::VOID]);
            $this->recordRequestEvent('payment_request.cancelled', $order, $request, 'Payment request cancelled');

            return $request;
        });
    }

    /**
     * Начало производства (confirmed → in_production) — можно без оплаты (В-003); при непогашенном долге — только
     * с явным подтверждением администратора ($debtConfirmed). После начала цена и состав заблокированы.
     */
    public function startProduction(User $admin, Order $order, bool $debtConfirmed = false): void
    {
        $this->authorize($admin);

        DB::transaction(function () use ($admin, $order, $debtConfirmed): void {
            [$order, $state] = $this->lock($order);
            $this->requireNotCancelled($state);
            $this->require($state->stage !== ProductionStage::Review, 'not_confirmed');
            $this->require($state->stage === ProductionStage::Confirmed, 'stage_invalid');
            $this->require($debtConfirmed || $this->balance($order)['due'] === 0, 'debt_unconfirmed');
            $state->update(['stage' => ProductionStage::InProduction, 'started_at' => now()]);
            $this->recordStage($order, $admin, $state);
        });
    }

    /**
     * Следующая стадия после начала производства: in_production → quality_check → ready → shipped (доставка, нужны
     * перевозчик и трек) → completed; самовывоз — ready → completed. Только на одну стадию вперёд.
     */
    public function advance(User $admin, Order $order, ProductionStage $to): void
    {
        $this->authorize($admin);

        DB::transaction(function () use ($admin, $order, $to): void {
            [$order, $state] = $this->lock($order);
            $this->requireNotCancelled($state);
            $from = $state->stage;
            $this->require(! in_array($from, [ProductionStage::Review, ProductionStage::Confirmed], true), 'stage_invalid');
            $this->require($from->next($this->isPickup($order)) === $to, 'stage_invalid');
            $this->require($to !== ProductionStage::Shipped || ($state->carrier !== null && $state->tracking_number !== null), 'tracking_required');
            $state->update(['stage' => $to]);
            $this->recordStage($order, $admin, $state);
        });
    }

    /** Перевозчик и трек-номер (вводятся вручную, видны клиенту) — только при доставке, до завершения или отмены. */
    public function setTracking(User $admin, Order $order, string $carrier, string $trackingNumber): void
    {
        $this->authorize($admin);

        DB::transaction(function () use ($admin, $order, $carrier, $trackingNumber): void {
            [$order, $state] = $this->lock($order);
            $this->requireNotCancelled($state);
            $this->require(! $this->isPickup($order), 'tracking_pickup');
            $this->require($state->stage !== ProductionStage::Completed, 'stage_invalid');
            $carrier = trim($carrier);
            $trackingNumber = trim($trackingNumber);
            $this->require($carrier !== '' && $trackingNumber !== '' && mb_strlen($carrier) <= 255 && mb_strlen($trackingNumber) <= 255, 'tracking_required');
            $state->update(['carrier' => $carrier, 'tracking_number' => $trackingNumber]);
            $this->recordOrderEvent('order.tracking_set', $order, $admin, 'Tracking: '.$carrier.' '.$trackingNumber, null, [
                'carrier' => $carrier,
                'tracking_number' => $trackingNumber,
            ]);
        });
    }

    /**
     * Отмена заказа администратором с причиной из любой стадии до completed. Открытые запросы pending без intent — void
     * в той же транзакции, pending с intent — cancelling; после commit PaymentRequestService отменяет их intent в Stripe и
     * проверяет запросы в creating (§3.4, W-16). Деньги не возвращаются.
     */
    public function cancel(User $admin, Order $order, string $reason): void
    {
        $this->authorize($admin);

        DB::transaction(function () use ($admin, $order, $reason): void {
            [$order, $state] = $this->lock($order);
            $this->require(! in_array($state->stage, [ProductionStage::Completed, ProductionStage::Cancelled], true), 'not_cancellable');
            $this->require(trim($reason) !== '', 'reason_required');
            $requests = PaymentRequest::where('order_id', $order->id)
                ->where('state', PaymentRequest::PENDING)
                ->lockForUpdate()
                ->get();
            foreach ($requests as $request) {
                if ($request->stripe_intent_id !== null) {
                    $request->update(['state' => PaymentRequest::CANCELLING]);

                    continue;
                }
                $request->update(['state' => PaymentRequest::VOID]);
                $this->recordRequestEvent('payment_request.cancelled', $order, $request, 'Payment request cancelled (order cancelled)');
            }
            $state->update(['stage' => ProductionStage::Cancelled, 'cancel_reason' => trim($reason)]);
            $this->recordOrderEvent('order.cancelled', $order, $admin, 'Order cancelled', null, ['reason' => trim($reason)]);
        });

        // Вызовы Stripe — только после commit (§2.6); сервис зависит от этого, поэтому берётся из контейнера здесь.
        app(PaymentRequestService::class)->cancelForCancelledOrder($order);
    }

    /** Сообщение администратора клиенту по заказу (§1.3): запись и журнал. */
    public function postAdminMessage(User $admin, Order $order, string $body): OrderMessage
    {
        $this->authorize($admin);
        $body = trim($body);
        $this->require($body !== '' && mb_strlen($body) <= 5000, 'message_invalid');

        return DB::transaction(function () use ($admin, $order, $body): OrderMessage {
            $message = OrderMessage::create(['order_id' => $order->id, 'user_id' => $admin->id, 'kind' => OrderMessage::ADMIN, 'body' => $body]);
            AuditLog::record('order.message_posted', $order, Channel::Web, $admin, ['message_id' => $message->id]);

            return $message;
        });
    }

    /**
     * Перевыпуск гостевой ссылки (§3.3, §6.2, W-14): новый токен, в БД — только его SHA-256, прежняя ссылка перестаёт
     * работать. Открытый токен возвращается один раз для показа администратору и нигде не записывается (журнал — без него).
     * У заказа клиента гостевой ссылки нет.
     */
    public function reissueGuestLink(User $admin, Order $order): string
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($admin, $order): string {
            [$order] = $this->lock($order);
            $meta = OrderMeta::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $this->require($order->user_id === null && $meta->guest_token_hash !== null, 'not_guest');
            $token = OrderSubmission::guestToken();
            $meta->update(['guest_token_hash' => hash('sha256', $token)]);
            AuditLog::record('order.guest_link_reissued', $order, Channel::Web, $admin);

            return $token;
        });
    }

    /**
     * Ответ клиента (agree | question | cancel_request, §1.3): только записывается и виден администратору — ничего не
     * отменяет и не меняет. Автор — владелец заказа или гость с правом на заказ в сессии (W-15); иначе отказ без записи.
     */
    public function recordCustomerReply(Order $order, string $kind, ?User $actor): OrderMessage
    {
        if (! $this->access->isCustomer($order, $actor)) {
            throw new AuthorizationException;
        }
        $this->require(in_array($kind, OrderMessage::REPLIES, true), 'reply_invalid');

        return DB::transaction(function () use ($order, $kind, $actor): OrderMessage {
            $message = OrderMessage::create(['order_id' => $order->id, 'user_id' => $actor?->id, 'kind' => $kind]);
            AuditLog::record('order.customer_replied', $order, Channel::Web, $actor, ['message_id' => $message->id, 'kind' => $kind]);

            return $message;
        });
    }

    /**
     * Оплата по транзакциям Lunar: оплачено = capture − refund (успешные), долг = цена − оплачено.
     *
     * @return array{total: int, paid: int, due: int, payment_state: 'unpaid'|'partial'|'paid'|'overpaid'}
     */
    public function balance(Order $order): array
    {
        $sum = fn (string $type): int => (int) $order->transactions()->where('type', $type)->where('success', true)->sum('amount');
        $paid = $sum('capture') - $sum('refund');
        $total = $order->total->value;

        return [
            'total' => $total,
            'paid' => $paid,
            'due' => max(0, $total - $paid),
            'payment_state' => match (true) {
                $paid > $total => 'overpaid',
                $paid === $total && $total > 0 => 'paid',
                $paid > 0 => 'partial',
                default => 'unpaid',
            },
        ];
    }

    /**
     * Цены строк, строка delivery, итог заказа и ревизия цены.
     *
     * @param  array<int, int>  $linePrices
     */
    private function setPrices(User $admin, Order $order, ProductionState $state, array $linePrices, ?int $delivery, ?string $reason, ?string $agreement): PriceRevision
    {
        $lines = $order->lines()->with('purchasable')->orderBy('id')->get();
        $isDelivery = fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY;
        $deliveryLine = $lines->first($isDelivery);
        $priced = $lines->reject($isDelivery);

        $ids = array_map('intval', array_keys($linePrices));
        sort($ids);
        $this->require($ids === $priced->modelKeys(), 'every_line');
        foreach ($priced as $line) {
            $cents = $linePrices[$line->id];
            $minimum = $line->purchasable instanceof StlPart ? 1 : 0;
            $this->require(is_int($cents) && $cents >= $minimum && $cents <= self::MAX_LINE_CENTS, 'line_price');
        }
        $this->require($delivery !== null || $deliveryLine === null, 'delivery_required');
        $this->require($delivery === null || ($delivery >= 0 && $delivery <= self::MAX_LINE_CENTS), 'line_price');

        $total = array_sum($linePrices) + ($delivery ?? 0);
        $paid = $this->balance($order)['paid'];
        $this->require($total >= $paid, 'below_paid');
        $this->require($total - $paid === 0 || $total - $paid >= PaymentRequest::MINIMUM, 'rest_too_small');

        $before = $lines->mapWithKeys(fn (OrderLine $line): array => [$line->id => $line->unit_price->value])->all();
        $after = [];
        foreach ($priced as $line) {
            $after[$line->id] = $this->setLinePrice($line, $linePrices[$line->id]);
        }
        if ($deliveryLine !== null) {
            $after[$deliveryLine->id] = $this->setLinePrice($deliveryLine, $delivery);
        } elseif ($delivery !== null) {
            $line = $this->addDeliveryLine($order, $delivery);
            $after[$line->id] = $delivery;
        }
        $order->update(['sub_total' => $total, 'total' => $total]);

        $revision = PriceRevision::create([
            'order_id' => $order->id,
            'actor_id' => $admin->id,
            'revision' => $state->revision + 1,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'agreement' => $agreement,
            'created_at' => now(),
        ]);
        $state->update(['revision' => $revision->revision]);
        $order->refresh();
        // Цена изменилась — проверка выпуска счёта после commit (§3.5, W-17): например, цена снижена до оплаченного.
        IssueInvoice::dispatch($order->id)->afterCommit();

        return $revision;
    }

    private function setLinePrice(OrderLine $line, int $cents): int
    {
        $line->update(['unit_price' => $cents, 'sub_total' => $cents, 'total' => $cents]);
        if ($line->purchasable instanceof OrderCharge) {
            $line->purchasable->update(['amount' => $cents]);
        }

        return $cents;
    }

    /** Строка доставки — OrderLine размещённого заказа (не через корзину), purchasable — OrderCharge вида delivery. */
    private function addDeliveryLine(Order $order, int $cents): OrderLine
    {
        $charge = OrderCharge::create(['order_id' => $order->id, 'kind' => OrderCharge::DELIVERY, 'amount' => $cents]);

        return OrderLine::create([
            'order_id' => $order->id,
            'purchasable_type' => $charge->getMorphClass(),
            'purchasable_id' => $charge->id,
            'type' => $charge->getType(),
            'description' => $charge->getDescription(),
            'option' => $charge->getOption(),
            'identifier' => $charge->getIdentifier(),
            'unit_price' => $cents,
            'unit_quantity' => 1,
            'quantity' => 1,
            'sub_total' => $cents,
            'discount_total' => 0,
            'tax_breakdown' => new TaxBreakdown,
            'tax_total' => 0,
            'total' => $cents,
        ]);
    }

    /** @return Builder<PaymentRequest> */
    private function openRequests(Order $order)
    {
        return PaymentRequest::where('order_id', $order->id)->whereIn('state', PaymentRequest::OPEN);
    }

    private function isPickup(Order $order): bool
    {
        return OrderMeta::where('order_id', $order->id)->value('delivery') === 'pickup';
    }

    /** @return array{Order, ProductionState} заказ и его состояние под блокировкой строк */
    private function lock(Order $order): array
    {
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

        return [$order, ProductionState::where('order_id', $order->id)->lockForUpdate()->firstOrFail()];
    }

    private function recordStage(Order $order, User $admin, ProductionState $state): void
    {
        $this->recordOrderEvent('order.stage_changed', $order, $admin, 'Production stage: '.$state->stage->value, null, ['stage' => $state->stage->value]);
    }

    /**
     * Событие заказа: outbox §5.1 (Orders + Events, без письма) и журнал.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordOrderEvent(string $type, Order $order, User $admin, string $summary, ?int $amount, array $data = []): void
    {
        $this->record($type, $order, $order, $summary, $amount, $data);
        AuditLog::record($type, $order, Channel::Web, $admin, $data + ($amount === null ? [] : ['amount' => $amount]));
    }

    /** Событие платёжного запроса: outbox §5.1 (Payments + Orders + Events, без письма). */
    private function recordRequestEvent(string $type, Order $order, PaymentRequest $request, string $summary): void
    {
        $this->record($type, $request, $order, $summary, $request->amount, ['public_id' => $request->public_id, 'state' => $request->state]);
    }

    /** @param  array<string, mixed>  $data */
    private function record(string $type, Model $entity, Order $order, string $summary, ?int $amount, array $data): void
    {
        $this->events->record($type, $entity, [
            'number' => OrderMeta::where('order_id', $order->id)->value('number'),
            'summary' => Str::limit($summary, 200),
            'amount' => $amount === null ? null : number_format($amount / 100, 2, '.', ''),
            ...$data,
        ], mail: false);
    }

    private function authorize(User $admin): void
    {
        Gate::forUser($admin)->authorize('admin');
    }

    private function requireNotCancelled(ProductionState $state): void
    {
        $this->require($state->stage !== ProductionStage::Cancelled, 'cancelled');
    }

    private function require(bool $valid, string $code): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['workflow' => __('orders.workflow.'.$code)]);
        }
    }
}
