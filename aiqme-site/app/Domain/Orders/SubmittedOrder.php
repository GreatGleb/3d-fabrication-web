<?php

namespace App\Domain\Orders;

use App\Domain\Orders\Models\OrderMeta;
use Lunar\Models\Order;

/** Результат отправки заявки: заказ, его данные и открытый гостевой токен (только гостю, показывается один раз). */
final readonly class SubmittedOrder
{
    public function __construct(
        public Order $order,
        public OrderMeta $meta,
        public ?string $guestToken,
    ) {}
}
