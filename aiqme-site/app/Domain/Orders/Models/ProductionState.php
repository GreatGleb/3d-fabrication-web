<?php

namespace App\Domain\Orders\Models;

use App\Domain\Orders\ProductionStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Состояние заказа (§2.5, §3.4): стадия, время подтверждения цены и начала производства (после него цена заблокирована),
 * номер последней ревизии цены, перевозчик и трек-номер (видны клиенту), причина отмены. Создаётся вместе с заказом (W-12)
 * в стадии review; меняет только OrderWorkflow.
 */
#[Fillable(['order_id', 'stage', 'confirmed_at', 'started_at', 'revision', 'carrier', 'tracking_number', 'cancel_reason'])]
class ProductionState extends Model
{
    protected function casts(): array
    {
        return ['stage' => ProductionStage::class, 'confirmed_at' => 'datetime', 'started_at' => 'datetime'];
    }
}
