<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\Order;

/**
 * Данные заявки заказа Lunar (§2.5): порядковый номер, хеш гостевого токена (открытый токен не хранится, §6.2), токен
 * отправки и отпечаток её данных (§3.1), локаль заявки (язык счёта, §3.5), контакты, способ получения, адрес доставки
 * и снимок настроек калькулятора, по которым посчитана оценка.
 */
#[Fillable(['order_id', 'number', 'guest_token_hash', 'submission_token', 'submission_digest', 'locale', 'contact', 'delivery', 'address', 'settings'])]
class OrderMeta extends Model
{
    protected $table = 'order_meta';

    protected function casts(): array
    {
        return ['contact' => 'array', 'address' => 'array', 'settings' => 'array'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
