<?php

use App\Http\Controllers\Public\AltchaController;
use App\Http\Controllers\Webhooks\StripeController;
use App\Http\Controllers\Webhooks\TelegramController;
use Illuminate\Support\Facades\Route;

// Маршруты вне группы web — без сессии, cookie и CSRF-middleware. Webhook внешних сервисов (§6.3): подлинность проверяет
// контроллер. Задача ALTCHA для форм (правка 28.10 П3): без cookie и url()->previous(), частота — по IP.
Route::post('webhooks/stripe', StripeController::class)->name('webhooks.stripe');
Route::post('webhooks/telegram', TelegramController::class)->name('webhooks.telegram');
Route::get('altcha', AltchaController::class)->middleware('throttle:altcha')->name('altcha');
