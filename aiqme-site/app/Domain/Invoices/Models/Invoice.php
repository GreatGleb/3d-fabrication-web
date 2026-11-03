<?php

namespace App\Domain\Invoices\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lunar\Models\Order;

/**
 * Выпущенный счёт (§2.5, §3.5): серия и номер, вид (итоговый — D-04), язык, снимок реквизитов продавца и покупателя,
 * суммы в центах (итог = подтверждённая цена, VAT выделен из строк) и путь PDF в приватном storage. Не перезаписывается.
 */
#[Fillable(['order_id', 'series', 'number', 'kind', 'locale', 'seller', 'buyer', 'net_total', 'vat_total', 'total', 'pdf_path', 'issued_at'])]
class Invoice extends Model
{
    /** Итоговый счёт-фактура на подтверждённую сумму (D-04). */
    public const FINAL = 'final';

    protected function casts(): array
    {
        return ['seller' => 'array', 'buyer' => 'array', 'issued_at' => 'datetime'];
    }

    /**
     * Номер для показа (правка 02.11, Г5): серия и номер из 5 цифр с ведущими нулями без дефиса (AIQW00001). Счета,
     * выписанные до правки, показываются, как напечатаны в их PDF: «серия-номер», что видно по их pdf_path.
     */
    public function label(): string
    {
        $old = $this->series.'-'.$this->number;

        return $this->pdf_path === 'invoices/'.$old.'.pdf' ? $old : self::number($this->series, $this->number);
    }

    /** Номер нового счёта: серия + номер до 5 цифр с ведущими нулями. */
    public static function number(string $series, int $number): string
    {
        return $series.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }
}
