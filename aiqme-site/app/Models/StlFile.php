<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\Order;

/**
 * Приватный STL заявки (§2.5, §6.4): файл на диске local (storage/app/private) под именем UUID, назначенным сервером;
 * исходное имя, размер и sha256 — метаданные; результат анализа StlAnalyzer (в единицах файла) и его версия.
 * Владелец — клиент (user_id) или, у гостевой заявки, только заказ (order_id). hidden_at — клиент скрыл модель из
 * Saved models (W-15); файл остаётся у заказа.
 */
#[Fillable(['user_id', 'order_id', 'path', 'original_name', 'size', 'sha256', 'triangles', 'analysis', 'hidden_at'])]
class StlFile extends Model
{
    /** Каталог файлов заявок на диске local. */
    public const DIRECTORY = 'stl';

    protected function casts(): array
    {
        return ['analysis' => 'array', 'hidden_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
