<?php

namespace App\Http\Controllers\Account;

use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\OrderAccess;
use App\Domain\Payments\Models\PaymentRequest;
use App\Domain\Payments\PaymentRequestService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Lunar\Models\Order;

/**
 * Страница оплаты платёжного запроса (§3.3, §6.2, W-16): /lt/apmokejimas/<public_id> · /en/checkout/<public_id>, она
 * же return_url после 3D Secure. Видят владелец, администратор (без Payment Element) или гость с правом на заказ в
 * сессии; остальным — 404, включая HEAD. public_id доступа не даёт. Возврат браузера оплату не записывает.
 */
class CheckoutController extends Controller
{
    public function __construct(private readonly OrderAccess $access, private readonly PaymentRequestService $payments) {}

    public function show(Request $request, string $publicId): View
    {
        $payment = PaymentRequest::where('public_id', $publicId)->first();
        $order = $payment === null ? null : Order::find($payment->order_id);
        abort_unless($order !== null && $this->access->allows($order, $request->user()), 404);

        // HEAD — без побочных действий: intent не создаётся (тело ответа не передаётся).
        $result = $request->isMethod('HEAD')
            ? ['status' => 'admin']
            : $this->payments->checkout($payment, $request->user(), in_array($request->query('redirect_status'), ['succeeded', 'processing'], true));

        return view('public.checkout', [
            'payment' => $payment->refresh(),
            'number' => OrderMeta::where('order_id', $order->id)->value('number'),
            'status' => $result['status'],
            'clientSecret' => $result['client_secret'] ?? null,
            'returnUrl' => route(app()->getLocale().'.checkout', $payment->public_id),
        ]);
    }
}
