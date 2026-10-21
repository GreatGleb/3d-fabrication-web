<?php

namespace App\Domain\Integrations\Telegram;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Приём апдейта — общий для webhook и aiqme:telegram-poll (§4.5, §5.4): update_id записывается в telegram_updates,
 * повтор того же update_id ничего не делает; затем апдейт обрабатывает TelegramBot. Ошибка обработки сообщается
 * в журнал, апдейт остаётся принятым (повтор Telegram его не применит второй раз).
 */
final class TelegramUpdates
{
    public function __construct(private readonly TelegramBot $bot) {}

    /** @return bool false — апдейт без update_id или уже принят */
    public function receive(array $update): bool
    {
        if (! is_int($update['update_id'] ?? null)) {
            return false;
        }
        if (DB::table('telegram_updates')->insertOrIgnore(['update_id' => $update['update_id'], 'created_at' => now()]) === 0) {
            return false;
        }
        try {
            $this->bot->handle($update);
        } catch (Throwable $exception) {
            report($exception);
        }

        return true;
    }
}
