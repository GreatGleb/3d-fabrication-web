{{-- Список заказов (W-14): поиск по номеру (search-box прототипа) и фильтр по стадии; демо-заказ DEMO-1047 не переносится. --}}
<div>
    <h2>{{ __('admin.orders.heading') }}</h2>
    <div class="search-box gap">
        <input id="admin-order-search" type="search" inputmode="numeric" placeholder="{{ __('admin.orders.search') }}" aria-label="{{ __('admin.orders.search_label') }}" wire:model.live.debounce.300ms="search">
        <x-line-icon name="search" />
    </div>
    <label class="field gap" for="admin-order-stage">{{ __('admin.orders.columns.status') }}
        <select id="admin-order-stage" wire:model.live="stage">
            <option value="">{{ __('admin.orders.all_stages') }}</option>
            @foreach (\App\Domain\Orders\ProductionStage::cases() as $case)
                <option value="{{ $case->value }}">{{ __('admin.orders.stages.'.$case->value) }}</option>
            @endforeach
        </select>
    </label>
    <div class="gap" id="admin-order-list">
        @include('admin.partials.order-rows', ['orders' => $orders])
    </div>
</div>
