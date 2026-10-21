<?php

namespace App\Domain\Orders;

use App\Domain\Orders\Models\OrderMeta;
use App\Models\User;
use Lunar\Models\Order;

/**
 * Единственная проверка прав на заказ (§6.1, W-32): администратор, владелец — вошедший клиент при непустом user_id
 * заказа, равном его id, — или гость с правом в сессии (§6.2, W-15). У гостевого заказа (user_id = null) владельца нет:
 * сравнение user_id без проверки входа из эксперимента (null === null у любого посетителя) не переносится.
 * Право гостя — пара «заказ + SHA-256 токена», записанная при открытии гостевой страницы по верному токену; при каждой
 * проверке хеш сверяется с текущим хешем заказа, поэтому после перевыпуска ссылки прежнее право не действует.
 * Используют выдача STL, кабинет, гостевая страница и задачи W-14…W-17; отказ — 404 у вызывающего.
 */
final class OrderAccess
{
    /** Ключ сессии: id заказа => SHA-256 гостевого токена. */
    public const SESSION_KEY = 'guest_orders';

    public function allows(Order $order, ?User $user): bool
    {
        return ($user !== null && $user->is_admin) || $this->isCustomer($order, $user);
    }

    /** Владелец заказа: вошедший клиент с user_id заказа (гостевой заказ владельца не имеет). */
    public function owns(Order $order, ?User $user): bool
    {
        return $user !== null && $order->user_id !== null && (int) $order->user_id === $user->id;
    }

    /** Сторона клиента заказа (ответы администратору, §1.3): владелец или гость с правом на этот заказ в сессии. */
    public function isCustomer(Order $order, ?User $user): bool
    {
        return $this->owns($order, $user) || $this->guestHolds($order);
    }

    /** Право гостя на заказ в текущей сессии — после открытия гостевой страницы по верному токену. */
    public function grantGuest(OrderMeta $meta): void
    {
        session()->put(self::SESSION_KEY.'.'.$meta->order_id, $meta->guest_token_hash);
    }

    private function guestHolds(Order $order): bool
    {
        $held = session()->get(self::SESSION_KEY.'.'.$order->id);
        if ($order->user_id !== null || ! is_string($held)) {
            return false;
        }
        $current = OrderMeta::where('order_id', $order->id)->value('guest_token_hash');

        return is_string($current) && hash_equals($current, $held);
    }
}
