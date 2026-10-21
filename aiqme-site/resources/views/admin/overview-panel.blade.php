{{-- Метрики и последние заказы Overview (W-14) в разметке прототипа (.grid.three .metric, .topline, orderList): метрики
     «Demo orders» и «Demo registrations» и notice «…simulated in the browser» не переносятся (D-21). «Платежи на сверку»
     (W-28): запросы и события с needs_review, кнопка «разобрано»; блока в прототипе нет — разметка .notice и x-table.
     «Незаполненные реквизиты» (W-17) — .notice со ссылкой на вкладку Settings. --}}
@php($locale = app()->getLocale())
@php($money = fn (int $cents): string => \Illuminate\Support\Number::currency($cents / 100, in: 'EUR', locale: $locale))
<div>
    @if ($failed > 0)
        <div class="notice warning gap" role="alert" id="overview-failed">{{ __('admin.overview.failed', ['count' => $failed]) }}</div>
    @endif
    @if ($sellerMissing)
        <div class="notice warning gap" role="alert" id="overview-seller-missing">{{ __('admin.overview.seller_missing') }} <a class="text-link" href="{{ route($locale.'.admin.settings') }}">{{ __('admin.tabs.settings') }}</a></div>
    @endif
    @if ($reviewRequests->isNotEmpty() || $reviewEvents->isNotEmpty())
        <section class="gap" id="overview-payment-review">
            <div class="notice warning" role="alert">{{ __('admin.overview.review.notice', ['count' => $reviewRequests->count() + $reviewEvents->count()]) }}</div>
            <x-table>
                <thead><tr><th>{{ __('admin.overview.review.columns.order') }}</th><th>{{ __('admin.overview.review.columns.item') }}</th><th>{{ __('admin.overview.review.columns.reason') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($reviewRequests as $request)
                        <tr data-review-request="{{ $request->id }}">
                            <td><a class="text-link" href="{{ route($locale.'.admin.orders', ['order' => $request->order_id]) }}#order-card">{{ $numbers[$request->order_id] ?? '—' }}</a></td>
                            <td>{{ __('admin.overview.review.request', ['amount' => $money($request->amount)]) }} · {{ __('admin.orders.request_states.'.$request->state) }}</td>
                            <td>{{ $request->review_reason }}</td>
                            <td><button class="btn small secondary" type="button" wire:click="resolveRequest({{ $request->id }})">{{ __('admin.overview.review.resolve') }}</button></td>
                        </tr>
                    @endforeach
                    @foreach ($reviewEvents as $event)
                        @php($orderId = $event->paymentRequest?->order_id)
                        <tr data-review-event="{{ $event->event_id }}">
                            <td>@if ($orderId)<a class="text-link" href="{{ route($locale.'.admin.orders', ['order' => $orderId]) }}#order-card">{{ $numbers[$orderId] ?? '—' }}</a>@else — @endif</td>
                            <td>{{ __('admin.overview.review.event') }} {{ $event->event_id }}</td>
                            <td>{{ $event->type }}</td>
                            <td><button class="btn small secondary" type="button" wire:click="resolveEvent('{{ $event->event_id }}')">{{ __('admin.overview.review.resolve') }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </section>
    @endif
    <div class="grid three gap" id="overview-metrics">
        @foreach ($metrics as $key => $value)
            <div class="metric"><strong>{{ $value }}</strong><span>{{ __('admin.overview.metrics.'.$key) }}</span></div>
        @endforeach
    </div>
    <div class="topline gap"><h2>{{ __('admin.overview.orders') }}</h2><a class="text-link" href="{{ route($locale.'.admin.orders') }}">{{ __('admin.overview.all_orders') }}</a></div>
    @include('admin.partials.order-rows', ['orders' => $orders])
</div>
