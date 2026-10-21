<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ревизия цены (§2.5, §3.2): было/стало в центах по id строк заказа, автор; у изменения после подтверждения — основание
 * и ссылка на согласование с клиентом (текст или ответ клиента «согласен с изменением»). Ревизия 1 — подтверждение.
 */
#[Fillable(['order_id', 'actor_id', 'revision', 'before', 'after', 'reason', 'agreement', 'created_at'])]
class PriceRevision extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
