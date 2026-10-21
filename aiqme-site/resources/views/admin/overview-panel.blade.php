{{-- Метрики и последние заказы Overview (W-14) в разметке прототипа (.grid.three .metric, .topline, orderList): метрики
     «Demo orders» и «Demo registrations» и notice «…simulated in the browser» не переносятся (D-21). --}}
@php($locale = app()->getLocale())
<div>
    @if ($failed > 0)
        <div class="notice warning gap" role="alert" id="overview-failed">{{ __('admin.overview.failed', ['count' => $failed]) }}</div>
    @endif
    <div class="grid three gap" id="overview-metrics">
        @foreach ($metrics as $key => $value)
            <div class="metric"><strong>{{ $value }}</strong><span>{{ __('admin.overview.metrics.'.$key) }}</span></div>
        @endforeach
    </div>
    <div class="topline gap"><h2>{{ __('admin.overview.orders') }}</h2><a class="text-link" href="{{ route($locale.'.admin.orders') }}">{{ __('admin.overview.all_orders') }}</a></div>
    @include('admin.partials.order-rows', ['orders' => $orders])
</div>
