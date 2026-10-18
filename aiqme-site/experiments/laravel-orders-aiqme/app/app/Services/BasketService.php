<?php

namespace App\Services;

use App\Models\Basket;
use App\Models\Order;
use App\Models\User;
use Comparison\StlIntake;
use Illuminate\Support\Facades\DB;

class BasketService
{
    public function open(User $buyer): Basket
    {
        return Basket::create(['user_id' => $buyer->id]);
    }

    public function add(User $buyer, int $id, array $item): void
    {
        DB::transaction(function () use ($buyer, $id, $item) {
            $basket = $this->editable($buyer, $id);
            abort_if($basket->lines()->count() >= 10, 422, 'At most 10 files.');
            $part = app(StlIntake::class)->store($buyer, $item);
            $basket->lines()->create(['stl_part_id' => $part->id, 'quantity' => $item['parameters']['quantity']]);
        });
    }

    public function change(User $buyer, int $id, int $lineId, int $quantity): void
    {
        abort_unless($quantity > 0 && $quantity <= 100, 422);
        DB::transaction(function () use ($buyer, $id, $lineId, $quantity) {
            $this->editable($buyer, $id)->lines()->findOrFail($lineId)->update(['quantity' => $quantity]);
        });
    }

    public function remove(User $buyer, int $id, int $lineId): void
    {
        DB::transaction(function () use ($buyer, $id, $lineId) {
            $this->editable($buyer, $id)->lines()->findOrFail($lineId)->delete();
        });
    }

    public function submit(User $buyer, int $id): Order
    {
        return DB::transaction(function () use ($buyer, $id) {
            $basket = Basket::lockForUpdate()->findOrFail($id);
            abort_unless($basket->user_id === $buyer->id, 403);
            if ($basket->order_id) {
                return Order::findOrFail($basket->order_id);
            }
            abort_unless($basket->lines()->exists(), 422, 'Basket is empty.');
            $order = Order::create(['user_id' => $buyer->id, 'status' => 'submitted', 'currency_code' => 'EUR', 'placed_at' => now(), 'sub_total' => 0, 'total' => 0]);
            $total = 0;
            foreach ($basket->lines as $line) {
                $part = $line->part;
                $unit = StlIntake::unit($part->parameters);
                $lineTotal = $unit * $line->quantity;
                $order->lines()->create(['stl_part_id' => $part->id, 'description' => $part->original_name, 'option' => $part->parameters['material'].' / '.$part->parameters['layer_mm'].' mm', 'quantity' => $line->quantity, 'unit_price' => $unit, 'sub_total' => $lineTotal, 'total' => $lineTotal, 'meta' => ['parameters' => [...$part->parameters, 'quantity' => $line->quantity], 'estimate' => $lineTotal]]);
                $total += $lineTotal;
            }
            $order->update(['reference' => 'PROBE-'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT), 'total' => $total, 'sub_total' => $total]);
            $basket->update(['order_id' => $order->id]);

            return $order->fresh();
        });
    }

    public function view(User $buyer, int $id): array
    {
        $basket = Basket::findOrFail($id);
        abort_unless($basket->user_id === $buyer->id, 403);
        $lines = $basket->lines->map(fn ($line) => ['id' => $line->id, 'name' => $line->part->original_name, 'quantity' => $line->quantity, 'total' => StlIntake::unit($line->part->parameters) * $line->quantity])->all();

        return ['id' => $id, 'total' => array_sum(array_column($lines, 'total')), 'submitted' => $basket->order_id, 'lines' => $lines];
    }

    private function editable(User $buyer, int $id): Basket
    {
        $basket = Basket::lockForUpdate()->findOrFail($id);
        abort_unless($basket->user_id === $buyer->id, 403);
        abort_if($basket->order_id !== null, 422, 'Basket already submitted.');

        return $basket;
    }
}
