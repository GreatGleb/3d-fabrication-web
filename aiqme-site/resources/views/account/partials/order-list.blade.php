{{-- Таблица заказов клиента (orderList() прототипа): номер и дата, тип, стадия и оплата, сумма, «View». --}}
@if ($orders->isEmpty())
    <p>{{ __('account.orders.empty') }}</p>
@else
    <x-table>
        <thead><tr><th>{{ __('admin.orders.columns.order') }}</th><th>{{ __('admin.orders.columns.type') }}</th><th>{{ __('admin.orders.columns.status') }}</th><th>{{ __('admin.orders.columns.total') }}</th><th></th></tr></thead>
        <tbody>
            @foreach ($orders as $row)
                <tr data-order="{{ $row['number'] }}">
                    <td><strong>{{ $row['number'] }}</strong><br><small>{{ $row['date'] }}</small></td>
                    <td>{{ __('admin.orders.type') }}</td>
                    <td><span class="tag blue">{{ __('admin.orders.stages.'.$row['stage']) }}</span><br><small>{{ __('admin.orders.payment_states.'.$row['payment_state']) }}</small></td>
                    <td>{{ \Illuminate\Support\Number::currency($row['total'] / 100, in: 'EUR', locale: $locale) }}</td>
                    <td><a class="btn small secondary" href="{{ route($locale.'.account.orders.show', $row['number']) }}">{{ __('account.orders.view') }}</a></td>
                </tr>
            @endforeach
        </tbody>
    </x-table>
@endif
