<?php

namespace App\Domain\Invoices;

use App\Domain\Invoices\Models\Invoice;
use App\Domain\Orders\OrderNumber;
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

        return [self::series($prefix, $year), OrderNumber::next(self::counter($prefix, $year))];
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

    private static function counter(string $prefix, int $year): string
    {
        return 'invoices:'.$prefix.':'.$year;
    }
}
