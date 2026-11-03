<?php

namespace App\Domain\Invoices;

/**
 * Сумма счёта прописью (правка 02.11, Г5): евро словами с заглавной буквы, центы двумя цифрами и «ct» — как в образце
 * заказчика «Vienas šimtas aštuoni EUR 90 ct» (LT) / «One hundred eight EUR 90 ct» (EN). Своим кодом, без пакетов;
 * до 999 999 999 EUR.
 */
final class AmountInWords
{
    private const LT_UNITS = ['', 'vienas', 'du', 'trys', 'keturi', 'penki', 'šeši', 'septyni', 'aštuoni', 'devyni',
        'dešimt', 'vienuolika', 'dvylika', 'trylika', 'keturiolika', 'penkiolika', 'šešiolika', 'septyniolika', 'aštuoniolika', 'devyniolika'];

    private const LT_TENS = ['', '', 'dvidešimt', 'trisdešimt', 'keturiasdešimt', 'penkiasdešimt', 'šešiasdešimt', 'septyniasdešimt', 'aštuoniasdešimt', 'devyniasdešimt'];

    /** Формы «один / несколько / много (0, 10–19 на конце)». */
    private const LT_SCALES = [
        1_000_000 => ['milijonas', 'milijonai', 'milijonų'],
        1_000 => ['tūkstantis', 'tūkstančiai', 'tūkstančių'],
    ];

    private const EN_UNITS = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
        'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const EN_TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    private const EN_SCALES = [1_000_000 => 'million', 1_000 => 'thousand'];

    public static function eur(int $cents, string $locale): string
    {
        $euros = intdiv($cents, 100);
        $words = $locale === 'lt' ? self::lithuanian($euros) : self::english($euros);

        return mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1).' EUR '.sprintf('%02d', $cents % 100).' ct';
    }

    private static function lithuanian(int $number): string
    {
        if ($number === 0) {
            return 'nulis';
        }
        $words = [];
        foreach (self::LT_SCALES as $scale => [$one, $few, $many]) {
            $count = intdiv($number, $scale);
            $number %= $scale;
            if ($count > 0) {
                $last = $count % 100;
                $words[] = self::lithuanianHundreds($count);
                $words[] = match (true) {
                    $last % 10 === 0 || ($last >= 10 && $last <= 19) => $many,
                    $last % 10 === 1 => $one,
                    default => $few,
                };
            }
        }
        $words[] = self::lithuanianHundreds($number);

        return implode(' ', array_filter($words));
    }

    private static function lithuanianHundreds(int $number): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;

        return implode(' ', array_filter([
            $hundreds > 0 ? self::LT_UNITS[$hundreds].' '.($hundreds === 1 ? 'šimtas' : 'šimtai') : '',
            $rest < 20 ? self::LT_UNITS[$rest] : self::LT_TENS[intdiv($rest, 10)].($rest % 10 > 0 ? ' '.self::LT_UNITS[$rest % 10] : ''),
        ]));
    }

    private static function english(int $number): string
    {
        if ($number === 0) {
            return 'zero';
        }
        $words = [];
        foreach (self::EN_SCALES as $scale => $name) {
            $count = intdiv($number, $scale);
            $number %= $scale;
            if ($count > 0) {
                $words[] = self::englishHundreds($count).' '.$name;
            }
        }
        $words[] = self::englishHundreds($number);

        return implode(' ', array_filter($words));
    }

    private static function englishHundreds(int $number): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;

        return implode(' ', array_filter([
            $hundreds > 0 ? self::EN_UNITS[$hundreds].' hundred' : '',
            $rest < 20 ? self::EN_UNITS[$rest] : self::EN_TENS[intdiv($rest, 10)].($rest % 10 > 0 ? '-'.self::EN_UNITS[$rest % 10] : ''),
        ]));
    }
}
