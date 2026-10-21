<?php

namespace App\Http\Controllers\Account;

use App\Domain\Orders\Models\OrderMessage;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderAccess;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentRequest;
use App\Http\Controllers\Controller;
use App\Models\StlPart;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Lunar\Models\Order;

/**
 * Карточка заказа для клиента (§1.3, W-15): в кабинете — владельцу, на гостевой странице — по секретной ссылке (§6.2).
 * Стадии производства и отдельно оплата; состав со скачиванием STL через защищённую выдачу; сумма, оплачено, долг;
 * открытые платёжные запросы с кнопкой перехода к оплате (W-16; гость — по праву в сессии); поступившие платежи и возвраты из
 * транзакций Lunar (подтверждение аванса, D-04); доставка и трек; сообщение администратора и три ответа клиента
 * (OrderWorkflow); повторный заказ — только владельцу (калькулятор с ?models=). Счета — W-17.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderAccess $access, private readonly OrderWorkflow $workflow) {}

    /** Карточка в кабинете: только владелец; гостю — форма входа, чужой или неизвестный номер — 404. */
    public function show(Request $request, int $number): View
    {
        if (! $request->user()) {
            return view('account.auth', ['mode' => 'login']);
        }
        $meta = OrderMeta::where('number', $number)->first();
        $order = $meta?->order;
        abort_unless($order !== null && $this->access->owns($order, $request->user()), 404);

        return $this->card($meta, $order, owner: true);
    }

    /**
     * Гостевая страница по секретной ссылке (§6.2): неверный номер или токен — 404 (частота по IP — throttle:guest-orders).
     * Верный токен записывает в сессию право на заказ: по нему гость скачивает свои STL и отвечает администратору.
     */
    public function guest(int $number, string $token): View
    {
        $meta = OrderMeta::where('number', $number)->whereNotNull('guest_token_hash')->first();
        abort_unless($meta !== null && hash_equals($meta->guest_token_hash, hash('sha256', $token)), 404);
        $this->access->grantGuest($meta);

        return $this->card($meta, $meta->order, owner: false);
    }

    /** Ответ клиента (§1.3): владелец или гость с правом на этот заказ в сессии; остальным — 404 без записи. */
    public function reply(Request $request, int $number): RedirectResponse
    {
        $order = OrderMeta::where('number', $number)->first()?->order;
        abort_unless($order !== null && $this->access->isCustomer($order, $request->user()), 404);
        $data = $request->validate(['kind' => ['required', Rule::in(OrderMessage::REPLIES)]]);
        $this->workflow->recordCustomerReply($order, $data['kind'], $request->user());

        return back()->with('status', __('account.order.recorded'));
    }

    private function card(OrderMeta $meta, Order $order, bool $owner): View
    {
        $order->load('lines.purchasable');
        $state = ProductionState::where('order_id', $order->id)->firstOrFail();
        $pickup = $meta->delivery === 'pickup';
        $stages = array_values(array_filter(ProductionStage::cases(), fn (ProductionStage $stage): bool => $stage !== ProductionStage::Cancelled
            && ! ($pickup && $stage === ProductionStage::Shipped)));
        $parts = $order->lines->filter(fn ($line) => $line->purchasable instanceof StlPart)->values();

        return view('account.order', [
            'meta' => $meta,
            'order' => $order,
            'state' => $state,
            'stages' => $stages,
            'current' => array_search($state->stage, $stages, true),
            'parts' => $parts,
            'balance' => $this->workflow->balance($order),
            'requests' => PaymentRequest::where('order_id', $order->id)->whereIn('state', PaymentRequest::OPEN)->orderBy('id')->get(),
            'payments' => $order->transactions()->whereIn('type', ['capture', 'refund'])->where('success', true)->orderBy('created_at')->orderBy('id')->get(),
            'messages' => OrderMessage::where('order_id', $order->id)->orderBy('id')->get(),
            'reorder' => $owner ? route(app()->getLocale().'.calculator', ['models' => $parts->map(fn ($line) => $line->purchasable->file->id)->join(',')]) : null,
        ]);
    }
}
