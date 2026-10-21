<?php

namespace App\Console\Commands;

use App\Domain\Integrations\Telegram\TelegramClient;
use App\Domain\Integrations\Telegram\TelegramException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Регистрирует webhook бота (§5.4): setWebhook с адресом APP_URL/webhooks/telegram, secret_token из
 * TELEGRAM_WEBHOOK_SECRET (1–256 символов A-Z, a-z, 0-9, _ и -, иначе отказ) и allowed_updates message, callback_query.
 */
#[Signature('aiqme:telegram-webhook')]
#[Description('Зарегистрировать webhook Telegram-бота на APP_URL/webhooks/telegram')]
class TelegramWebhook extends Command
{
    public function handle(TelegramClient $client): int
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if (! preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $secret)) {
            $this->error('TELEGRAM_WEBHOOK_SECRET: 1–256 символов A-Z, a-z, 0-9, _ и -.');

            return self::FAILURE;
        }
        $url = rtrim((string) config('app.url'), '/').'/webhooks/telegram';
        try {
            $client->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query']]);
        } catch (TelegramException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Webhook: '.$url);

        return self::SUCCESS;
    }
}
