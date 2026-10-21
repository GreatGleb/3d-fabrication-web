{{-- Таблица заказов прототипа (orderList(…, true)): Order (номер и дата), Type, Status (стадия), Total, «Valdyti» · «Manage» —
     переход к карточке заказа во вкладке Orders. Строки — Orders\Index::rows() (W-14). --}}
@php($locale = app()->getLocale())
<x-table>
    <thead><tr><th>{{ __('admin.orders.columns.order') }}</th><th>{{ __('admin.orders.columns.type') }}</th><th>{{ __('admin.orders.columns.status') }}</th><th>{{ __('admin.orders.columns.total') }}</th><th></th></tr></thead>
    <tbody>
        @forelse ($orders as $row)
            <tr>
                <td><strong>{{ $row->number }}</strong><br><small>{{ \Illuminate\Support\Carbon::parse($row->created_at)->timezone(config('app.timezone'))->format('Y-m-d') }}</small></td>
                <td>{{ __('admin.orders.type') }}</td>
                <td><span class="tag blue">{{ __('admin.orders.stages.'.$row->stage) }}</span></td>
                <td>{{ \Illuminate\Support\Number::currency($row->total / 100, in: 'EUR', locale: $locale) }}</td>
                <td><a class="btn small secondary" href="{{ route($locale.'.admin.orders', ['order' => $row->id]) }}#order-card">{{ __('admin.orders.manage') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="5">{{ __('admin.orders.empty') }}</td></tr>
        @endforelse
    </tbody>
</x-table>
