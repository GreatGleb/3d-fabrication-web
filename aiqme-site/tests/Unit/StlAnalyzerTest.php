<?php

namespace Tests\Unit;

use App\Domain\Pricing\StlAnalyzer;
use App\Domain\Pricing\StlException;
use App\Domain\Pricing\StlModel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * StlAnalyzer (W-11, §3.1, §9.2 «Ядро STL»): те же случаи, что tests/js/calculator-core.test.js для ядра JS, на корпусе
 * tests/fixtures/stl/. Паритет чисел с JS — tests/Feature/StlParityTest.php, границы 1 000 000 треугольников и 50 МиБ —
 * tests/Feature/StlAnalyzerLimitsTest.php.
 */
class StlAnalyzerTest extends TestCase
{
    private StlAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new StlAnalyzer;
    }

    private static function fixture(string $name): string
    {
        return __DIR__.'/../fixtures/stl/'.$name;
    }

    private function parse(string $name): StlModel
    {
        return $this->analyzer->analyzeFile(self::fixture($name));
    }

    private function assertRejected(string $reason, callable $analyze): void
    {
        try {
            $analyze();
        } catch (StlException $exception) {
            $this->assertSame($reason, $exception->reason);

            return;
        }
        $this->fail("ожидался отказ {$reason}");
    }

    private static function cubeWith(string $search, string $replace): string
    {
        $cube = (string) file_get_contents(self::fixture('cube-10mm-ascii.stl'));

        return str_replace($search, $replace, $cube);
    }

    public function test_ascii_and_binary_cube_give_same_volume_size_and_closed_mesh(): void
    {
        $this->assertSame($this->parse('cube-10mm-ascii.stl')->volume, $this->parse('cube-10mm-binary.stl')->volume);
        foreach (['cube-10mm-ascii.stl', 'cube-10mm-binary.stl', 'cube-10mm-binary-solid-header.stl'] as $name) {
            $model = $this->parse($name);
            $this->assertSame(12, $model->triangles, $name);
            $this->assertEqualsWithDelta(1000, $model->volume, 1e-9, $name);
            $this->assertSame([10.0, 10.0, 10.0], $model->size, $name);
            $this->assertTrue($model->closed, $name);
        }
    }

    public function test_reversed_orientation_keeps_volume(): void
    {
        $this->assertSame($this->parse('cube-10mm-ascii.stl')->volume, $this->parse('cube-10mm-reversed-ascii.stl')->volume);
    }

    public function test_volume_uses_float32_coordinates(): void
    {
        // Тот же тетраэдр, посчитанный независимо: |(b−a)·((c−a)×(d−a))| / 6.
        $volume = function (callable $coordinate): float {
            [$a, $b, $c, $d] = array_map(fn (array $p): array => array_map($coordinate, $p),
                [[0.1, 0.2, 0.3], [12.345678, 0.5, 1.5], [0.7, 9.87654321, 0.1], [1.1, 2.2, 7.77777777]]);
            $u = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
            $v = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];
            $w = [$d[0] - $a[0], $d[1] - $a[1], $d[2] - $a[2]];

            return abs($u[0] * ($v[1] * $w[2] - $v[2] * $w[1]) + $u[1] * ($v[2] * $w[0] - $v[0] * $w[2]) + $u[2] * ($v[0] * $w[1] - $v[1] * $w[0])) / 6;
        };
        $float32 = $volume(fn (float $x): float => unpack('g', pack('g', $x))[1]);
        $double = $volume(fn (float $x): float => $x);

        $model = $this->parse('tetrahedron-fractional-ascii.stl');
        $this->assertEqualsWithDelta($float32, $model->volume, 1e-12 * $float32);
        $this->assertGreaterThan(1e-9 * $double, abs($double - $model->volume), 'координаты не приведены к float32');
        $this->assertTrue($model->closed);
    }

    public function test_negative_zero_and_zero_are_one_vertex(): void
    {
        foreach (['tetrahedron-negative-zero-ascii.stl', 'tetrahedron-negative-zero-binary.stl'] as $name) {
            $model = $this->parse($name);
            $this->assertTrue($model->closed, $name);
            $this->assertEqualsWithDelta(1000 / 6, $model->volume, 1e-9, $name);
        }
    }

    public function test_open_tetrahedron_is_a_warning_not_a_silent_success(): void
    {
        $model = $this->parse('tetrahedron-open-ascii.stl');
        $this->assertFalse($model->closed);
        $this->assertSame([StlAnalyzer::WARNING_OPEN_MESH], $this->analyzer->measure($model, 'mm', 100, 250)['warnings']);
        $this->assertSame([], $this->analyzer->measure($this->parse('cube-10mm-ascii.stl'), 'mm', 100, 250)['warnings']);
    }

    /** @return array<string, array{string, string}> */
    public static function rejectedFixtures(): array
    {
        return [
            'hex' => ['hex-number-ascii.stl', 'number'],
            'float32 overflow' => ['float32-overflow-ascii.stl', 'coordinate'],
            'NaN' => ['nan-binary.stl', 'coordinate'],
            'Infinity' => ['infinity-binary.stl', 'coordinate'],
            'truncated' => ['truncated-binary.stl', 'structure'],
            'wrong count' => ['wrong-count-binary.stl', 'structure'],
            'zero triangles' => ['zero-triangles-binary.stl', 'triangle_count'],
            'no facets' => ['no-facets-ascii.stl', 'triangle_count'],
        ];
    }

    #[DataProvider('rejectedFixtures')]
    public function test_invalid_files_are_rejected(string $name, string $reason): void
    {
        $this->assertRejected($reason, fn () => $this->parse($name));
        $this->assertRejected($reason, fn () => $this->analyzer->analyze((string) file_get_contents(self::fixture($name))));
    }

    public function test_ascii_number_pattern_is_shared_with_js(): void
    {
        foreach (['10', '+10', '10.', '10.0', '1e1', '1E+1', '100e-1', '.1e2'] as $token) {
            $this->assertEqualsWithDelta(1000, $this->analyzer->analyze(self::cubeWith('vertex 10 0 0', "vertex {$token} 0 0"))->volume, 1e-9, $token);
        }
        foreach (['0x10', '1_0', '10,0', 'NaN', 'Infinity', '-inf', '1e', 'e1', '.', '+', '1..0', '１０'] as $token) {
            $this->assertRejected('number', fn () => $this->analyzer->analyze(self::cubeWith('vertex 10 0 0', "vertex {$token} 0 0")));
        }
    }

    public function test_ascii_grammar_and_tail_after_endsolid(): void
    {
        $cube = (string) file_get_contents(self::fixture('cube-10mm-ascii.stl'));
        $this->assertRejected('structure', fn () => $this->analyzer->analyze($cube."solid second\n"));
        $this->assertRejected('structure', fn () => $this->analyzer->analyze(self::cubeWith('outer loop', 'outer')));
        $this->assertRejected('structure', fn () => $this->analyzer->analyze(self::cubeWith('endsolid cube', '')));
        $this->assertRejected('structure', fn () => $this->analyzer->analyze(self::cubeWith("vertex 10 0 0\n", '')));
        $this->assertRejected('structure', fn () => $this->analyzer->analyze('hello'));
        // Вертикальная табуляция — пробел ASCII, а \x85 (NEL) — нет, как в ядре JS.
        $this->assertEqualsWithDelta(1000, $this->analyzer->analyze(self::cubeWith('vertex 10 0 0', "vertex\x0B10 0 0"))->volume, 1e-9);
        $this->assertRejected('structure', fn () => $this->analyzer->analyze(self::cubeWith('vertex 10 0 0', "vertex\x8510 0 0")));
    }

    public function test_units_and_scale_apply_to_volume_cubed_and_size_linearly(): void
    {
        $cube = $this->parse('cube-10mm-ascii.stl');
        foreach ([['mm', 100, 10, 1], ['cm', 100, 100, 1000], ['in', 100, 254, 16387.064], ['mm', 200, 20, 8], ['mm', 1, 0.1, 1e-6], ['cm', 1000, 1000, 1e6]] as [$unit, $scale, $size, $cm3]) {
            $measured = $this->analyzer->measure($cube, $unit, $scale, 5000);
            $this->assertEqualsWithDelta($cm3, $measured['volume_cm3'], 1e-9 * $cm3, "{$unit} {$scale}");
            foreach ($measured['dimensions_mm'] as $dimension) {
                $this->assertEqualsWithDelta($size, $dimension, 1e-9 * $size, "{$unit} {$scale}");
            }
        }
    }

    public function test_build_size_is_compared_per_dimension_and_only_warns(): void
    {
        $cube = $this->parse('cube-10mm-ascii.stl');
        $this->assertSame([], $this->analyzer->measure($cube, 'cm', 100, 100)['warnings']);
        $this->assertSame([StlAnalyzer::WARNING_EXCEEDS_BUILD_SIZE], $this->analyzer->measure($cube, 'cm', 101, 100)['warnings']);
        $open = $this->parse('tetrahedron-open-ascii.stl');
        $this->assertSame([StlAnalyzer::WARNING_OPEN_MESH, StlAnalyzer::WARNING_EXCEEDS_BUILD_SIZE], $this->analyzer->measure($open, 'in', 1000, 10)['warnings']);
    }

    public function test_invalid_unit_scale_and_build_size_are_rejected(): void
    {
        $cube = $this->parse('cube-10mm-ascii.stl');
        foreach ([['m', 100, 250], ['mm', 0.5, 250], ['mm', 1001, 250], ['mm', NAN, 250], ['mm', 100, 0], ['mm', 100, INF]] as [$unit, $scale, $max]) {
            try {
                $this->analyzer->measure($cube, $unit, $scale, $max);
                $this->fail("принято: {$unit} {$scale} {$max}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_size_limit_is_checked_before_reading_the_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'aiqme-stl-');
        try {
            // Разрежённый файл на 1 байт больше лимита: отказ по размеру без чтения тела.
            $handle = fopen($path, 'w');
            ftruncate($handle, StlAnalyzer::MAX_BYTES + 1);
            fclose($handle);
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $this->assertRejected('size', fn () => $this->analyzer->analyzeFile($path));
            $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $before);

            // Счётчик binary MAX_TRIANGLES + 1 при длине 84 + 50·N больше MAX_BYTES: тоже отказ по размеру, тело не читается.
            $handle = fopen($path, 'w');
            fwrite($handle, str_repeat("\0", 80).pack('V', StlAnalyzer::MAX_TRIANGLES + 1));
            ftruncate($handle, 84 + (StlAnalyzer::MAX_TRIANGLES + 1) * 50);
            fclose($handle);
            memory_reset_peak_usage();
            $this->assertRejected('size', fn () => $this->analyzer->analyzeFile($path));
            $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $before);
        } finally {
            @unlink($path);
        }
    }
}
