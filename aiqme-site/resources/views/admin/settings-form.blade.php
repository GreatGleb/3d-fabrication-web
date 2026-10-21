{{-- Форма вкладки Settings (W-17): реквизиты продавца, VAT и нумерация счетов. В прототипе вкладки нет — разметка
     формы калькулятора (.panel, .grid.two.form-grid, x-field), надписи нейтральные (docs/progress.md). --}}
<form class="panel" id="invoice-settings-form" wire:submit="save">
    <h2>{{ __('admin.settings.heading') }}</h2>
    <x-status :message="$status" />
    <div class="grid two form-grid">
        <x-field name="name" :label="__('admin.settings.name')" :value="$name" maxlength="255" wire:model="name" />
        <x-field name="companyCode" :label="__('admin.settings.company_code')" :value="$companyCode" maxlength="50" wire:model="companyCode" />
        <x-field name="address" :label="__('admin.settings.address')" :value="$address" maxlength="255" wire:model="address" />
        <x-field name="vatPayer" type="select" :label="__('admin.settings.vat_payer')" :value="$vatPayer"
            :options="['1' => __('admin.settings.vat_payer_yes'), '0' => __('admin.settings.vat_payer_no')]" wire:model.live="vatPayer" />
        @if ($vatPayer === '1')
            <x-field name="vatCode" :label="__('admin.settings.vat_code')" :value="$vatCode" maxlength="50" wire:model="vatCode" />
            <x-field name="vatRate" type="number" :label="__('admin.settings.vat_rate')" :value="$vatRate" min="1" max="100" wire:model="vatRate" />
        @else
            <x-field name="vatNote" :label="__('admin.settings.vat_note')" :value="$vatNote" maxlength="255" wire:model="vatNote" />
        @endif
        <x-field name="series" :label="__('admin.settings.series')" :value="$series" maxlength="10" wire:model="series" />
        <x-field name="firstNumber" type="number" :label="__('admin.settings.first_number')" :value="$firstNumber" min="1" wire:model="firstNumber" />
    </div>
    <p>{{ __('admin.settings.hint') }}</p>
    <x-button type="submit" class="gap">{{ __('admin.settings.save') }}</x-button>
</form>
