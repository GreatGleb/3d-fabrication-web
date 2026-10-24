<?php

namespace App\Livewire\Admin\Orders;

use App\Domain\Audit\AuditLog;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Orders\Models\OrderMessage;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\PriceRevision;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentRequest;
use App\Domain\Payments\PaymentRequestService;
use App\Models\OrderCharge;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Spatie\Activitylog\Models\Activity;

/**
 * Карточка заказа во вкладке Orders (§1.4, W-14): файлы (скачивание STL — только защищённая выдача W-32), параметры и
 * пожелания, лист задания Bambu, подтверждение и изменение цены по строкам со строкой доставки, платёжные запросы, их
 * отмена и «проверить статус в Stripe» (W-16), ссылка страницы оплаты клиенту, начало производства, стадии, трек,
 * отмена заказа, сообщение клиенту и ответы клиента, перевыпуск гостевой ссылки, история, скачивание счёта (W-17). Все действия — методы
 * OrderWorkflow (W-13) и PaymentRequestService (W-16): правил здесь нет, только перевод EUR в центы и подтверждение
 * опасных действий (отмена, изменение цены, начало производства при долге > 0, §3.4) вторым нажатием.
 * Каждый запрос — только администратору, иначе 404.
 */
class Card extends Component
{
    /** EUR: целые с не более чем двумя знаками после точки. */
    private const EUROS = '/^\d{1,6}(\.\d{1,2})?$/D';

    #[Locked]
    public string $uiLocale;

    #[Locked]
    public int $orderId;

    /** @var array<int, string> цена строки в EUR по id строки заказа (кроме delivery) */
    public array $prices = [];

    /** Цена строки доставки в EUR; пусто — без доставки. */
    public string $delivery = '';

    public string $reason = '';

    public string $agreement = '';

    public string $amount = '';

    public string $carrier = '';

    public string $trackingNumber = '';

    public string $message = '';

    public string $cancelReason = '';

    /** Опасное действие, ждущее подтверждения: cancel | change | start. */
    #[Locked]
    public ?string $confirming = null;

    public bool $showJob = false;

    /** Новая гостевая ссылка — только в ответе на перевыпуск (следующий запрос её сбрасывает, §6.2). */
    #[Locked]
    public ?string $guestLink = null;

    #[Locked]
    public ?string $status = null;

    public function boot(): void
    {
        abort_unless(auth()->user() && Gate::allows('admin'), 404);
    }

    public function hydrate(): void
    {
        app()->setLocale($this->uiLocale);
        $this->guestLink = null;
        $this->status = null;
    }

    public function mount(int $orderId): void
    {
        $this->uiLocale = app()->getLocale();
        $this->orderId = Order::findOrFail($orderId)->id;
        $this->loadForm();
    }

    public function confirmPrice(OrderWorkflow $flow): void
    {
        $this->validatePrices();
        $flow->confirmPrice($this->admin(), $this->order(), $this->lineCents(), $this->deliveryCents());
        $this->done('price_confirmed');
    }

    public function changePrice(OrderWorkflow $flow): void
    {
        $this->validatePrices();
        if ($this->ask('change')) {
            return;
        }
        $flow->changePrice($this->admin(), $this->order(), $this->lineCents(), $this->deliveryCents(), $this->reason, $this->agreement);
        $this->reason = $this->agreement = '';
        $this->done('price_changed');
    }

    public function requestPayment(OrderWorkflow $flow): void
    {
        $this->validate(['amount' => ['required', 'regex:'.self::EUROS]], ['*' => __('admin.orders.invalid_amount')]);
        $flow->requestPayment($this->admin(), $this->order(), self::cents($this->amount));
        $this->amount = '';
        $this->done('request_created');
    }

    /** Отмена запроса по автомату §3.3 (W-16): без intent — сразу void, с intent и в creating — через Stripe. */
    public function cancelRequest(PaymentRequestService $payments, int $requestId): void
    {
        $result = $payments->cancel($this->admin(), $this->paymentRequest($requestId));
        $this->done($result === PaymentRequest::VOID ? 'request_cancelled' : 'stripe_'.$result);
    }

    /** «Проверить статус в Stripe» для creating, cancelling и pending с intent (§3.3). */
    public function checkRequest(PaymentRequestService $payments, int $requestId): void
    {
        $this->done('stripe_'.$payments->checkStatus($this->admin(), $this->paymentRequest($requestId)));
    }

    /** Начало производства; при непогашенном долге — только после подтверждения администратора (§3.4). */
    public function startProduction(OrderWorkflow $flow): void
    {
        $order = $this->order();
        if ($flow->balance($order)['due'] > 0 && $this->ask('start')) {
            return;
        }
        $flow->startProduction($this->admin(), $order, debtConfirmed: $this->confirming === 'start');
        $this->done('stage_changed');
    }

    public function advance(OrderWorkflow $flow, string $stage): void
    {
        $flow->advance($this->admin(), $this->order(), ProductionStage::from($stage));
        $this->done('stage_changed');
    }

    public function setTracking(OrderWorkflow $flow): void
    {
        $flow->setTracking($this->admin(), $this->order(), $this->carrier, $this->trackingNumber);
        $this->done('tracking_saved');
    }

    public function postMessage(OrderWorkflow $flow): void
    {
        $flow->postAdminMessage($this->admin(), $this->order(), $this->message);
        $this->message = '';
        $this->done('message_sent');
    }

    public function cancelOrder(OrderWorkflow $flow): void
    {
        if ($this->ask('cancel')) {
            return;
        }
        $flow->cancel($this->admin(), $this->order(), $this->cancelReason);
        $this->cancelReason = '';
        $this->done('order_cancelled');
    }

    public function back(): void
    {
        $this->confirming = null;
    }

    public function reissueGuestLink(OrderWorkflow $flow): void
    {
        $order = $this->order();
        $token = $flow->reissueGuestLink($this->admin(), $order);
        $meta = OrderMeta::where('order_id', $order->id)->firstOrFail();
        $this->guestLink = route($meta->locale.'.order.guest', [$meta->number, $token]);
    }

    public function toggleJob(): void
    {
        $this->confirming = null;
        $this->showJob = ! $this->showJob;
    }

    public function render(OrderWorkflow $flow): View
    {
        $order = Order::with(['lines.purchasable'])->findOrFail($this->orderId);
        $meta = OrderMeta::where('order_id', $order->id)->firstOrFail();
        $state = ProductionState::where('order_id', $order->id)->firstOrFail();
        $lines = $order->lines->sortBy('id');

        return view('admin.orders-card', [
            'order' => $order,
            'meta' => $meta,
            'state' => $state,
            'parts' => $lines->filter(fn (OrderLine $line): bool => ! $line->purchasable instanceof OrderCharge)->values(),
            'priced' => $lines->reject(fn (OrderLine $line): bool => self::isDelivery($line))->values(),
            'deliveryLine' => $lines->first(fn (OrderLine $line): bool => self::isDelivery($line)),
            'balance' => $flow->balance($order),
            'available' => $flow->availableForRequest($order),
            'requests' => PaymentRequest::where('order_id', $order->id)->orderBy('id')->get(),
            'messages' => OrderMessage::where('order_id', $order->id)->orderBy('id')->get(),
            'revisions' => PriceRevision::where('order_id', $order->id)->orderBy('revision')->get(),
            'history' => Activity::where('log_name', AuditLog::LOG_NAME)->where('subject_type', $order->getMorphClass())
                ->where('subject_id', $order->id)->orderBy('id')->get(),
            'customer' => $order->user_id === null ? null : User::find($order->user_id),
            'pickup' => $meta->delivery === 'pickup',
            'invoice' => app()->environment('demo') ? null : Invoice::where('order_id', $order->id)->first(),
        ]);
    }

    private function validatePrices(): void
    {
        $this->validate([
            'prices.*' => ['required', 'regex:'.self::EUROS],
            'delivery' => ['nullable', 'regex:'.self::EUROS],
        ], ['*' => __('admin.orders.invalid_amount')]);
    }

    /** @return array<int, int> */
    private function lineCents(): array
    {
        return array_map(fn (string $euros): int => self::cents($euros), $this->prices);
    }

    private function deliveryCents(): ?int
    {
        return trim($this->delivery) === '' ? null : self::cents(trim($this->delivery));
    }

    /** Первое нажатие опасного действия только просит подтверждения; true — действие ещё не подтверждено. */
    private function ask(string $action): bool
    {
        if ($this->confirming === $action) {
            return false;
        }
        $this->confirming = $action;

        return true;
    }

    private function done(string $status): void
    {
        $this->confirming = null;
        $this->status = __('admin.orders.done.'.$status);
        $this->loadForm();
    }

    /** Поля форм — из текущего заказа: цены строк, доставка, трек. */
    private function loadForm(): void
    {
        $order = Order::with('lines.purchasable')->findOrFail($this->orderId);
        $state = ProductionState::where('order_id', $order->id)->firstOrFail();
        $euros = fn (int $cents): string => number_format($cents / 100, 2, '.', '');
        $this->prices = [];
        foreach ($order->lines->sortBy('id') as $line) {
            if (self::isDelivery($line)) {
                $this->delivery = $euros($line->unit_price->value);
            } else {
                $this->prices[$line->id] = $euros($line->unit_price->value);
            }
        }
        $this->carrier = (string) $state->carrier;
        $this->trackingNumber = (string) $state->tracking_number;
    }

    private function paymentRequest(int $requestId): PaymentRequest
    {
        return PaymentRequest::where('order_id', $this->orderId)->find($requestId) ?? abort(404);
    }

    private function order(): Order
    {
        return Order::findOrFail($this->orderId);
    }

    private function admin(): User
    {
        /** @var User */
        return auth()->user();
    }

    private static function isDelivery(OrderLine $line): bool
    {
        return $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY;
    }

    /** EUR с не более чем двумя знаками (уже проверено) → целые центы без двоичного округления. */
    private static function cents(string $euros): int
    {
        [$whole, $fraction] = explode('.', $euros.'.');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
