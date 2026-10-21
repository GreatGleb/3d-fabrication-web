<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Integrations\Telegram\TelegramUpdates;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook Telegram (§4.5, §6.5): маршрут вне группы web — без сессии, cookie и CSRF. Подлинность — заголовок
 * X-Telegram-Bot-Api-Secret-Token, равный TELEGRAM_WEBHOOK_SECRET (иначе 403 без эффекта); путь секретом не считается.
 * 200 — после записи update_id (повтор — без эффекта) и обработки.
 */
class TelegramController extends Controller
{
    public function __invoke(Request $request, TelegramUpdates $updates): Response
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response('', 403);
        }
        $update = json_decode($request->getContent(), true);
        if (! is_array($update) || ! is_int($update['update_id'] ?? null)) {
            return response('', 400);
        }
        $updates->receive($update);

        return response('', 200);
    }
}
