<?php

namespace App\Domain\Pricing;

use App\Models\Setting;

/**
 * Настройки калькулятора (§3.1, D-08, D-09, D-24) — запись settings «calculator» (начальные значения кладёт миграция W-11):
 * материалы => плотность г/см³, одна цена за грамм (десятичная строка), плата за заказ и за файл (центы), заполнение
 * по умолчанию (%), габарит оборудования (мм). unconfirmed — тарифы и габарит, которые администратор ещё не изменил.
 * Фрезеровка и гравировка (правка 02.11, Г6): списки материалов {lt, en} и предел [2000, 4000] мм. Толщина фрезеровки и
 * глубина гравировки вводятся числом (правка 05.11, H-05): пределы ввода — константы ниже, списков в настройках нет.
 * Лимиты файлов (§6.4) — config('aiqme.calculator'), сюда не входят.
 */
final class CalculatorSettings
{
    public const KEY = 'calculator';

    /** Пределы ввода, мм (допущение плана 05.11, H-05; заказчик их не назвал): толщина фрезеровки и глубина гравировки. */
    public const THICKNESS_MM = [0.1, 60]; // правка 11.11, J-01 (было 200)

    public const DEPTH_MM = [0.01, 20];

    /** Материал фрезеровки, при котором толщина необязательна (H-05). */
    public const MILLING_CONSULT = 'Reikia konsultacijos';

    /** @return array{materials: array<string, float>, rate_per_gram: string, order_fee_cents: int, file_fee_cents: int, default_infill: int, max_dimension_mm: int, milling_materials: list<array{lt: string, en: string}>, engraving_materials: list<array{lt: string, en: string}>, milling_max_mm: array{int, int}} */
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
     * Сохраняет значения; изменённое снимает пометку «не подтверждено». Ключи, которых нет в $values, остаются прежними.
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
