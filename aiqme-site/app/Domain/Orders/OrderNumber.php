<?php

namespace App\Domain\Orders;

use App\Domain\Orders\Models\OrderMeta;
use Illuminate\Support\Facades\DB;
use Lunar\Base\OrderReferenceGeneratorInterface;
use Lunar\Models\Contracts\Order;

/**
 * Анонимный номер заказа (правка 12.11, K-05): генератор номера Lunar (lunar.orders.reference_generator) пишет в
 * reference случайное 8-значное число (random_int, 10000000–99999999) — по номеру нельзя понять, сколько было заказов.
 * При совпадении с reference другого заказа или order_meta.number — новая попытка. Номера прежних заказов (порядковые
 * из счётчика counters «orders») не меняются. Номер доступа к заказу не даёт.
 */
class OrderNumber implements OrderReferenceGeneratorInterface
{
    public const MIN = 10000000;

    public const MAX = 99999999;

    public function generate(Order $order): string
    {
        do {
            $number = $this->candidate();
        } while (DB::table('lunar_orders')->where('reference', (string) $number)->exists()
            || OrderMeta::where('number', $number)->exists());

        return (string) $number;
    }

    /** Кандидат в номер; тесты подменяют его, чтобы проверить повтор при совпадении. */
    protected function candidate(): int
    {
        return random_int(self::MIN, self::MAX);
    }
}
