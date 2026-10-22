{{-- Клиенты (W-14): карточка выбранного клиента, список зарегистрированных клиентов и гостевые контакты; вместо выгрузки
     CSV — ссылка на Google Таблицу из Integrations (W-18), если она сохранена. --}}
@php($locale = app()->getLocale())
<div>
    <h2>{{ __('admin.customers.heading') }}</h2>
    @if ($sheetsUrl)
        <p><a id="customers-sheet" href="{{ $sheetsUrl }}" target="_blank" rel="noopener noreferrer">{{ __('admin.customers.sheet') }}</a></p>
    @endif
    @if ($customer)
        <div class="panel gap" id="customer-card">
            <h3>{{ $customer->name }}</h3>
            <p>{{ $customer->email }} · {{ __('admin.customers.registered') }}: {{ $customer->created_at->timezone(config('app.timezone'))->format('Y-m-d') }}</p>
            @if ($customer->profile)
                <h3 class="gap">{{ __('admin.customers.details') }}</h3>
                <x-table>
                    <tbody>
                        @foreach (['name', 'company', 'company_code', 'vat_code', 'address', 'city', 'postcode'] as $field)
                            @if (filled($customer->profile->{$field}))
                                <tr><th>{{ __('account.addresses.fields.'.$field) }}</th><td>{{ $customer->profile->{$field} }}</td></tr>
                            @endif
                        @endforeach
                    </tbody>
                </x-table>
            @endif
            <h3 class="gap">{{ __('admin.customers.orders') }}</h3>
            @include('admin.partials.order-rows', ['orders' => $customerOrders])
        </div>
    @endif
    <x-table class="gap">
        <thead><tr><th>{{ __('admin.customers.name') }}</th><th>{{ __('admin.customers.email') }}</th><th>{{ __('admin.customers.registered') }}</th><th>{{ __('admin.customers.orders') }}</th><th></th></tr></thead>
        <tbody>
            @forelse ($customers as $item)
                <tr wire:key="customer-{{ $item->id }}">
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->email }}</td>
                    <td>{{ $item->created_at->timezone(config('app.timezone'))->format('Y-m-d') }}</td>
                    <td>{{ $item->orders }}</td>
                    <td><a class="btn small secondary" href="{{ route($locale.'.admin.customers', ['customer' => $item->id]) }}#customer-card">{{ __('admin.customers.open') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('admin.customers.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-table>
    <h3 class="gap" id="guest-contacts">{{ __('admin.customers.guests') }}</h3>
    <x-table>
        <thead><tr><th>{{ __('admin.orders.columns.order') }}</th><th>{{ __('admin.customers.name') }}</th><th>{{ __('admin.customers.email') }}</th><th>{{ __('admin.customers.phone') }}</th><th>{{ __('admin.customers.company') }}</th></tr></thead>
        <tbody>
            @forelse ($guests as $guest)
                @php($contact = json_decode($guest->contact, true) ?? [])
                <tr wire:key="guest-{{ $guest->id }}">
                    <td><a class="text-link" href="{{ route($locale.'.admin.orders', ['order' => $guest->id]) }}#order-card">{{ $guest->number }}</a></td>
                    <td>{{ $contact['name'] ?? '' }}</td>
                    <td>{{ $contact['email'] ?? '' }}</td>
                    <td>{{ $contact['phone'] ?? '' }}</td>
                    <td>{{ $contact['company'] ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('admin.customers.guests_empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-table>
</div>
