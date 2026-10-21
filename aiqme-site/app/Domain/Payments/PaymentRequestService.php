<?php

namespace App\Domain\Payments;

use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Order;
use Stripe\ErrorObject;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;

/**
 * Платёжные запросы со Stripe (§3.3, W-16) поверх модели и requestPayment W-13: PaymentIntent при первом открытии
 * страницы оплаты клиентом (транзакция 1 pending → creating, вызов Stripe вне транзакции, транзакция 2 creating →
 * pending с intent_id), продолжение того же intent, отмена запроса по автомату (с intent — через cancelling), отмена
 * запросов отменённого заказа после commit (§3.4), неизвестный исход и «проверить статус в Stripe». Ни один вызов
 * Stripe не выполняется внутри DB::transaction. Оплату записывает только PaymentRecorder по прочитанному intent.
 */
final class PaymentRequestService
{
    /** Срок ключа идемпотентности Stripe: повтор создания — только в эти часы от intent_first_attempt_at (§2.5). */
    public const RETRY_HOURS = 24;

    /** Статусы, из которых Stripe отменяет PaymentIntent. */
    private const CANCELLABLE = [
        PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
        PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
        PaymentIntent::STATUS_REQUIRES_ACTION,
        PaymentIntent::STATUS_REQUIRES_CAPTURE,
    ];

    public function __construct(
        private readonly StripeInstallments $stripe,
        private readonly PaymentRecorder $recorder,
        private readonly OrderWorkflow $workflow,
    ) {}

    /**
     * Страница оплаты (права на заказ проверяет вызывающий, §6.2). Администратор видит её без Payment Element — intent не
     * создаётся; возврат браузера с redirect_status succeeded|processing — «обрабатывается» без записи и без Stripe.
     *
     * @return array{status: 'admin'|'processing'|'checking'|'paid'|'closed'|'unavailable'|'form', client_secret?: string}
     */
    public function checkout(PaymentRequest $request, ?User $user, bool $returned = false): array
    {
        $request->refresh();
        if ($user?->is_admin) {
            return ['status' => 'admin'];
        }
        if ($returned) {
            return ['status' => 'processing'];
        }
        if ($request->state === PaymentRequest::CREATING) {
            return ['status' => 'checking'];
        }
        if ($request->state === PaymentRequest::PAID) {
            return ['status' => 'paid'];
        }
        if ($request->state !== PaymentRequest::PENDING || $this->orderCancelled($request)) {
            return ['status' => 'closed'];
        }
        if (! StripeInstallments::configured()) {
            return ['status' => 'unavailable'];
        }
        if ($request->stripe_intent_id !== null) {
            return $this->continueIntent($request->stripe_intent_id);
        }

        $claimed = DB::transaction(function () use ($request): ?PaymentRequest {
            [$request, $cancelled] = $this->lock($request);
            if ($request->state !== PaymentRequest::PENDING || $request->stripe_intent_id !== null || $cancelled) {
                return null;
            }
            $request->update([
                'state' => PaymentRequest::CREATING,
                'idempotency_key' => 'aiqme-request-'.$request->public_id,
                'intent_first_attempt_at' => $request->intent_first_attempt_at ?? now(),
            ]);

            return $request;
        });
        if ($claimed === null) {
            return $this->checkout($request, $user);
        }

        try {
            $intent = $this->stripe->create($claimed);
        } catch (ApiErrorException) {
            // Неизвестный исход (таймаут, 5xx, 4xx): запрос остаётся creating, второй intent не создаётся.
            return ['status' => 'checking'];
        }

        return $this->attach($claimed, $intent) === PaymentRequest::PENDING
            ? ['status' => 'form', 'client_secret' => (string) $intent->client_secret]
            : ['status' => 'closed'];
    }

    /**
     * Отмена запроса администратором: без intent — pending → void без Stripe (W-13); с intent — pending → cancelling,
     * отмена intent после commit, cancelling → void; creating — сначала поиск в Stripe по metadata.request_id.
     *
     * @return string итог для администратора: void | paid | error
     */
    public function cancel(User $admin, PaymentRequest $request): string
    {
        Gate::forUser($admin)->authorize('admin');
        $request->refresh();
        if ($request->state === PaymentRequest::PENDING && $request->stripe_intent_id === null) {
            $this->workflow->cancelPaymentRequest($admin, $request);

            return PaymentRequest::VOID;
        }
        if ($request->state === PaymentRequest::CREATING) {
            return $this->cancelCreating($request);
        }
        $this->require($request->state === PaymentRequest::PENDING, 'request_not_cancellable');
        $moved = DB::transaction(function () use ($request): bool {
            [$request] = $this->lock($request);
            if ($request->state !== PaymentRequest::PENDING || $request->stripe_intent_id === null) {
                return false;
            }
            $request->update(['state' => PaymentRequest::CANCELLING]);

            return true;
        });
        $this->require($moved, 'request_not_cancellable');

        return $this->cancelIntent($request->refresh());
    }

    /**
     * «Проверить статус в Stripe» (§3.3): creating — поиск по metadata.request_id, «не найден» в пределах 24 часов —
     * повтор создания с тем же ключом, позже — creating → void с needs_review; cancelling — retrieve: canceled → void,
     * succeeded с latest_charge — оплата, отменяемый статус — повтор отмены; pending с intent — retrieve и запись оплаты.
     *
     * @return string итог для администратора: pending | void | paid | unchanged | error
     */
    public function checkStatus(User $admin, PaymentRequest $request): string
    {
        Gate::forUser($admin)->authorize('admin');
        $request->refresh();
        $this->require(in_array($request->state, [PaymentRequest::CREATING, PaymentRequest::CANCELLING], true)
            || ($request->state === PaymentRequest::PENDING && $request->stripe_intent_id !== null), 'request_not_checkable');
        if (! StripeInstallments::configured()) {
            return 'error';
        }

        if ($request->state === PaymentRequest::CREATING) {
            try {
                $intent = $this->stripe->find($request);
                if ($intent === null && $request->intent_first_attempt_at?->gt(now()->subHours(self::RETRY_HOURS))) {
                    $intent = $this->stripe->create($request);
                }
            } catch (ApiErrorException) {
                return 'error';
            }
            if ($intent === null) {
                return $this->voidCreating($request, review: true);
            }

            return $this->attach($request, $intent);
        }

        try {
            $intent = $this->stripe->retrieve($request->stripe_intent_id);
        } catch (ApiErrorException) {
            return 'error';
        }
        if (PaymentRecorder::captured($this->recorder->recordCapture($request, $intent))) {
            return PaymentRequest::PAID;
        }
        if ($request->state === PaymentRequest::PENDING) {
            return 'unchanged';
        }
        if ($intent->status === PaymentIntent::STATUS_CANCELED) {
            return $this->voidCancelling($request);
        }

        return in_array($intent->status, self::CANCELLABLE, true) ? $this->cancelIntent($request) : 'error';
    }

    /**
     * После commit отмены заказа (§3.4): запросы в cancelling (были pending с intent) — отмена intent и void; в creating —
     * проверка в Stripe (найден — pending → cancelling → void, не найден — void). Stripe недоступен — состояние остаётся
     * до кнопки «проверить статус в Stripe».
     */
    public function cancelForCancelledOrder(Order $order): void
    {
        $requests = PaymentRequest::where('order_id', $order->id)
            ->whereIn('state', [PaymentRequest::CREATING, PaymentRequest::CANCELLING])
            ->orderBy('id')
            ->get();
        foreach ($requests as $request) {
            $request->state === PaymentRequest::CREATING ? $this->cancelCreating($request) : $this->cancelIntent($request);
        }
    }

    /** Повторное открытие: тот же intent (retrieve вне транзакции); succeeded или processing — «обрабатывается». */
    private function continueIntent(string $intentId): array
    {
        try {
            $intent = $this->stripe->retrieve($intentId);
        } catch (ApiErrorException) {
            return ['status' => 'unavailable'];
        }

        return match ($intent->status) {
            PaymentIntent::STATUS_SUCCEEDED, PaymentIntent::STATUS_PROCESSING => ['status' => 'processing'],
            PaymentIntent::STATUS_CANCELED => ['status' => 'closed'],
            default => ['status' => 'form', 'client_secret' => (string) $intent->client_secret],
        };
    }

    /**
     * Транзакция 2: creating → pending с intent_id, только если запрос ещё creating и заказ не отменён; при отменённом
     * заказе — creating → cancelling и отмена intent по автомату; при void состояние не меняется, intent отменяется в
     * Stripe после commit.
     *
     * @return string pending — client_secret можно выдать; иначе итог отмены (void | paid | error) или состояние запроса
     */
    private function attach(PaymentRequest $request, PaymentIntent $intent): string
    {
        $state = DB::transaction(function () use ($request, $intent): string {
            [$request, $cancelled] = $this->lock($request);
            if ($request->state === PaymentRequest::CREATING) {
                $request->update(['state' => $cancelled ? PaymentRequest::CANCELLING : PaymentRequest::PENDING, 'stripe_intent_id' => $intent->id]);
            }

            return $request->state;
        });
        if ($state === PaymentRequest::VOID) {
            $this->cancelIntent($request->refresh(), $intent->id);
        }

        return $state === PaymentRequest::CANCELLING ? $this->cancelIntent($request->refresh()) : $state;
    }

    /** Отмена запроса в creating: поиск intent; найден — creating → pending → cancelling и отмена, не найден — void. */
    private function cancelCreating(PaymentRequest $request): string
    {
        if (! StripeInstallments::configured()) {
            return 'error';
        }
        try {
            $intent = $this->stripe->find($request);
        } catch (ApiErrorException) {
            return 'error';
        }
        if ($intent === null) {
            return $this->voidCreating($request, review: false);
        }
        $moved = DB::transaction(function () use ($request, $intent): bool {
            [$request] = $this->lock($request);
            if ($request->state !== PaymentRequest::CREATING) {
                return false;
            }
            $request->update(['state' => PaymentRequest::PENDING, 'stripe_intent_id' => $intent->id]);
            $request->update(['state' => PaymentRequest::CANCELLING]);

            return true;
        });

        return $moved ? $this->cancelIntent($request->refresh()) : 'error';
    }

    /**
     * Отмена intent в Stripe (вне транзакции) и итог: canceled — cancelling → void; «уже оплачен»
     * (payment_intent_unexpected_state) — retrieve и при succeeded с latest_charge запись оплаты, при canceled — void;
     * Stripe недоступен — запрос остаётся cancelling.
     */
    private function cancelIntent(PaymentRequest $request, ?string $intentId = null): string
    {
        $intentId ??= $request->stripe_intent_id;
        if (! StripeInstallments::configured()) {
            return 'error';
        }
        try {
            $intent = $this->stripe->cancel($intentId);
        } catch (InvalidRequestException $exception) {
            if ($exception->getStripeCode() !== ErrorObject::CODE_PAYMENT_INTENT_UNEXPECTED_STATE) {
                return 'error';
            }
            try {
                $intent = $this->stripe->retrieve($intentId);
            } catch (ApiErrorException) {
                return 'error';
            }
        } catch (ApiErrorException) {
            return 'error';
        }
        if (PaymentRecorder::captured($this->recorder->recordCapture($request, $intent))) {
            return PaymentRequest::PAID;
        }

        return $intent->status === PaymentIntent::STATUS_CANCELED ? $this->voidCancelling($request) : 'error';
    }

    /** cancelling → void с событием §5.1 «запрос отменён» в той же транзакции (void остаётся void). */
    private function voidCancelling(PaymentRequest $request): string
    {
        return DB::transaction(function () use ($request): string {
            [$request] = $this->lock($request);
            if ($request->state === PaymentRequest::CANCELLING) {
                $request->update(['state' => PaymentRequest::VOID]);
                $this->recorder->event('payment_request.cancelled', $request, 'Payment request cancelled');
            }

            return $request->state === PaymentRequest::VOID ? PaymentRequest::VOID : 'error';
        });
    }

    /** creating → void: intent не найден при отмене или спустя 24 часа (тогда — на сверку, администратор создаёт новый). */
    private function voidCreating(PaymentRequest $request, bool $review): string
    {
        return DB::transaction(function () use ($request, $review): string {
            [$request] = $this->lock($request);
            if ($request->state !== PaymentRequest::CREATING) {
                return 'error';
            }
            $request->update(['state' => PaymentRequest::VOID, ...($review ? ['needs_review' => true, 'review_reason' => 'intent_not_found'] : [])]);
            $this->recorder->event('payment_request.cancelled', $request, 'Payment request cancelled');

            return PaymentRequest::VOID;
        });
    }

    /** @return array{PaymentRequest, bool} запрос под блокировкой (после строки заказа — тот же порядок, что у OrderWorkflow) и «заказ отменён» */
    private function lock(PaymentRequest $request): array
    {
        Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();
        $request = PaymentRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

        return [$request, $this->orderCancelled($request)];
    }

    private function orderCancelled(PaymentRequest $request): bool
    {
        return ProductionState::where('order_id', $request->order_id)->value('stage') === ProductionStage::Cancelled;
    }

    private function require(bool $valid, string $code): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['workflow' => __('orders.workflow.'.$code)]);
        }
    }
}
