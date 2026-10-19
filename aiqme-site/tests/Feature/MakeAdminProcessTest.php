<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Настоящий процесс без терминала (как claude -p, codex exec, docker compose exec -T):
 * stdin — канал, а не TTY. Процесс пишет в тестовую БД и фиксирует транзакцию сам,
 * поэтому тест не использует RefreshDatabase и удаляет свою запись в конце.
 */
class MakeAdminProcessTest extends TestCase
{
    private const EMAIL = 'process-admin@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(0, User::where('is_admin', true)->count(), 'В тестовой БД не должно быть администратора до теста.');
    }

    protected function tearDown(): void
    {
        User::where('email', self::EMAIL)->delete();

        parent::tearDown();
    }

    public function test_real_process_without_tty_requires_password_stdin(): void
    {
        $process = $this->makeAdmin([], 'process-password-123'."\n");

        $this->assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('--password-stdin', $process->getOutput().$process->getErrorOutput());
        $this->assertFalse(User::where('email', self::EMAIL)->exists());
    }

    public function test_real_process_reads_password_from_stdin(): void
    {
        $process = $this->makeAdmin(['--password-stdin'], 'process-password-123'."\n");

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString('process-password-123', $process->getOutput().$process->getErrorOutput());
        $admin = User::where('email', self::EMAIL)->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('process-password-123', $admin->password));
    }

    /**
     * @param  list<string>  $options
     */
    private function makeAdmin(array $options, string $stdin): Process
    {
        $connection = config('database.connections.pgsql');
        $process = new Process(
            [base_path('bin/php'), base_path('artisan'), 'aiqme:make-admin', self::EMAIL, ...$options],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => $connection['host'],
                'DB_PORT' => (string) $connection['port'],
                'DB_DATABASE' => $connection['database'],
                'DB_USERNAME' => $connection['username'],
                'DB_PASSWORD' => $connection['password'],
                'BCRYPT_ROUNDS' => '4',
            ],
            $stdin,
            60,
        );
        $process->run();

        return $process;
    }
}
