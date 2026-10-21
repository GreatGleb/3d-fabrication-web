{{-- Форма настроек калькулятора (W-11) в разметке #pricing-form прототипа; у тарифов и габарита, которые администратор
     ещё не менял, к подписи добавлено «Nepatvirtinta» · «Not confirmed» (D-09, D-24). --}}
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
    <x-button type="submit" class="gap">{{ __('admin.calculator.save') }}</x-button>
</form>
