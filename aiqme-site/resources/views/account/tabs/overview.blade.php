{{-- Overview (account() прототипа): приветствие, реальные счётчики заказов и моделей, последний заказ. Метрики
     персональной скидки нет — скидка не реализуется (W-15). --}}
<div id="account-overview">
    <h2>{{ __('account.overview.welcome', ['name' => auth()->user()->name]) }}</h2>
    <p class="gap">{{ __('account.overview.intro') }}</p>
    <div class="grid two gap">
        <div class="metric"><strong data-count="orders">{{ $ordersCount }}</strong><span>{{ __('account.overview.orders') }}</span></div>
        <div class="metric"><strong data-count="models">{{ $modelsCount }}</strong><span>{{ __('account.overview.models') }}</span></div>
    </div>
    <div class="panel gap">
        <h3>{{ __('account.overview.latest') }}</h3>
        @include('account.partials.order-list')
    </div>
</div>
