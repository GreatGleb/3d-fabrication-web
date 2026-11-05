<?php

namespace App\Models;

use App\Domain\Orders\PricedLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lunar\Base\Purchasable;

/**
 * Строка заказа «товар» (правка 05.11, H-08): покупка товара «Pirkti» из корзины. Снимок названия {lt, en}, количество и
 * цена за единицу (центы, с PVM) на момент оформления — позже изменённый или удалённый товар заказ не меняет. Строка
 * Lunar — quantity = 1 с ценой quantity × unit_price (как у прочих строк, PricedLine); остатки не ведутся.
 */
#[Fillable(['content_item_id', 'title', 'quantity', 'unit_price'])]
class ProductLine extends Model implements Purchasable
{
    use PricedLine;

    protected function casts(): array
    {
        return ['title' => 'array'];
    }

    public function lineCents(): int
    {
        return $this->quantity * $this->unit_price;
    }

    /** Название на языке $locale (если перевода не было — литовское). */
    public function title(string $locale): string
    {
        return $this->title[$locale] ?? $this->title['lt'];
    }

    public function getDescription(): string
    {
        return $this->title(app()->getLocale());
    }

    public function getOption(): string
    {
        return '× '.$this->quantity;
    }

    public function getOptions(): Collection
    {
        return collect(['quantity' => $this->quantity]);
    }

    public function getIdentifier(): string
    {
        return 'PRODUCT-'.$this->id;
    }
}
