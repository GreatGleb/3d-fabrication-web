<?php

use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Integrations\Sheets\RebuildSheets;
use App\Domain\Integrations\Sheets\SheetsProjector;
use App\Domain\Invoices\IssuePendingInvoices;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Повтор недоставленных каналов outbox (§2.7, §8.4): mail (W-09) и sheets (W-18).
Artisan::command('aiqme:integration-retry', function (IntegrationEvents $events) {
    $this->info('Заданий поставлено: '.$events->retry());
})->purpose('Retry undelivered integration_events channels');

Schedule::command('aiqme:integration-retry')->everyFiveMinutes();

// Пересборка Google Таблицы из БД (§5.3, W-18): команда только ставит задание в очередь sheets, в лист пишет оно.
Artisan::command('aiqme:sheets-rebuild', function () {
    if (! SheetsProjector::configured()) {
        $this->error('Google Sheets is not configured: set GOOGLE_SHEETS_KEY_BASE64 and GOOGLE_SHEETS_SPREADSHEET_ID.');

        return 1;
    }
    RebuildSheets::dispatch();
    $this->info('Rebuild queued (queue sheets).');

    return 0;
})->purpose('Queue a rebuild of the Google Sheets tabs from the database');

// Задание «ожидающие счета» (§3.5, §8.4, W-17): раз в сутки; после сохранения реквизитов его ставит вкладка Settings.
Schedule::job(new IssuePendingInvoices)->daily();
