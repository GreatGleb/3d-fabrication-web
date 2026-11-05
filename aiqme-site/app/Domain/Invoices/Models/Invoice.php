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
     * Номер для показа — как напечатан в PDF: имя файла pdf_path, сохранённое при выписке и не пересчитываемое (правка
     * 05.11, H-06). Так старые счета остаются «AIQ-1» (до правки 02.11) и «AIQW00001» (правка 02.11, Г5), новые — «AIQ60008».
     */
    public function label(): string
    {
        return basename($this->pdf_path, '.pdf');
    }

    /** Номер нового счёта (правка 05.11, H-06): серия года (AIQ6) + номер не короче 4 цифр — AIQ60008, AIQ610000. */
    public static function number(string $series, int $number): string
    {
        return $series.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
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
