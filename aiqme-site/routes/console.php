<?php

use App\Domain\Integrations\IntegrationEvents;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Повтор недоставленных каналов outbox (§2.7, §8.4, W-09): канал mail; канал sheets подключает W-18.
Artisan::command('aiqme:integration-retry', function (IntegrationEvents $events) {
    $this->info('Заданий поставлено: '.$events->retryMail());
})->purpose('Retry undelivered integration_events channels');

Schedule::command('aiqme:integration-retry')->everyFiveMinutes();
