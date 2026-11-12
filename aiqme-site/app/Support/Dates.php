<?php

namespace App\Support;

use DateTimeImmutable;

/**
 * Даты сайта (правка 11.11, J-04): вывод — dd.mm.yyyy;
 * ввод — dd.mm.yyyy, прежний Y-m-d принимается для совместимости; хранение — Y-m-d.
 */
final class Dates
{
    /** Правило проверки поля даты (вместе с nullable). */
    public const RULE = 'date_format:d.m.Y,Y-m-d';

    /** Дата Y-m-d → dd.mm.yyyy; прочие строки — как есть, пустое — null. */
    public static function show(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $date->format('d.m.Y') : $value;
    }

    /** Введённая дата dd.mm.yyyy или Y-m-d → Y-m-d; неверная или пустая — null. */
    public static function toIso(?string $value): ?string
    {
        $value = trim((string) $value);
        foreach (['d.m.Y', 'Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
