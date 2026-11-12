<?php

namespace App\Domain\Invoices;

use App\Domain\Invoices\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Нумерация счетов по серии заказчика (правка 05.11, H-06): префикс из настроек (AIQ) + последняя цифра года выписки +
 * номер не короче 4 цифр — AIQ60008, после 9999 — AIQ610000. Счётчик counters «invoices:<префикс>:<год>» свой на каждый
 * год, первый номер года — 1 (AIQ70001). Серия счёта в записи — префикс с цифрой года (AIQ6), номер — число. Следующий
 * номер текущего года задаёт администратор (Settings); номер не больше уже выданного в этой серии задать нельзя.
 */
final class InvoiceNumber
{
    /** Серия года: префикс + последняя цифра года (AIQ6). */
    public static function series(string $prefix, int $year): string
    {
        return $prefix.($year % 10);
    }

    /** Выдаёт номер текущего года (в транзакции выпуска; откат номер не расходует). @return array{string, int} серия, номер */
    public static function issue(string $prefix): array
    {
        $year = now()->year;

        return [self::series($prefix, $year), self::next(self::counter($prefix, $year))];
    }

    /** Номер, который получит следующий счёт текущего года. */
    public static function upcoming(string $prefix): int
    {
        return (int) DB::table('counters')->where('name', self::counter($prefix, now()->year))->value('value') + 1;
    }

    /** Наибольший номер, уже выданный в серии текущего года (0 — ни одного). */
    public static function lastIssued(string $prefix): int
    {
        return (int) Invoice::where('series', self::series($prefix, now()->year))->max('number');
    }

    /** Следующий номер текущего года; проверку «больше выданного» делает вызывающий (lastIssued). */
    public static function setNext(string $prefix, int $next): void
    {
        DB::table('counters')->upsert(['name' => self::counter($prefix, now()->year), 'value' => $next - 1], ['name'], ['value']);
    }

    /**
     * Следующее значение счётчика counters: атомарный UPDATE … SET value = value + 1 RETURNING value в транзакции
     * выпуска — при откате номер не расходуется, параллельные выпуски получают разные номера; строку создаёт первая
     * выдача (первое значение — 1). До правки 12.11 (K-05) — OrderNumber::next, общий с порядковым номером заказа.
     */
    public static function next(string $counter): int
    {
        DB::table('counters')->insertOrIgnore(['name' => $counter, 'value' => 0]);

        return (int) DB::selectOne('update counters set value = value + 1 where name = ? returning value', [$counter])->value;
    }

    private static function counter(string $prefix, int $year): string
    {
        return 'invoices:'.$prefix.':'.$year;
    }
}
