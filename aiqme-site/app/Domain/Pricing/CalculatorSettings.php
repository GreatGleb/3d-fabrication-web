<?php

namespace App\Domain\Pricing;

use App\Models\Setting;

/**
 * Настройки калькулятора (§3.1, D-08, D-09, D-24) — запись settings «calculator» (начальные значения кладёт миграция W-11):
 * материалы => плотность г/см³, одна цена за грамм (десятичная строка), плата за заказ и за файл (центы), заполнение
 * по умолчанию (%), габарит оборудования (мм). unconfirmed — тарифы и габарит, которые администратор ещё не изменил.
 * Фрезеровка и гравировка (правка 02.11, Г6): списки материалов {lt, en}, толщин и глубин (мм) и предел [2000, 4000] мм.
 * Лимиты файлов (§6.4) — config('aiqme.calculator'), сюда не входят.
 */
final class CalculatorSettings
{
    public const KEY = 'calculator';

    /** @return array{materials: array<string, float>, rate_per_gram: string, order_fee_cents: int, file_fee_cents: int, default_infill: int, max_dimension_mm: int, milling_materials: list<array{lt: string, en: string}>, milling_thicknesses_mm: list<int|float>, engraving_materials: list<array{lt: string, en: string}>, engraving_depths_mm: list<int|float>, milling_max_mm: array{int, int}} */
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
     * Сохраняет значения; изменённое снимает пометку «не подтверждено». Ключи, которых нет в $values (списки фрезеровки
     * и гравировки, пока их нет в форме админки), остаются прежними.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $setting = Setting::findOrFail(self::KEY);
        $previous = $setting->value;
        $unconfirmed = array_values(array_filter($previous['unconfirmed'] ?? [], fn (string $key): bool => $values[$key] === $previous[$key]));
        $setting->update(['value' => [...$previous, ...$values, 'unconfirmed' => $unconfirmed]]);
    }
}
