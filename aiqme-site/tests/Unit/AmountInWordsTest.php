<?php

namespace Tests\Unit;

use App\Domain\Invoices\AmountInWords;
use PHPUnit\Framework\TestCase;

/** Сумма счёта прописью (правка 02.11, Г5): значения README плана, 108,90 — дословно как в образце заказчика. */
class AmountInWordsTest extends TestCase
{
    public function test_lithuanian_amounts(): void
    {
        $expected = [
            0 => 'Nulis EUR 00 ct',
            100 => 'Vienas EUR 00 ct',
            200 => 'Du EUR 00 ct',
            1000 => 'Dešimt EUR 00 ct',
            1100 => 'Vienuolika EUR 00 ct',
            1900 => 'Devyniolika EUR 00 ct',
            2100 => 'Dvidešimt vienas EUR 00 ct',
            10000 => 'Vienas šimtas EUR 00 ct',
            10890 => 'Vienas šimtas aštuoni EUR 90 ct',
            100000 => 'Vienas tūkstantis EUR 00 ct',
            202100 => 'Du tūkstančiai dvidešimt vienas EUR 00 ct',
            100000000 => 'Vienas milijonas EUR 00 ct',
            1100005 => 'Vienuolika tūkstančių EUR 05 ct',
            2000000 => 'Dvidešimt tūkstančių EUR 00 ct',
            32150000 => 'Trys šimtai dvidešimt vienas tūkstantis penki šimtai EUR 00 ct',
            511200000 => 'Penki milijonai vienas šimtas dvylika tūkstančių EUR 00 ct',
        ];
        foreach ($expected as $cents => $words) {
            $this->assertSame($words, AmountInWords::eur($cents, 'lt'), (string) $cents);
        }
    }

    public function test_english_amounts(): void
    {
        $expected = [
            0 => 'Zero EUR 00 ct',
            100 => 'One EUR 00 ct',
            200 => 'Two EUR 00 ct',
            1000 => 'Ten EUR 00 ct',
            1100 => 'Eleven EUR 00 ct',
            1900 => 'Nineteen EUR 00 ct',
            2100 => 'Twenty-one EUR 00 ct',
            10000 => 'One hundred EUR 00 ct',
            10890 => 'One hundred eight EUR 90 ct',
            100000 => 'One thousand EUR 00 ct',
            202100 => 'Two thousand twenty-one EUR 00 ct',
            100000000 => 'One million EUR 00 ct',
        ];
        foreach ($expected as $cents => $words) {
            $this->assertSame($words, AmountInWords::eur($cents, 'en'), (string) $cents);
        }
    }
}
