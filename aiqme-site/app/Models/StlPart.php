<?php

namespace App\Models;

use App\Domain\Orders\PricedLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Lunar\Base\Purchasable;

/**
 * Строка заказа «деталь из файла» (§2.5, §3.1): закрытая спецификация изготовления, не товар каталога. Параметры —
 * у каждой позиции (правка 02.11, Г6): способ method (printing, milling, engraving; у позиций до 02.11 его нет — это
 * печать FDM), количество (parameters.quantity), срок term, needed_by, notes; у STL — единицы и масштаб; у печати —
 * материал, заполнение (infill; infill_consult — клиент просил консультацию, infill = заполнение по умолчанию) и пожелания
 * color, purpose, service, surface (у заказов до 28.10 — quality, layer, finish); у фрезеровки — material, material_en,
 * thickness (мм), у гравировки — material, material_en, depth (мм). estimate — серверная оценка строки в центах
 * (EstimateService, только STL + печать), она же цена строки Lunar при quantity = 1; NULL — цену назначит специалист при
 * подтверждении, до того цена строки 0.
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
        return $this->estimate ?? 0;
    }

    /** Способ изготовления; позиции до правки 02.11 — печать. */
    public function method(): string
    {
        return $this->parameters['method'] ?? 'printing';
    }

    /**
     * Подпись позиции по способу на языке $locale: «3D spausdinimas / ABS», «Frezavimas / Aliuminis, 6 mm»,
     * «Graviravimas / Aliuminis, 0,1 mm» (в LT — десятичная запятая); $separator — между способом и материалом (в счёте
     * «, »: «3D spausdinimas, ABS», правка 02.11, Г7).
     */
    public function label(string $locale, string $separator = ' / '): string
    {
        $parameters = $this->parameters;
        $method = __('calculator.methods.'.$this->method(), [], $locale);
        $material = $locale === 'lt' ? $parameters['material'] : ($parameters['material_en'] ?? $parameters['material']);
        $size = match ($this->method()) {
            'milling' => $parameters['thickness'],
            'engraving' => $parameters['depth'],
            default => null,
        };
        if ($size === null) {
            return $method.$separator.$material;
        }
        $size = (string) $size;

        return $method.$separator.$material.', '.($locale === 'lt' ? str_replace('.', ',', $size) : $size).' mm';
    }

    public function getDescription(): string
    {
        return $this->file->original_name;
    }

    public function getOption(): string
    {
        return $this->label(app()->getLocale());
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
