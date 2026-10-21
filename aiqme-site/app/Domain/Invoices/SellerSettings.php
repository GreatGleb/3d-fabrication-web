<?php

namespace App\Domain\Invoices;

use App\Models\Setting;

/**
 * Реквизиты продавца, VAT и нумерация счетов (§3.5, D-05) — запись settings «invoices». Значений по умолчанию нет:
 * пока запись не сохранена администратором, выпуск блокируется (вымышленные значения не подставляются). Плательщик VAT —
 * ставка в целых процентах и VAT-код; не плательщик — VAT 0 и пометка для счёта. Демо-ставка 21 % не используется.
 */
final class SellerSettings
{
    public const KEY = 'invoices';

    /** @return array{name: string, company_code: string, vat_code: ?string, address: string, vat_payer: bool, vat_rate: int, vat_note: ?string, series: string, first_number: int}|null */
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
            && trim($value['series']) !== '' && $value['first_number'] >= 1
            && ($value['vat_payer']
                ? $value['vat_rate'] >= 1 && trim((string) $value['vat_code']) !== ''
                : trim((string) $value['vat_note']) !== '');
    }

    /** @param  array{name: string, company_code: string, vat_code: ?string, address: string, vat_payer: bool, vat_rate: int, vat_note: ?string, series: string, first_number: int}  $values */
    public function save(array $values): void
    {
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $values]);
    }
}
