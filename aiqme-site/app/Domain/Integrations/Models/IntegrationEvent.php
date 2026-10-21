<?php

namespace App\Domain\Integrations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Событие outbox (§2.5, §2.7): тип, сущность, данные для письма и листа Events (summary, amount) и доставка по каналам
 * mail и sheets — статус pending/sending/sent/failed/none, попытки, последняя ошибка и время захвата у каждого свои.
 */
#[Fillable(['type', 'entity_type', 'entity_id', 'data', 'mail_status', 'sheets_status'])]
class IntegrationEvent extends Model
{
    protected function casts(): array
    {
        return ['data' => 'array', 'mail_claimed_at' => 'datetime', 'sheets_claimed_at' => 'datetime'];
    }
}
