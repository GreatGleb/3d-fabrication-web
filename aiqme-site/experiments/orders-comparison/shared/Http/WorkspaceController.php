<?php

namespace Comparison\Http;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\BasketService;
use App\Services\OrderWorkflow;
use Comparison\Payments\StripeInstallments;
use Comparison\ReadModel;
use Illuminate\Http\Request;

class WorkspaceController
{
    public function create(Request $request, BasketService $baskets): mixed
    {
        return redirect()->route('comparison.basket', $baskets->open($request->user())->id);
    }

    public function basket(Request $request, int $basket, BasketService $baskets): mixed
    {
        return view('comparison::basket', ['basket' => $baskets->view($request->user(), $basket)]);
    }

    public function add(Request $request, int $basket, BasketService $baskets): mixed
    {
        $data = $request->validate(['file' => 'required|file|max:25600', 'material' => 'required|in:PLA,PETG', 'layer_mm' => 'required|numeric', 'quantity' => 'required|integer|min:1|max:100']);
        $baskets->add($request->user(), $basket, ['file' => $request->file('file'), 'parameters' => ['material' => $data['material'], 'layer_mm' => (float) $data['layer_mm'], 'quantity' => (int) $data['quantity']]]);

        return redirect()->route('comparison.basket', $basket);
    }

    public function change(Request $request, int $basket, int $line, BasketService $baskets): mixed
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:100']);
        $baskets->change($request->user(), $basket, $line, (int) $data['quantity']);

        return redirect()->route('comparison.basket', $basket);
    }

    public function remove(Request $request, int $basket, int $line, BasketService $baskets): mixed
    {
        $baskets->remove($request->user(), $basket, $line);

        return redirect()->route('comparison.basket', $basket);
    }

    public function submit(Request $request, int $basket, BasketService $baskets): mixed
    {
        return redirect()->route('probe.order', $baskets->submit($request->user(), $basket));
    }

    public function orders(Request $request): mixed
    {
        $query = ReadModel::orderClass()::query();
        if (! $request->user()->is_admin) {
            $query->where('user_id', $request->user()->id);
        }
        if ($request->filled('q')) {
            $query->where('reference', 'like', '%'.$request->string('q').'%');
        }

        return view('comparison::orders', ['orders' => $query->latest('id')->paginate(20)]);
    }

    public function customers(Request $request): mixed
    {
        abort_unless($request->user()->is_admin, 403);

        return view('comparison::customers', ['customers' => User::where('is_admin', false)->orderBy('id')->paginate(20)]);
    }

    public function cancel(Request $request, int $order, OrderWorkflow $workflow): mixed
    {
        $workflow->cancel($request->user(), $order);

        return redirect()->route('probe.order', $order);
    }

    public function checkout(Request $request, string $payment, StripeInstallments $gateway): mixed
    {
        $record = PaymentRequest::wherePublicId($payment)->firstOrFail();
        $order = ReadModel::orderClass()::findOrFail($record->order_id);
        app(OrderWorkflow::class)->authorizeView($request->user(), $order);

        return response()->json($gateway->create($record));
    }

    public function webhook(Request $request, StripeInstallments $gateway): mixed
    {
        $gateway->webhook($request->getContent(), $request->header('Stripe-Signature', ''));

        return response()->json(['received' => true]);
    }
}
