<?php

namespace Tests\Feature;

use App\Domain\Pricing\CalculatorSettings;
use App\Domain\Pricing\EstimateService;
use App\Domain\Pricing\StlAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * EstimateService (W-11, §3.1, §9.2): контрольные примеры по начальным настройкам D-09 из БД, half-up на границе
 * половины цента, несколько файлов с платой за заказ и за файл (D-08), снимок настроек в результате.
 */
class EstimateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function estimate(array $parts, string $material = 'PLA', int $infill = 100): array
    {
        return app(EstimateService::class)->estimate($parts, $material, $infill);
    }

    /** @param  array<string, mixed>  $changes */
    private function configure(array $changes): void
    {
        $settings = app(CalculatorSettings::class);
        $settings->save($changes + $settings->values());
    }

    public function test_control_example_of_a_10_mm_cube(): void
    {
        $analyzer = new StlAnalyzer;
        $volume = $analyzer->measure($analyzer->analyzeFile(base_path('tests/fixtures/stl/cube-10mm-binary.stl')), 'mm', 100, 250)['volume_cm3'];

        $result = $this->estimate([['volume_cm3' => $volume, 'quantity' => 1]]);
        $this->assertEqualsWithDelta(1.24, $result['lines'][0]['mass_grams'], 1e-12);
        $this->assertSame([12, 500, 0, 512], [$result['lines'][0]['cents'], $result['order_fee_cents'], $result['file_fees_cents'], $result['total_cents']]);

        $this->assertEqualsWithDelta(0.4464, $this->estimate([['volume_cm3' => $volume, 'quantity' => 1]], 'PLA', 20)['lines'][0]['mass_grams'], 1e-12);
        $three = $this->estimate([['volume_cm3' => $volume, 'quantity' => 3]]);
        $this->assertEqualsWithDelta(3.72, $three['lines'][0]['mass_grams'], 1e-12);
        $this->assertSame(37, $three['lines'][0]['cents'], 'не 3 × 12');
    }

    public function test_half_cent_rounds_up(): void
    {
        $this->configure(['materials' => ['X' => 1.0], 'rate_per_gram' => '0.5']);
        foreach ([[0.25, 13], [0.75, 38], [1.25, 63]] as [$volume, $cents]) {
            $this->assertSame($cents, $this->estimate([['volume_cm3' => $volume, 'quantity' => 1]], 'X')['lines'][0]['cents'], (string) $volume);
        }
        $this->configure(['rate_per_gram' => '0.4999']);
        $this->assertSame(12, $this->estimate([['volume_cm3' => 0.25, 'quantity' => 1]], 'X')['lines'][0]['cents']);
    }

    public function test_several_files_add_order_fee_once_and_file_fee_per_file_with_settings_snapshot(): void
    {
        $this->configure(['file_fee_cents' => 150, 'order_fee_cents' => 700]);
        $result = $this->estimate([['volume_cm3' => 1.0, 'quantity' => 1], ['volume_cm3' => 8.0, 'quantity' => 2]], 'ABS', 20);

        // ABS 1,04 × 0,36: 1 см³ → 0,3744 г → 4 цента; 8 см³ × 2 → 5,9904 г → 60 центов.
        $this->assertSame([4, 60], array_column($result['lines'], 'cents'));
        $this->assertSame([700, 300, 1064], [$result['order_fee_cents'], $result['file_fees_cents'], $result['total_cents']]);
        $this->assertSame(app(CalculatorSettings::class)->values(), $result['settings']);
        $this->assertSame(150, $result['settings']['file_fee_cents']);
        $this->assertArrayNotHasKey('unconfirmed', $result['settings']);
    }

    public function test_invalid_input_is_rejected(): void
    {
        foreach ([
            [[['volume_cm3' => 1.0, 'quantity' => 1]], 'Nylon', 100],
            [[['volume_cm3' => 1.0, 'quantity' => 0]], 'PLA', 100],
            [[['volume_cm3' => 1.0, 'quantity' => 101]], 'PLA', 100],
            [[['volume_cm3' => 0.0, 'quantity' => 1]], 'PLA', 100],
            [[['volume_cm3' => 1.0, 'quantity' => 1]], 'PLA', 101],
            [[], 'PLA', 100],
        ] as [$parts, $material, $infill]) {
            try {
                $this->estimate($parts, $material, $infill);
                $this->fail('принято: '.json_encode([$parts, $material, $infill]));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
