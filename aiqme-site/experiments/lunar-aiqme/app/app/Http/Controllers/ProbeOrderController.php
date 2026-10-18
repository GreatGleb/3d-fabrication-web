<?php

namespace App\Http\Controllers;

use App\Models\StlPart;
use App\Services\OrderWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\Order;

class ProbeOrderController extends Controller
{
    public function show(Request $request, Order $order, OrderWorkflow $workflow): mixed
    {
        $workflow->authorizeView($request->user(), $order);

        return view('probe-order', ['order' => $order, 'balance' => $workflow->balance($order)]);
    }

    public function file(Request $request, StlPart $part): mixed
    {
        abort_unless($request->user()->is_admin || $part->user_id === $request->user()->id, 403);

        return Storage::disk('local')->download($part->path, $part->original_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function confirm(Request $request, Order $order, OrderWorkflow $workflow): mixed
    {
        $data = $request->validate(['prices' => 'required|array', 'prices.*' => 'required|integer|min:1', 'reason' => 'required|string|max:1000', 'agreement' => 'required|string|max:255']);
        $prices = array_map('intval', $data['prices']);
        $workflow->confirmPrice($request->user(), $order->id, $prices, $data['reason'], $data['agreement']);

        return redirect()->route('probe.order', $order);
    }

    public function payment(Request $request, Order $order, OrderWorkflow $workflow): mixed
    {
        $data = $request->validate(['amount' => 'required|integer|min:1']);

        return response()->json($workflow->requestPayment($request->user(), $order->id, (int) $data['amount']));
    }

    public function start(Request $request, Order $order, OrderWorkflow $workflow): mixed
    {
        $workflow->startProduction($request->user(), $order->id);

        return redirect()->route('probe.order', $order);
    }
}
