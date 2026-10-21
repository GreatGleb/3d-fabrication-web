<?php

namespace Tests\Feature;

use App\Domain\Pricing\StlAnalyzer;
use App\Domain\Pricing\StlException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Границы файла StlAnalyzer (W-11 done[1], §6.4, §9.2): binary 500 000 треугольников принимается в подпроцессе
 * php -d memory_limit=512M (значение §6.4 и W-20), 500 001 — отказ; файл ровно 26 214 400 байт (ASCII с пробелами) —
 * принят, на 1 байт больше — отказ. Файлы генерируются во временный каталог.
 */
class StlAnalyzerLimitsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/aiqme-limits-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
        parent::tearDown();
    }

    /** Замкнутые тетраэдры решёткой (4 треугольника каждый, грани наружу) и при необходимости лишний треугольник. */
    private function tetrahedra(string $name, int $triangles): string
    {
        $path = $this->directory.'/'.$name;
        $handle = fopen($path, 'w');
        fwrite($handle, str_repeat("\0", 80).pack('V', $triangles));
        $normal = pack('g3', 0, 0, 0);
        for ($t = 0; $t + 4 <= $triangles; $t += 4) {
            $i = intdiv($t, 4);
            [$x, $y, $z] = [($i % 50) * 3, (intdiv($i, 50) % 50) * 3, intdiv($i, 2500) * 3];
            [$a, $b, $c, $d] = [[$x, $y, $z], [$x + 2, $y, $z], [$x, $y + 2, $z], [$x, $y, $z + 2]];
            $chunk = '';
            foreach ([[$a, $c, $b], [$a, $b, $d], [$a, $d, $c], [$b, $c, $d]] as $face) {
                $chunk .= $normal.pack('g9', ...$face[0], ...$face[1], ...$face[2])."\0\0";
            }
            fwrite($handle, $chunk);
        }
        for (; $t < $triangles; $t++) {
            fwrite($handle, $normal.pack('g9', 0, 0, 0, 1, 0, 0, 0, 1, 0)."\0\0");
        }
        fclose($handle);
        $this->assertSame(84 + 50 * $triangles, filesize($path));

        return $path;
    }

    public function test_500000_binary_triangles_are_analyzed_with_512m_memory_limit(): void
    {
        $path = $this->tetrahedra('max.stl', StlAnalyzer::MAX_TRIANGLES);
        $script = 'require "vendor/autoload.php"; $start = microtime(true);'
            .' $model = (new App\Domain\Pricing\StlAnalyzer)->analyzeFile($argv[1]);'
            .' echo json_encode(["triangles" => $model->triangles, "volume" => $model->volume, "closed" => $model->closed,'
            .' "peak" => memory_get_peak_usage(true), "limit" => ini_get("memory_limit"), "seconds" => microtime(true) - $start]);';
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $script, '--', $path], base_path(), null, null, 120);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        fwrite(STDERR, sprintf("\nStlAnalyzer 500 000 треугольников: пиковая память %.1f МиБ, %.2f с\n", $result['peak'] / 1048576, $result['seconds']));
        $this->assertSame('512M', $result['limit']);
        $this->assertSame(500000, $result['triangles']);
        $this->assertTrue($result['closed']);
        // 125 000 тетраэдров с рёбрами 2: объём 125 000 × 8 / 6.
        $this->assertEqualsWithDelta(125000 * 8 / 6, $result['volume'], 1e-6 * $result['volume']);
    }

    public function test_500001_binary_triangles_are_rejected(): void
    {
        $path = $this->tetrahedra('over.stl', StlAnalyzer::MAX_TRIANGLES + 1);
        try {
            (new StlAnalyzer)->analyzeFile($path);
            $this->fail('принят файл на 500 001 треугольник');
        } catch (StlException $exception) {
            $this->assertSame('triangle_count', $exception->reason);
        }
    }

    public function test_file_of_exactly_26214400_bytes_is_accepted_and_one_byte_more_is_rejected(): void
    {
        $cube = (string) file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));
        $at = strpos($cube, 'endsolid');
        $padded = function (int $size) use ($cube, $at): string {
            file_put_contents($path = $this->directory."/{$size}.stl", substr($cube, 0, $at).str_repeat(' ', $size - strlen($cube)).substr($cube, $at));

            return $path;
        };

        $exact = $padded(26214400);
        $this->assertSame(26214400, filesize($exact));
        $this->assertEqualsWithDelta(1000, (new StlAnalyzer)->analyzeFile($exact)->volume, 1e-9);
        try {
            (new StlAnalyzer)->analyzeFile($padded(26214401));
            $this->fail('принят файл на 1 байт больше лимита');
        } catch (StlException $exception) {
            $this->assertSame('size', $exception->reason);
        }
    }
}
