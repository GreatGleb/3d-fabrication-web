<?php

namespace App\Domain\Invoices;

use App\Models\Setting;

/**
 * Реквизиты продавца, VAT и нумерация счетов (§3.5, D-05) — запись settings «invoices». Значений по умолчанию нет:
 * пока запись не сохранена администратором, выпуск блокируется (вымышленные значения не подставляются). Плательщик VAT —
 * ставка в целых процентах и VAT-код; не плательщик — VAT 0 и пометка для счёта. Демо-ставка 21 % не используется.
 * Банк, SWIFT, IBAN, почта, телефон, сайт, условия поставки и «кто выписал» (правка 02.11, Г5) — необязательные: пустые
 * в счёте не выводятся, complete() их не требует; в записях, сохранённых до правки, этих ключей нет. series — префикс серии
 * (AIQ); first_number записей до правки 05.11 (H-06) не используется: следующий номер года — счётчик InvoiceNumber.
 */
final class SellerSettings
{
    public const KEY = 'invoices';

    /** @return array{name: string, company_code: string, vat_code: ?string, address: string, vat_payer: bool, vat_rate: int, vat_note: ?string, series: string, bank?: ?string, swift?: ?string, iban?: ?string, email?: ?string, phone?: ?string, website?: ?string, delivery_terms?: ?string, issued_by?: ?string}|null */
    public function values(): ?array
    {
        $value = Setting::find(self::KEY)?->value;

        return is_array($value) ? $value : null;
    }

    /** Реквизиты, VAT-статус и нумерация заданы — счёт можно выпускать. */
    public function complete(): bool
    {
        $value = $this->values();

        return $value !== null
            && trim($value['name']) !== '' && trim($value['company_code']) !== '' && trim($value['address']) !== ''
            && trim($value['series']) !== ''
            && ($value['vat_payer']
                ? $value['vat_rate'] >= 1 && trim((string) $value['vat_code']) !== ''
                : trim((string) $value['vat_note']) !== '');
    }

    /** @param  array{name: string, company_code: string, vat_code: ?string, address: string, vat_payer: bool, vat_rate: int, vat_note: ?string, series: string, bank?: ?string, swift?: ?string, iban?: ?string, email?: ?string, phone?: ?string, website?: ?string, delivery_terms?: ?string, issued_by?: ?string}  $values */
    public function save(array $values): void
    {
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $values]);
    }
}
