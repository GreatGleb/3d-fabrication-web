<?php

namespace App\Domain\Orders;

use App\Models\User;
use Lunar\Models\Order;

/**
 * Единственная проверка прав на заказ (§6.1, W-32): администратор или владелец — вошедший клиент при непустом user_id
 * заказа, равном его id. У гостевого заказа (user_id = null) владельца нет: сравнение user_id без проверки входа из
 * эксперимента (null === null у любого посетителя) не переносится. Право гостя в сессии (§6.2) добавляет W-15.
 * Используют выдача STL и задачи W-14…W-17; отказ — 404 у вызывающего (существование не раскрывается).
 */
final class OrderAccess
{
    public function allows(Order $order, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->is_admin || $this->owns($order, $user);
    }

    /** Владелец заказа: вошедший клиент с user_id заказа (гостевой заказ владельца не имеет). */
    public function owns(Order $order, ?User $user): bool
    {
        return $user !== null && $order->user_id !== null && (int) $order->user_id === $user->id;
    }
}
