<?php

namespace Tests\Support;

use Lunar\Models\Order;
use Lunar\Models\Transaction;

/**
 * Тестовая фабрика «оплачено» (§2.6, W-13): транзакция Lunar capture на заказ — вместо receipt() и LocalTestAdapter
 * эксперимента. В рабочем коде метода записи оплаты нет: capture создаёт только сервис записи по данным Stripe (W-16, W-28).
 */
final class LunarCapture
{
    public static function create(Order $order, int $cents, string $reference = 'test-charge-not-real'): Transaction
    {
        return Transaction::create([
            'order_id' => $order->id,
            'success' => true,
            'type' => 'capture',
            'driver' => 'test',
            'amount' => $cents,
            'reference' => $reference,
            'status' => 'settled',
            'captured_at' => now(),
            'meta' => [],
        ]);
    }
}
