<?php

namespace App\Domain\Integrations\Telegram\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Привязка бота (§4.5): единственная строка — администратор, привязанные Telegram user.id и chat.id, SHA-256 кода
 * привязки со сроком и последняя ошибка Bot API (без токена) для экрана Integrations.
 */
#[Fillable(['user_id', 'telegram_user_id', 'chat_id', 'bound_at', 'code_hash', 'code_expires_at', 'last_error', 'last_error_at'])]
class TelegramBinding extends Model
{
    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'chat_id' => 'integer',
            'bound_at' => 'datetime',
            'code_expires_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrNew();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
