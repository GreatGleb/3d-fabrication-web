<?php

namespace App\Domain\Orders;

use Illuminate\Support\Collection;
use Lunar\Base\Traits\HasPrices;
use Lunar\Models\Contracts\TaxClass as TaxClassContract;
use Lunar\Models\Currency;
use Lunar\Models\TaxClass;

/**
 * Общее у строк заказа Lunar AIQME (Purchasable, §3.1): StlPart и OrderCharge — не товары каталога. Цена строки — одна
 * цена Lunar в EUR, равная итогу строки в центах (lineCents()); количество в корзине и заказе всегда 1; налоговый класс —
 * класс по умолчанию без ставок (aiqme:bootstrap), поэтому сумма заказа равна сумме строк.
 */
trait PricedLine
{
    use HasPrices;

    abstract public function lineCents(): int;

    protected static function bootPricedLine(): void
    {
        static::creating(fn (self $line) => $line->tax_class_id ??= TaxClass::getDefault()->id);
        static::created(fn (self $line) => $line->prices()->create([
            'currency_id' => Currency::whereCode('EUR')->firstOrFail()->id,
            'price' => $line->lineCents(),
            'min_quantity' => 1,
        ]));
    }

    public function getPrices(): Collection
    {
        return $this->prices;
    }

    public function getUnitQuantity(): int
    {
        return 1;
    }

    public function getTaxClass(): TaxClassContract
    {
        return TaxClass::findOrFail($this->tax_class_id);
    }

    public function getTaxReference(): ?string
    {
        return null;
    }

    public function getType(): string
    {
        return 'physical';
    }

    /** Доставку в цену включает администратор строкой delivery (W-13), а не механизм доставки Lunar. */
    public function isShippable(): bool
    {
        return false;
    }

    public function getThumbnail(): mixed
    {
        return null;
    }

    public function canBeFulfilledAtQuantity(int $quantity): bool
    {
        return $quantity === 1;
    }

    public function isPurchasable(): bool
    {
        return true;
    }

    public function getTotalInventory(): int
    {
        return PHP_INT_MAX;
    }
}
