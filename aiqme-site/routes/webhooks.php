<?php

use App\Http\Controllers\Webhooks\StripeController;
use App\Http\Controllers\Webhooks\TelegramController;
use Illuminate\Support\Facades\Route;

// Webhook внешних сервисов (§6.3): вне группы web — без сессии, cookie и CSRF-middleware; подлинность проверяет контроллер.
Route::post('webhooks/stripe', StripeController::class)->name('webhooks.stripe');
Route::post('webhooks/telegram', TelegramController::class)->name('webhooks.telegram');
