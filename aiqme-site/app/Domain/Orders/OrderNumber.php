<?php

namespace App\Domain\Orders;

use Illuminate\Support\Facades\DB;
use Lunar\Base\OrderReferenceGeneratorInterface;
use Lunar\Models\Contracts\Order;

/**
 * Порядковый номер заказа (§3.1): генератор номера Lunar (lunar.orders.reference_generator) пишет его в reference.
 * Номер выдаёт атомарный UPDATE … SET value = value + 1 RETURNING value счётчика counters в транзакции заявки: при откате
 * номер не расходуется, параллельные заявки получают разные номера. Строку счётчика создаёт первая выдача
 * (первый номер — config('aiqme.orders.first_number')). Номер доступа к заказу не даёт.
 */
final class OrderNumber implements OrderReferenceGeneratorInterface
{
    public const COUNTER = 'orders';

    public function generate(Order $order): string
    {
        return (string) self::next(self::COUNTER, config('aiqme.orders.first_number'));
    }

    public static function next(string $counter, int $first = 1): int
    {
        DB::table('counters')->insertOrIgnore(['name' => $counter, 'value' => $first - 1]);

        return (int) DB::selectOne('update counters set value = value + 1 where name = ? returning value', [$counter])->value;
    }
}
