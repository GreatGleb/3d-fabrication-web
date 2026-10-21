<?php

namespace App\Domain\Integrations\Telegram\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Контекст диалога привязанного чата (§4.5): ожидаемый ввод и его запись; хранится на сервере до expires_at. */
#[Fillable(['chat_id', 'state', 'expires_at'])]
class TelegramDialog extends Model
{
    protected $primaryKey = 'chat_id';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['state' => 'array', 'expires_at' => 'datetime'];
    }
}
