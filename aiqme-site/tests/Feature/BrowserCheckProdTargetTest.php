<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Режим bin/browser-check --base-url (W-20): цель — только 127.0.0.1/localhost, docker-compose.prod.yml проекта aiqme-prod
 * и build/prod-local/.env; иначе отказ до любых действий. Вместо docker в PATH — заглушка, которая записывает вызовы:
 * при отказе её не вызывают ни разу.
 */
class BrowserCheckProdTargetTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/aiqme-browser-check-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/docker', "#!/bin/sh\necho \"\$*\" >> \"\$(dirname \"\$0\")/calls\"\nexit 1\n");
        chmod($this->directory.'/docker', 0755);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    /**
     * @param  list<string>  $arguments
     */
    private function browserCheck(array $arguments): Process
    {
        $process = new Process([base_path('bin/browser-check'), ...$arguments], base_path(),
            ['PATH' => $this->directory.':'.getenv('PATH')]);
        $process->run();

        return $process;
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function refusedTargets(): array
    {
        return [
            'чужой хост' => [['--base-url', 'http://example.com:8792', '--compose-file', 'docker-compose.prod.yml'], 'только http://127.0.0.1'],
            'адрес сети' => [['--base-url', 'http://192.168.1.10:8792', '--compose-file', 'docker-compose.prod.yml'], 'только http://127.0.0.1'],
            'https' => [['--base-url', 'https://localhost:8792', '--compose-file', 'docker-compose.prod.yml'], 'только http://127.0.0.1'],
            'dev-compose' => [['--base-url', 'http://127.0.0.1:8792', '--compose-file', 'docker-compose.dev.yml'], 'только docker-compose.prod.yml'],
            'без compose-файла' => [['--base-url', 'http://127.0.0.1:8792'], 'нужны оба параметра'],
            'лишний аргумент' => [['--base-url', 'http://127.0.0.1:8792', '--compose-file', 'docker-compose.prod.yml', '/en'], 'ожидается ровно'],
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('refusedTargets')]
    public function test_refuses_before_any_action(array $arguments, string $reason): void
    {
        $process = $this->browserCheck($arguments);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('отказан до любых действий', $process->getErrorOutput());
        $this->assertStringContainsString($reason, $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/calls', 'docker вызван до проверки цели');
    }

    public function test_port_must_match_prod_local_env_or_env_must_exist(): void
    {
        $process = $this->browserCheck(['--base-url', 'http://127.0.0.1:1', '--compose-file', 'docker-compose.prod.yml']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertMatchesRegularExpression('/порт 1 не совпадает с AIQME_WEB_PORT|нет build\/prod-local\/\.env/', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/calls');
    }

    public function test_accepted_target_starts_with_prod_compose_commands_only(): void
    {
        $env = base_path('build/prod-local/.env');
        if (! is_file($env) || ! preg_match('/^AIQME_WEB_PORT=(\d+)$/m', file_get_contents($env), $port)) {
            $this->markTestSkipped('нет build/prod-local/.env (локальный prod-compose W-20 не подготовлен)');
        }

        $process = $this->browserCheck(['--base-url', 'http://127.0.0.1:'.$port[1], '--compose-file', 'docker-compose.prod.yml']);

        $this->assertSame(1, $process->getExitCode(), 'заглушка docker завершается ошибкой');
        $this->assertSame("compose --env-file build/prod-local/.env -f docker-compose.prod.yml config --format json\n",
            file_get_contents($this->directory.'/calls'));
    }
}
