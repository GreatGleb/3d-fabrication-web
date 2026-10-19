<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * aiqme-site/bin/php: расширения runtime эксперимента и лимиты §6.4 из bin/php.d/,
 * в том числе в дочерних процессах (artisan serve запускает PHP_BINARY -S).
 */
class PhpRuntimeTest extends TestCase
{
    private const LIMITS = [
        'upload_max_filesize' => '25M',
        'post_max_size' => '260M',
        'max_file_uploads' => '20',
        'memory_limit' => '512M',
        'max_execution_time' => '120',
    ];

    public function test_wrapper_loads_required_extensions(): void
    {
        $modules = $this->run_(['-m']);

        foreach (['bcmath', 'intl', 'gd', 'pdo_pgsql', 'pgsql', 'zip'] as $extension) {
            $this->assertMatchesRegularExpression("/^{$extension}$/m", $modules);
        }
    }

    public function test_wrapper_applies_upload_limits(): void
    {
        $info = $this->run_(['-i']);

        foreach (self::LIMITS as $name => $value) {
            if ($name === 'max_execution_time') {
                continue; // CLI всегда 0; проверяется на встроенном сервере ниже.
            }
            $this->assertMatchesRegularExpression("/^{$name} => {$value} => {$value}$/m", $info);
        }
    }

    public function test_child_processes_inherit_limits(): void
    {
        // Дочерний /usr/bin/php, запущенный из bin/php, получает тот же PHP_INI_SCAN_DIR и LD_LIBRARY_PATH.
        $child = $this->run_(['-r', 'passthru(escapeshellarg(PHP_BINARY)." -r ".escapeshellarg("echo ini_get(\"post_max_size\"), \" \", ini_get(\"upload_max_filesize\"), \" \", extension_loaded(\"pdo_pgsql\") ? \"pgsql\" : \"-\";"));']);

        $this->assertSame('260M 25M pgsql', trim($child));
    }

    public function test_web_server_sapi_applies_all_limits(): void
    {
        $docroot = sys_get_temp_dir().'/aiqme-php-runtime-'.bin2hex(random_bytes(4));
        mkdir($docroot);
        file_put_contents($docroot.'/index.php', '<?php $k = '.var_export(array_keys(self::LIMITS), true).'; echo json_encode(array_combine($k, array_map("ini_get", $k)));');

        // bin/php делает exec, поэтому процесс один и stop() его завершает (artisan serve запускает так же PHP_BINARY -S).
        $port = $this->freePort();
        $server = new Process([base_path('bin/php'), '-S', "127.0.0.1:{$port}", '-t', $docroot]);
        $server->start();

        try {
            $body = null;
            for ($i = 0; $i < 50 && $body === null; $i++) {
                usleep(100_000);
                $body = @file_get_contents("http://127.0.0.1:{$port}/index.php") ?: null;
            }
            $this->assertNotNull($body, $server->getErrorOutput());

            $this->assertSame(self::LIMITS, json_decode($body, true));
        } finally {
            $server->stop(1);
            @unlink($docroot.'/index.php');
            @rmdir($docroot);
        }
    }

    public function test_wrapper_has_no_absolute_paths_of_this_checkout(): void
    {
        $script = file_get_contents(base_path('bin/php'));

        $this->assertStringNotContainsString(dirname(base_path()), $script);
        $this->assertStringContainsString('experiments/lunar-aiqme/runtime', $script);
        $this->assertStringContainsString('php.d', $script);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run_(array $arguments): string
    {
        $process = new Process([base_path('bin/php'), ...$arguments]);
        $process->mustRun();

        return $process->getOutput();
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}
