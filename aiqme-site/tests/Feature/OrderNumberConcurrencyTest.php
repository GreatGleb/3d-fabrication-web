<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Параллельная выдача порядковых номеров (§3.1, W-12): несколько отдельных процессов PHP со своими подключениями к
 * PostgreSQL одновременно берут номера OrderNumber::next в своих транзакциях (каждая держит строку счётчика) — номера
 * без повторов и пропусков. Без RefreshDatabase: транзакции процессов должны видеть закоммиченный счётчик;
 * счётчик теста — с уникальным именем и удаляется после теста.
 */
class OrderNumberConcurrencyTest extends TestCase
{
    private const PROCESSES = 4;

    private const PER_PROCESS = 15;

    public function test_parallel_processes_get_unique_consecutive_numbers(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $counter = 'test-parallel-'.Str::uuid();
        $start = microtime(true) + 2;
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            [$counter, $start, $count] = [$argv[1], (float) $argv[2], (int) $argv[3]];
            time_sleep_until($start);
            $numbers = [];
            for ($i = 0; $i < $count; $i++) {
                $numbers[] = Illuminate\Support\Facades\DB::transaction(function () use ($counter) {
                    $number = App\Domain\Orders\OrderNumber::next($counter);
                    usleep(2000); // строка счётчика заблокирована до commit: соседние процессы ждут
                    return $number;
                });
            }
            echo json_encode($numbers);
            PHP;

        try {
            $processes = array_map(function () use ($script, $counter, $start): Process {
                $process = new Process([PHP_BINARY, '-r', $script, '--', $counter, (string) $start, (string) self::PER_PROCESS], base_path(), null, null, 60);
                $process->start();

                return $process;
            }, range(1, self::PROCESSES));
            $numbers = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $numbers[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
        } finally {
            DB::table('counters')->where('name', $counter)->delete();
        }

        $all = array_merge(...$numbers);
        sort($all);
        $this->assertSame(range(1, self::PROCESSES * self::PER_PROCESS), $all);
        // Номера процессов перемежаются — выдача действительно шла одновременно.
        foreach ($numbers as $sequence) {
            $this->assertNotSame(range($sequence[0], $sequence[0] + self::PER_PROCESS - 1), $sequence);
        }
    }

    public function test_first_number_comes_from_configuration(): void
    {
        $counter = 'test-first-'.Str::uuid();
        try {
            $this->assertSame([1001, 1002], [OrderNumber::next($counter, 1001), OrderNumber::next($counter, 1001)]);
        } finally {
            DB::table('counters')->where('name', $counter)->delete();
        }
    }
}
