<?php

namespace App\Models;

use App\Domain\Orders\PricedLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Lunar\Base\Purchasable;

/**
 * Строка заказа «деталь из STL» (§2.5, §3.1): закрытая спецификация изготовления, не товар каталога. Параметры —
 * материал, заполнение, единицы, масштаб, количество (parameters.quantity) и пожелания; estimate — серверная оценка строки
 * в центах (EstimateService), она же цена строки Lunar при quantity = 1.
 */
#[Fillable(['stl_file_id', 'parameters', 'measurement', 'estimate'])]
class StlPart extends Model implements Purchasable
{
    use PricedLine;

    protected function casts(): array
    {
        return ['parameters' => 'array', 'measurement' => 'array'];
    }

    /** @return BelongsTo<StlFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StlFile::class, 'stl_file_id');
    }

    public function lineCents(): int
    {
        return $this->estimate;
    }

    public function getDescription(): string
    {
        return $this->file->original_name;
    }

    public function getOption(): string
    {
        return 'FDM / '.$this->parameters['material'];
    }

    public function getOptions(): Collection
    {
        return collect($this->parameters);
    }

    public function getIdentifier(): string
    {
        return 'STL-'.$this->id;
    }
}
