<?php

namespace App\Domain\Audit;

/** Канал действия в журнале (§1.4 Audit): веб-админка, Telegram-бот, Stripe или система (сидер, планировщик). */
enum Channel: string
{
    case Web = 'web';
    case Telegram = 'telegram';
    case Stripe = 'stripe';
    case System = 'system';
}
