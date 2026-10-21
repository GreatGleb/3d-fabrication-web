<?php

namespace App\Console\Commands;

use App\Domain\Integrations\Telegram\TelegramClient;
use App\Domain\Integrations\Telegram\TelegramException;
use App\Domain\Integrations\Telegram\TelegramUpdates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Опрос тестового бота без публичного адреса (§5.4): только при APP_ENV=local; deleteWebhook, затем getUpdates в цикле
 * до остановки (Ctrl+C), апдейты — тому же обработчику, что у webhook (дедупликация update_id). --once — один проход.
 */
#[Signature('aiqme:telegram-poll {--once : Один запрос getUpdates}')]
#[Description('Опрос тестового Telegram-бота (только APP_ENV=local)')]
class TelegramPoll extends Command
{
    private const WAIT_SECONDS = 25;

    public function handle(TelegramClient $client, TelegramUpdates $updates): int
    {
        if (! app()->environment('local')) {
            $this->error('aiqme:telegram-poll — только при APP_ENV=local.');

            return self::FAILURE;
        }
        try {
            $client->call('deleteWebhook');
        } catch (TelegramException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $offset = 0;
        do {
            try {
                $batch = $client->call('getUpdates', [
                    'offset' => $offset, 'timeout' => self::WAIT_SECONDS, 'allowed_updates' => ['message', 'callback_query'],
                ], self::WAIT_SECONDS + 10);
            } catch (TelegramException $exception) {
                $this->error($exception->getMessage());
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                sleep(5);

                continue;
            }
            foreach (is_array($batch) ? $batch : [] as $update) {
                if (is_array($update) && is_int($update['update_id'] ?? null)) {
                    $offset = max($offset, $update['update_id'] + 1);
                    $updates->receive($update);
                }
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
