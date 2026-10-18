<?php

namespace App\Services;

use App\Models\ProductionState;
use App\Models\User;
use Comparison\StlIntake;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Order;

class BasketService
{
    public function open(User $buyer): Cart
    {
        return Cart::create(['user_id' => $buyer->id, 'customer_id' => $buyer->customers()->firstOrFail()->id, 'channel_id' => Channel::getDefault()->id, 'currency_id' => Currency::whereCode('EUR')->firstOrFail()->id]);
    }

    public function add(User $buyer, int $id, array $item): void
    {
        DB::transaction(function () use ($buyer, $id, $item) {
            $cart = $this->editable($buyer, $id);
            abort_if($cart->lines()->count() >= 10, 422, 'At most 10 files.');
            $part = app(StlIntake::class)->store($buyer, $item);
            $cart->add($part, $item['parameters']['quantity'], ['parameters' => $part->parameters, 'estimate' => $part->estimate]);
        });
    }

    public function change(User $buyer, int $id, int $lineId, int $quantity): void
    {
        abort_unless($quantity > 0 && $quantity <= 100, 422);
        DB::transaction(function () use ($buyer, $id, $lineId, $quantity) {
            $cart = $this->editable($buyer, $id);
            $line = $cart->lines()->findOrFail($lineId);
            $parameters = [...$line->purchasable->parameters, 'quantity' => $quantity];
            $cart->updateLine($lineId, $quantity, ['parameters' => $parameters, 'estimate' => StlIntake::unit($parameters) * $quantity]);
        });
    }

    public function remove(User $buyer, int $id, int $lineId): void
    {
        DB::transaction(function () use ($buyer, $id, $lineId) {
            $cart = $this->editable($buyer, $id);
            $cart->lines()->findOrFail($lineId);
            $cart->remove($lineId);
        });
    }

    public function submit(User $buyer, int $id): Order
    {
        return DB::transaction(function () use ($buyer, $id) {
            $cart = Cart::lockForUpdate()->findOrFail($id);
            abort_unless($cart->user_id === $buyer->id, 403);
            if ($cart->completedOrder) {
                return $cart->completedOrder;
            }
            abort_unless($cart->lines()->exists(), 422, 'Basket is empty.');
            $order = $cart->createOrder();
            $order->update(['placed_at' => now(), 'status' => 'submitted']);
            ProductionState::create(['order_id' => $order->id]);

            return $order->fresh();
        });
    }

    public function view(User $buyer, int $id): array
    {
        $cart = Cart::findOrFail($id);
        abort_unless($cart->user_id === $buyer->id, 403);
        $cart->recalculate();

        return ['id' => $cart->id, 'total' => $cart->total->value, 'submitted' => $cart->completedOrder?->id, 'lines' => $cart->lines->map(fn ($line) => ['id' => $line->id, 'name' => $line->purchasable->original_name, 'quantity' => $line->quantity, 'total' => $line->unitPrice->value * $line->quantity])->all()];
    }

    private function editable(User $buyer, int $id): Cart
    {
        $cart = Cart::lockForUpdate()->findOrFail($id);
        abort_unless($cart->user_id === $buyer->id, 403);
        abort_if($cart->completedOrder !== null, 422, 'Basket already submitted.');

        return $cart;
    }
}
