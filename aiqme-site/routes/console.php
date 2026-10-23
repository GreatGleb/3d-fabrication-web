<?php

use App\Domain\Forms\FormSubmissions;
use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Integrations\Sheets\RebuildSheets;
use App\Domain\Integrations\Sheets\SheetsProjector;
use App\Domain\Invoices\IssuePendingInvoices;
use App\Domain\Invoices\Models\Invoice;
use App\Models\StlFile;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

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

// Ночная проверка файлов-сирот (§8.4, W-29): файлы приватного диска в каталогах STL, вложений и счетов без ссылки в БД
// перечисляются в журнале и выводе; ничего не удаляется.
Artisan::command('aiqme:orphans', function () {
    $referenced = StlFile::pluck('path')
        ->merge(ContactRequest::pluck('attachments')->flatten(1)->pluck('path'))
        ->merge(Invoice::pluck('pdf_path'))
        ->flip();
    $orphans = collect([StlFile::DIRECTORY, FormSubmissions::ATTACHMENTS_DIRECTORY, 'invoices'])
        ->flatMap(fn (string $directory): array => Storage::disk('local')->allFiles($directory))
        ->reject(fn (string $path): bool => $referenced->has($path))
        ->values();
    if ($orphans->isNotEmpty()) {
        Log::warning('aiqme:orphans: files without database records (not deleted)', ['files' => $orphans->all()]);
    }
    $orphans->each(fn (string $path) => $this->line($path));
    $this->info('Orphan files: '.$orphans->count().' (not deleted).');
})->purpose('List private files without database records (nothing is deleted)');

// Очистка устаревших сессий (§8.4, W-29): строки sessions старше session.lifetime.
Artisan::command('aiqme:prune-sessions', function () {
    $deleted = DB::table(config('session.table'))
        ->where('last_activity', '<', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
        ->delete();
    $this->info('Expired sessions deleted: '.$deleted);
})->purpose('Delete expired database sessions');

Schedule::command('aiqme:orphans')->dailyAt('03:30');
Schedule::command('aiqme:prune-sessions')->dailyAt('03:40');
Schedule::command('auth:clear-resets')->dailyAt('03:45');
