<?php

namespace Tests\Feature;

use App\Domain\Pricing\CalculatorSettings;
use App\Domain\Pricing\EstimateService;
use App\Domain\Pricing\StlAnalyzer;
use App\Domain\Pricing\StlException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Паритет ядра JS ↔ PHP (W-11 done[0], §9.2): при каждом запуске вызывается Node-скрипт tests/js/stl-parity.mjs
 * (ядро resources/js/calculator/core/index.js), его JSON сравнивается с StlAnalyzer и EstimateService на корпусе
 * tests/fixtures/stl/ и сгенерированных во временный каталог файлах (случайные binary и ASCII, формы чисел ASCII)
 * при матрице единиц, масштаба, материалов, заполнения и количества. Объём, габарит и масса — относительный допуск 1e-9,
 * отказы, предупреждения и центы — точно. Node — из NODE_BINARY (выставляет bin/check); без него тест проваливается.
 */
class StlParityTest extends TestCase
{
    use RefreshDatabase;

    private const MATRIX = ['units' => ['mm', 'cm', 'in'], 'scales' => [1, 37, 100, 1000], 'infills' => [20, 100], 'quantities' => [1, 3]];

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/aiqme-parity-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
        parent::tearDown();
    }

    private function node(): string
    {
        $node = (string) getenv('NODE_BINARY');
        if ($node === '' || ! is_file($node) || ! is_executable($node)) {
            $this->fail('Тест паритета JS ↔ PHP требует NODE_BINARY — путь к исполняемому node 22 (его выставляет bin/check); сейчас: "'.$node.'".');
        }

        return $node;
    }

    /** @return list<string> */
    private function corpus(): array
    {
        $files = glob(base_path('tests/fixtures/stl/*.stl')) ?: [];
        mt_srand(20261021);
        $random = fn (): float => (mt_rand() / mt_getrandmax() - 0.3) * 200;

        // Случайные треугольники: дробные координаты, не представимые точно во float32.
        $binary = str_repeat("\0", 80).pack('V', 3000);
        $ascii = "solid random\n";
        for ($t = 0; $t < 3000; $t++) {
            $binary .= pack('g3', 0, 0, 0);
            $vertices = [];
            for ($i = 0; $i < 9; $i++) {
                $vertices[] = $random();
            }
            $binary .= pack('g9', ...$vertices)."\0\0";
            if ($t < 800) {
                $ascii .= "facet normal 0 0 0\nouter loop\n";
                foreach (array_chunk($vertices, 3) as $vertex) {
                    $ascii .= 'vertex '.implode(' ', array_map(fn (float $x): string => sprintf('%.17g', $x), $vertex))."\n";
                }
                $ascii .= "endloop\nendfacet\n";
            }
        }
        $generated = ['random-binary.stl' => $binary, 'random-ascii.stl' => $ascii."endsolid random\n"];

        // Формы чисел ASCII и нарушения грамматики (как в tests/js/calculator-core.test.js).
        $cube = (string) file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));
        foreach (['+10', '10.', '1E+1', '.1e2', '0.0000000000000001e17', '-0.0', '1e39', '1e-50', '0x10', '1e', '１０'] as $index => $token) {
            $generated["number-{$index}.stl"] = str_replace('vertex 10 0 0', "vertex {$token} 0 0", $cube);
        }
        $generated['tail.stl'] = $cube."solid second\n";
        $generated['name-tail.stl'] = str_replace('endsolid cube', 'endsolid cube extra words', $cube);
        $generated['vertical-tab.stl'] = str_replace('vertex 10 0 0', "vertex\x0B10 0 0", $cube);
        $generated['nel.stl'] = str_replace('vertex 10 0 0', "vertex\x8510 0 0", $cube);
        $generated['short.stl'] = 'solid';

        foreach ($generated as $name => $bytes) {
            file_put_contents($files[] = $this->directory.'/'.$name, $bytes);
        }

        return $files;
    }

    private function assertClose(float $expected, float $actual, string $message): void
    {
        $this->assertLessThanOrEqual(1e-9 * abs($expected), abs($actual - $expected), "{$message}: {$actual} ≉ {$expected}");
    }

    public function test_php_core_matches_js_core_on_the_whole_corpus(): void
    {
        $node = $this->node();
        $settings = app(CalculatorSettings::class);
        // Настройки, отличные от начальных: дробная цена за грамм, плата за файл, четвёртый материал.
        $settings->save([
            'materials' => ['PLA' => 1.24, 'PETG' => 1.27, 'ABS' => 1.04, 'TPU' => 1.21],
            'rate_per_gram' => '0.137', 'order_fee_cents' => 500, 'file_fee_cents' => 35, 'default_infill' => 20, 'max_dimension_mm' => 250,
        ]);
        $values = $settings->values();
        $files = $this->corpus();

        $process = new Process([$node, base_path('tests/js/stl-parity.mjs')], base_path());
        $process->setInput(json_encode(['files' => $files, 'settings' => $values, 'matrix' => self::MATRIX]));
        $process->mustRun();
        $js = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($files, array_column($js['files'], 'path'));
        $analyzer = new StlAnalyzer;
        $estimates = app(EstimateService::class);
        $accepted = $rejected = $cases = 0;
        $parts = [];
        $warnings = [];
        foreach ($js['files'] as $file) {
            $name = basename($file['path']);
            try {
                $model = $analyzer->analyzeFile($file['path']);
            } catch (StlException $exception) {
                $this->assertSame($file['error'] ?? 'принят в JS', $exception->reason, $name);
                $rejected++;

                continue;
            }
            $this->assertArrayNotHasKey('error', $file, "{$name}: отказ в JS, принят в PHP");
            $accepted++;
            $this->assertSame([$file['triangles'], $file['closed']], [$model->triangles, $model->closed], $name);
            $this->assertClose($file['volume'], $model->volume, "{$name} объём");
            foreach ($model->size as $axis => $size) {
                $this->assertClose($file['size'][$axis], $size, "{$name} габарит {$axis}");
            }

            $this->assertCount(count(self::MATRIX['units']) * count(self::MATRIX['scales']) * count($values['materials'])
                * count(self::MATRIX['infills']) * count(self::MATRIX['quantities']), $file['cases'], $name);
            foreach ($file['cases'] as $case) {
                $label = "{$name} {$case['unit']} {$case['scale']}% {$case['material']} {$case['infill']}% ×{$case['quantity']}";
                $measured = $analyzer->measure($model, $case['unit'], $case['scale'], $values['max_dimension_mm']);
                $this->assertSame($case['warnings'], $measured['warnings'], $label);
                $this->assertClose($case['volumeCm3'], $measured['volume_cm3'], "{$label} см³");
                foreach ($measured['dimensions_mm'] as $axis => $dimension) {
                    $this->assertClose($case['dimensionsMm'][$axis], $dimension, "{$label} мм {$axis}");
                }
                $estimate = $estimates->estimate([['volume_cm3' => $measured['volume_cm3'], 'quantity' => $case['quantity']]], $case['material'], $case['infill']);
                $this->assertClose($case['estimate']['lines'][0]['massGrams'], $estimate['lines'][0]['mass_grams'], "{$label} масса");
                $this->assertSame([$case['estimate']['lines'][0]['cents'], $case['estimate']['fileFeesCents'], $case['estimate']['totalCents']],
                    [$estimate['lines'][0]['cents'], $estimate['file_fees_cents'], $estimate['total_cents']], $label);
                $warnings = [...$warnings, ...$measured['warnings']];
                $cases++;
            }
            $parts[] = ['volume_cm3' => $analyzer->measure($model, 'mm', 100, $values['max_dimension_mm'])['volume_cm3'], 'quantity' => 2];
        }

        // Все принятые файлы одной заявкой: строки, плата за заказ и n × плата за файл.
        $order = $estimates->estimate($parts, 'PLA', $values['default_infill']);
        $this->assertSame(array_column($js['order']['lines'], 'cents'), array_column($order['lines'], 'cents'));
        $this->assertSame([$js['order']['orderFeeCents'], $js['order']['fileFeesCents'], $js['order']['totalCents']],
            [$order['order_fee_cents'], $order['file_fees_cents'], $order['total_cents']]);
        $this->assertSame($accepted * 35, $order['file_fees_cents']);

        // Корпус не вырожден: есть и принятые, и отклонённые файлы, оба предупреждения и ненулевые оценки.
        $this->assertGreaterThanOrEqual(15, $accepted);
        $this->assertGreaterThanOrEqual(15, $rejected);
        $this->assertGreaterThan(2000, $cases);
        $this->assertEqualsCanonicalizing([StlAnalyzer::WARNING_OPEN_MESH, StlAnalyzer::WARNING_EXCEEDS_BUILD_SIZE], array_values(array_unique($warnings)));
    }
}
