<?php

namespace Tests\Feature;

use App\Domain\Forms\Models\ContactRequest;
use App\Livewire\Admin\Overview;
use App\Models\StlFile;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Эксплуатационный пакет II (W-29, §8.4, §8.5): ротация копий bin/backup (только ротация, без Docker), расписание
 * scheduler, проверка файлов-сирот без удаления, очистка сессий и токенов сброса, давность копии в Overview.
 */
class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/aiqme-w29-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->directory.'/backups', 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    /** @return array{int, string} код завершения и вывод bin/backup с .env временного каталога */
    private function backup(string $env, string ...$arguments): array
    {
        file_put_contents($this->directory.'/.env', $env);
        $process = new Process(['python3', base_path('bin/backup'), '--env-file', $this->directory.'/.env', ...$arguments], base_path());
        $process->run();

        return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }

    public function test_rotation_keeps_the_last_copy_of_14_days_and_8_iso_weeks_from_env(): void
    {
        // Копии каждый день 2026-01-01 … 2026-03-31 в 02:00 и ещё по одной более ранней копии 31.03 и 15.03.
        $backups = $this->directory.'/backups';
        for ($day = strtotime('2026-01-01'); $day <= strtotime('2026-03-31'); $day += 86400) {
            mkdir($backups.'/'.date('Ymd', $day).'-020000');
        }
        mkdir($backups.'/20260331-010000');
        mkdir($backups.'/20260315-010000');
        file_put_contents($backups.'/20260331-020000/db.dump', 'dump');
        mkdir($backups.'/.incomplete-20260331-030000');
        mkdir($backups.'/notes');

        [$code, $output] = $this->backup("AIQME_BACKUP_DIR={$backups}\nAIQME_BACKUP_DAILY=14\nAIQME_BACKUP_WEEKLY=8\n", '--rotate-only');

        $this->assertSame(0, $code, $output);
        $kept = collect(scandir($backups))->reject(fn (string $name): bool => in_array($name, ['.', '..'], true))->sort()->values()->all();
        // 14 дней 18.03–31.03 и последние копии ISO-недель 7–11 (15.02, 22.02, 01.03, 08.03, 15.03; недели 12–14 уже среди дней).
        $days = array_map(fn (int $day): string => '202603'.sprintf('%02d', $day).'-020000', range(18, 31));
        $weeks = ['20260215-020000', '20260222-020000', '20260301-020000', '20260308-020000', '20260315-020000'];
        $this->assertSame(collect([...$weeks, ...$days, '.incomplete-20260331-030000', 'notes'])->sort()->values()->all(), $kept);
        $this->assertFileExists($backups.'/20260331-020000/db.dump');
        $this->assertStringContainsString('удалено '.(90 + 2 - 19), $output);

        // Числа — из .env: 2 дневные и 0 недельных.
        [$code] = $this->backup("AIQME_BACKUP_DIR={$backups}\nAIQME_BACKUP_DAILY=2\nAIQME_BACKUP_WEEKLY=0\n", '--rotate-only');
        $this->assertSame(0, $code);
        $this->assertDirectoryExists($backups.'/20260331-020000');
        $this->assertDirectoryExists($backups.'/20260330-020000');
        $this->assertDirectoryDoesNotExist($backups.'/20260329-020000');
    }

    public function test_backup_fails_explicitly_without_settings_or_a_readable_env(): void
    {
        [$code, $output] = $this->backup("AIQME_BACKUP_DAILY=14\nAIQME_BACKUP_WEEKLY=8\n", '--rotate-only');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('нет AIQME_BACKUP_DIR', $output);

        [$code, $output] = $this->backup("AIQME_BACKUP_DIR={$this->directory}/backups\nAIQME_BACKUP_DAILY=14\n", '--rotate-only');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('AIQME_BACKUP_WEEKLY', $output);

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestIncomplete('от root нечитаемый .env не проверить');
        }
        file_put_contents($this->directory.'/.env', "AIQME_BACKUP_DIR={$this->directory}/backups\n");
        chmod($this->directory.'/.env', 0);
        $process = new Process(['python3', base_path('bin/backup'), '--env-file', $this->directory.'/.env'], base_path());
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('.env не читается', $process->getErrorOutput());
    }

    public function test_scheduler_runs_the_section_8_4_tasks_and_no_backup(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event): array => [preg_replace('/^.*artisan.? /', '', $event->command ?? $event->description) => $event->expression]);

        $this->assertSame('*/5 * * * *', $events['aiqme:integration-retry']);
        $this->assertSame('30 3 * * *', $events['aiqme:orphans']);
        $this->assertSame('40 3 * * *', $events['aiqme:prune-sessions']);
        $this->assertSame('45 3 * * *', $events['auth:clear-resets']);
        $this->assertSame('0 0 * * *', $events['App\Domain\Invoices\IssuePendingInvoices']);
        // Копию запускает cron хоста (bin/backup), не scheduler.
        $this->assertFalse($events->keys()->contains(fn (string $command): bool => str_contains($command, 'backup')));
    }

    public function test_orphan_check_lists_unreferenced_files_and_deletes_nothing(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        foreach (['stl/kept.stl', 'stl/orphan.stl', 'contact-requests/kept', 'contact-requests/orphan', 'invoices/A-1.pdf', 'other/ignored.txt'] as $path) {
            $disk->put($path, 'x');
        }
        StlFile::create(['path' => 'stl/kept.stl', 'original_name' => 'kept.stl', 'size' => 1, 'sha256' => str_repeat('0', 64),
            'triangles' => 0, 'analysis' => []]);
        ContactRequest::create(['locale' => 'en', 'name' => 'Ona', 'email' => 'ona@example.test', 'service' => 'scanning',
            'description' => 'Text', 'attachments' => [['path' => 'contact-requests/kept', 'name' => 'a.pdf', 'size' => 1, 'sha256' => 'x']]]);

        $this->artisan('aiqme:orphans')
            ->expectsOutput('stl/orphan.stl')
            ->expectsOutput('contact-requests/orphan')
            ->expectsOutput('invoices/A-1.pdf')
            ->doesntExpectOutput('stl/kept.stl')
            ->expectsOutput('Orphan files: 3 (not deleted).')
            ->assertSuccessful();

        foreach (['stl/kept.stl', 'stl/orphan.stl', 'contact-requests/orphan', 'invoices/A-1.pdf'] as $path) {
            $disk->assertExists($path);
        }
    }

    public function test_expired_sessions_and_reset_tokens_are_deleted_and_fresh_ones_kept(): void
    {
        $lifetime = (int) config('session.lifetime');
        foreach (['old' => now()->subMinutes($lifetime + 1), 'fresh' => now()->subMinutes($lifetime - 1)] as $id => $time) {
            DB::table('sessions')->insert(['id' => $id, 'payload' => '', 'last_activity' => $time->getTimestamp()]);
        }
        $expire = (int) config('auth.passwords.users.expire');
        foreach (['old@example.test' => now()->subMinutes($expire + 1), 'fresh@example.test' => now()] as $email => $time) {
            DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'hash', 'created_at' => $time]);
        }

        $this->artisan('aiqme:prune-sessions')->expectsOutput('Expired sessions deleted: 1')->assertSuccessful();
        $this->artisan('auth:clear-resets')->assertSuccessful();

        $this->assertSame(['fresh'], DB::table('sessions')->pluck('id')->all());
        $this->assertSame(['fresh@example.test'], DB::table('password_reset_tokens')->pluck('email')->all());
    }

    public function test_overview_shows_the_age_of_the_last_backup_from_the_storage_stamp(): void
    {
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        $storage = $this->app->storagePath();
        $this->app->useStoragePath($this->directory);
        File::makeDirectory($this->directory.'/app');

        try {
            app()->setLocale('en');
            Livewire::test(Overview::class)->assertSeeHtml('id="overview-backup"')->assertSee('No backup has been made yet.');

            file_put_contents($this->directory.'/'.Overview::BACKUP_STAMP, now()->subHours(3)->utc()->format('Y-m-d\TH:i:s\Z'));
            Livewire::test(Overview::class)->assertSee('Last backup: 3 hours ago.');

            app()->setLocale('lt');
            Livewire::test(Overview::class)->assertSee('Paskutinė atsarginė kopija: prieš 3 valandas.');

            $this->app->detectEnvironment(fn (): string => 'demo');
            Livewire::test(Overview::class)->assertDontSeeHtml('id="overview-backup"');
        } finally {
            $this->app->useStoragePath($storage);
        }
    }
}
