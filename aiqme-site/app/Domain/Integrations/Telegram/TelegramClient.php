<?php

namespace App\Domain\Integrations\Telegram;

use App\Domain\Integrations\Telegram\Models\TelegramBinding;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Тонкий клиент Bot API на HTTP-клиенте Laravel (§5.4), без пакетов. Адрес содержит токен (/bot<TOKEN>/…): любая
 * ошибка сети или API превращается в TelegramException, из сообщения которой токен вырезан; это же сообщение пишется
 * в журнал и в последнюю ошибку привязки (экран Integrations). Исходное исключение дальше не передаётся.
 */
final class TelegramClient
{
    private const API = 'https://api.telegram.org';

    /** Вызов метода Bot API; возвращает result ответа. */
    public function call(string $method, array $params = [], int $timeout = 15): mixed
    {
        try {
            $response = Http::timeout($timeout)->asJson()->post(self::API.'/bot'.$this->token().'/'.$method, $params);
        } catch (Throwable $exception) {
            $this->fail($method.': '.$exception->getMessage());
        }
        if (! $response->successful() || $response->json('ok') !== true) {
            $this->fail($method.': HTTP '.$response->status().' '.$response->json('description'));
        }

        return $response->json('result');
    }

    /** Скачивает файл, полученный getFile (file_path), в $target. */
    public function download(string $filePath, string $target): void
    {
        try {
            $response = Http::timeout(60)->sink($target)->get(self::API.'/file/bot'.$this->token().'/'.$filePath);
        } catch (Throwable $exception) {
            $this->fail('download: '.$exception->getMessage());
        }
        if (! $response->successful()) {
            $this->fail('download: HTTP '.$response->status());
        }
    }

    private function token(): string
    {
        $token = (string) config('services.telegram.token');

        return $token !== '' ? $token : $this->fail('TELEGRAM_BOT_TOKEN is not set');
    }

    private function fail(string $message): never
    {
        $token = (string) config('services.telegram.token');
        if ($token !== '') {
            $message = str_replace([$token, rawurlencode($token)], '[token]', $message);
        }
        $message = mb_substr($message, 0, 1000);
        Log::warning('Telegram: '.$message);
        $binding = TelegramBinding::current();
        $binding->fill(['last_error' => $message, 'last_error_at' => now()])->save();

        throw new TelegramException($message);
    }
}
