<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Отказы aiqme:demo-export (W-24 done[7]): при APP_ENV=production — до любых действий (ни одного запроса к БД); если
 * после создания aiqme_demo и переключения на неё подключение смотрит в другую БД (DB_URL) — до DemoSeeder и Auth::login,
 * aiqme_demo удалена, рабочая БД не меняется. Без RefreshDatabase: CREATE DATABASE не выполняется внутри транзакции.
 * Полная сборка и её вывод — bin/demo-build и bin/demo-check.
 */
class DemoExportTest extends TestCase
{
    public function test_refuses_in_production_before_any_action(): void
    {
        $this->app['env'] = 'production';
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->artisan('aiqme:demo-export')->expectsOutputToContain('APP_ENV=production')->assertFailed();

        $this->assertSame([], $queries);
        $this->assertFalse($this->demoDatabaseExists());
    }

    public function test_refuses_when_the_connection_does_not_switch_to_the_demo_database(): void
    {
        $connection = config('database.connections.pgsql');
        // DB_URL перекрывает имя БД из конфигурации: после переключения подключение остаётся в aiqme_test.
        config(['database.connections.pgsql.url' => sprintf('pgsql://%s:%s@%s:%s/%s', $connection['username'],
            $connection['password'], $connection['host'], $connection['port'], $connection['database'])]);
        $before = $this->workingDatabase();

        $this->artisan('aiqme:demo-export')->expectsOutputToContain('не в aiqme_demo')->assertFailed();

        $this->assertSame($before, $this->workingDatabase());
        $this->assertSame('aiqme_test', DB::scalar('select current_database()'));
        $this->assertTrue(Auth::guest());
        $this->assertSame('testing', $this->app->environment());
        $this->assertFalse($this->demoDatabaseExists());
    }

    private function demoDatabaseExists(): bool
    {
        return (bool) DB::scalar('select count(*) from pg_database where datname = ?', [DemoSeeder::DATABASE]);
    }

    /** @return array{tables: int, users: ?int, demo_users: ?int} */
    private function workingDatabase(): array
    {
        $users = Schema::hasTable('users');

        return [
            'tables' => (int) DB::scalar("select count(*) from information_schema.tables where table_schema = 'public'"),
            'users' => $users ? User::count() : null,
            'demo_users' => $users ? User::whereIn('email', [DemoSeeder::ADMIN_EMAIL, DemoSeeder::CLIENT_EMAIL])->count() : null,
        ];
    }
}
