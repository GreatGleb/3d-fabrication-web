<?php

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * Оценка по §3.1 в целых центах, тот же порядок операций, что estimate() ядра JS: масса строки
 * V × плотность × (0,2 + 0,8 × заполнение / 100) × qty, строка = floor(масса × цена за грамм × 100 + 0,5) в double,
 * итог = строки + плата за заказ + n × плата за файл. Цена за грамм — одна общая (D-08), плотность — только из настроек.
 * Результат несёт снимок настроек, по которым посчитан.
 */
final class EstimateService
{
    public function __construct(private readonly CalculatorSettings $settings) {}

    /**
     * @param  list<array{volume_cm3: float, quantity: int}>  $parts
     * @return array{lines: list<array{mass_grams: float, cents: int}>, order_fee_cents: int, file_fees_cents: int, total_cents: int, settings: array<string, mixed>}
     */
    public function estimate(array $parts, string $material, int|float $infill): array
    {
        $settings = $this->settings->values();
        $density = (float) ($settings['materials'][$material] ?? NAN);
        $rate = is_numeric($settings['rate_per_gram']) ? (float) $settings['rate_per_gram'] : NAN;
        if (! is_finite($density) || $density <= 0) {
            throw new InvalidArgumentException('material');
        }
        if (! is_finite($rate) || $rate < 0) {
            throw new InvalidArgumentException('ratePerGram');
        }
        if (! is_finite($infill) || $infill < 0 || $infill > 100) {
            throw new InvalidArgumentException('infill');
        }
        if ($parts === []) {
            throw new InvalidArgumentException('parts');
        }

        $lines = array_map(function (array $part) use ($density, $rate, $infill): array {
            ['volume_cm3' => $volume, 'quantity' => $quantity] = $part;
            if (! is_float($volume) || ! is_finite($volume) || $volume <= 0) {
                throw new InvalidArgumentException('volumeCm3');
            }
            if (! is_int($quantity) || $quantity < 1 || $quantity > 100) {
                throw new InvalidArgumentException('quantity');
            }
            $mass = $volume * $density * (0.2 + 0.8 * $infill / 100) * $quantity;

            return ['mass_grams' => $mass, 'cents' => (int) floor($mass * $rate * 100 + 0.5)];
        }, $parts);
        $fileFees = count($parts) * $settings['file_fee_cents'];

        return [
            'lines' => $lines,
            'order_fee_cents' => $settings['order_fee_cents'],
            'file_fees_cents' => $fileFees,
            'total_cents' => array_sum(array_column($lines, 'cents')) + $settings['order_fee_cents'] + $fileFees,
            'settings' => $settings,
        ];
    }
}
