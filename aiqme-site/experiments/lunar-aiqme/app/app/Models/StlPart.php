<?php

namespace App\Models;

use Comparison\StlIntake;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lunar\Base\Purchasable;
use Lunar\Base\Traits\HasPrices;
use Lunar\Models\Contracts\TaxClass;
use Lunar\Models\Currency;

/** A private manufacturing specification, never a public catalog product. */
class StlPart extends Model implements Purchasable
{
    use HasPrices;

    protected $guarded = [];

    protected $casts = ['parameters' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn (self $part) => $part->tax_class_id = \Lunar\Models\TaxClass::getDefault()->id);
        static::created(fn (self $part) => $part->prices()->create(['currency_id' => Currency::whereCode('EUR')->firstOrFail()->id, 'price' => StlIntake::unit($part->parameters), 'min_quantity' => 1]));
    }

    public function getPrices(): Collection
    {
        return $this->prices;
    }

    public function getUnitQuantity(): int
    {
        return 1;
    }

    public function getTaxClass(): TaxClass
    {
        return \Lunar\Models\TaxClass::findOrFail($this->tax_class_id);
    }

    public function getTaxReference(): ?string
    {
        return null;
    }

    public function getType(): string
    {
        return 'physical';
    }

    public function getDescription(): string
    {
        return $this->original_name;
    }

    public function getOption(): string
    {
        return $this->parameters['material'].' / '.$this->parameters['layer_mm'].' mm';
    }

    public function getOptions(): Collection
    {
        return collect($this->parameters);
    }

    public function getIdentifier(): string
    {
        return 'STL-'.$this->id;
    }

    /** Collection at workshop; no carrier or shipping price in this probe. */
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
        return $quantity > 0;
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
