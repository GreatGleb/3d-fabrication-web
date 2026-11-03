{{-- Форма настроек калькулятора (W-11) в разметке #pricing-form прототипа; у тарифов и габарита, которые администратор
     ещё не менял, к подписи добавлено «Nepatvirtinta» · «Not confirmed» (D-09, D-24). Ниже — фрезеровка и гравировка (правка 02.11,
     Г7): материалы LT / EN строками, толщины и глубины по одной в строке, предел двумя сторонами. --}}
@php($mark = fn (string $key): string => in_array($key, $unconfirmed, true) ? ' · '.__('admin.calculator.unconfirmed') : '')
<form class="panel" id="pricing-form" wire:submit="save">
    <h2>{{ __('admin.calculator.heading') }}</h2>
    <x-status :message="$status" />
    <div class="grid two form-grid">
        @foreach ($materials as $index => $row)
            <x-field :name="'materials.'.$index.'.name'" :label="__('admin.calculator.material')" :value="$row['name']" wire:model="materials.{{ $index }}.name" />
            <x-field :name="'materials.'.$index.'.density'" type="number" :label="__('admin.calculator.density').$mark('materials')" :value="$row['density']"
                step="0.01" min="0.01" max="30" wire:model="materials.{{ $index }}.density" />
        @endforeach
    </div>
    <p>{{ __('admin.calculator.materials_hint') }}</p>
    <div class="grid two form-grid gap">
        <x-field name="ratePerGram" type="number" :label="__('admin.calculator.rate').$mark('rate_per_gram')" :value="$ratePerGram" step="0.0001" min="0" wire:model="ratePerGram" />
        <x-field name="orderFee" type="number" :label="__('admin.calculator.order_fee').$mark('order_fee_cents')" :value="$orderFee" step="0.01" min="0" wire:model="orderFee" />
        <x-field name="fileFee" type="number" :label="__('admin.calculator.file_fee').$mark('file_fee_cents')" :value="$fileFee" step="0.01" min="0" wire:model="fileFee" />
        <x-field name="defaultInfill" type="number" :label="__('admin.calculator.default_infill')" :value="$defaultInfill" min="0" max="100" wire:model="defaultInfill" />
        <x-field name="maxDimension" type="number" :label="__('admin.calculator.max_dimension').$mark('max_dimension_mm')" :value="$maxDimension" min="10" max="2000" wire:model="maxDimension" />
    </div>
    @foreach (['millingMaterials' => 'milling_materials', 'engravingMaterials' => 'engraving_materials'] as $list => $heading)
        <h3 class="gap">{{ __('admin.calculator.'.$heading) }}</h3>
        <div class="grid two form-grid" id="{{ str_replace('_', '-', $heading) }}">
            @foreach ($this->{$list} as $index => $row)
                <x-field :name="$list.'.'.$index.'.lt'" :label="__('admin.calculator.name_lt')" :value="$row['lt']" wire:model="{{ $list }}.{{ $index }}.lt" />
                <x-field :name="$list.'.'.$index.'.en'" :label="__('admin.calculator.name_en')" :value="$row['en']" wire:model="{{ $list }}.{{ $index }}.en" />
            @endforeach
        </div>
    @endforeach
    <p>{{ __('admin.calculator.materials_hint') }}</p>
    <div class="grid two form-grid gap">
        <x-field name="millingThicknesses" type="textarea" :label="__('admin.calculator.milling_thicknesses')" :value="$millingThicknesses" wire:model="millingThicknesses" />
        <x-field name="engravingDepths" type="textarea" :label="__('admin.calculator.engraving_depths')" :value="$engravingDepths" wire:model="engravingDepths" />
        <x-field name="millingWidth" type="number" :label="__('admin.calculator.milling_width')" :value="$millingWidth" min="1" wire:model="millingWidth" />
        <x-field name="millingLength" type="number" :label="__('admin.calculator.milling_length')" :value="$millingLength" min="1" wire:model="millingLength" />
    </div>
    <p>{{ __('admin.calculator.sizes_hint') }}</p>
    <x-button type="submit" class="gap">{{ __('admin.calculator.save') }}</x-button>
</form>
