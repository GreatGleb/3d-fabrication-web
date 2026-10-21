<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Telegram-бот контента (W-19, §5.4): токен бота и секрет webhook (1–256 символов A-Z, a-z, 0-9, _ и -).
    | Значения — только в .env; в репозитории и интерфейсе их нет.
    */
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    /*
    | Stripe (W-16, §3.3, §5.5): публикуемый ключ для Payment Element и секретный ключ API — только в .env, свои для
    | каждой среды (test вне рабочей среды, live — только в production). Не заданы — «оплата временно недоступна».
    | timeout — секунд на вызов API (вызов всегда вне транзакции БД, §2.6).
    */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'timeout' => (int) env('STRIPE_TIMEOUT', 20),
    ],

];
