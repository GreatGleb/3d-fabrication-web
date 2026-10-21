<?php

namespace App\Domain\Pricing;

use App\Models\Setting;

/**
 * Настройки калькулятора (§3.1, D-08, D-09, D-24) — запись settings «calculator» (начальные значения кладёт миграция W-11):
 * материалы => плотность г/см³, одна цена за грамм (десятичная строка), плата за заказ и за файл (центы), заполнение
 * по умолчанию (%), габарит оборудования (мм). unconfirmed — тарифы и габарит, которые администратор ещё не изменил.
 * Лимиты файлов (§6.4) — config('aiqme.calculator'), сюда не входят.
 */
final class CalculatorSettings
{
    public const KEY = 'calculator';

    /** @return array{materials: array<string, float>, rate_per_gram: string, order_fee_cents: int, file_fee_cents: int, default_infill: int, max_dimension_mm: int} */
    public function values(): array
    {
        $value = Setting::findOrFail(self::KEY)->value;
        unset($value['unconfirmed']);

        return $value;
    }

    /** @return list<string> */
    public function unconfirmed(): array
    {
        return Setting::findOrFail(self::KEY)->value['unconfirmed'] ?? [];
    }

    /**
     * Сохраняет значения; изменённое снимает пометку «не подтверждено».
     *
     * @param  array{materials: array<string, float>, rate_per_gram: string, order_fee_cents: int, file_fee_cents: int, default_infill: int, max_dimension_mm: int}  $values
     */
    public function save(array $values): void
    {
        $setting = Setting::findOrFail(self::KEY);
        $previous = $setting->value;
        $unconfirmed = array_values(array_filter($previous['unconfirmed'] ?? [], fn (string $key): bool => $values[$key] === $previous[$key]));
        $setting->update(['value' => [...$values, 'unconfirmed' => $unconfirmed]]);
    }
}
