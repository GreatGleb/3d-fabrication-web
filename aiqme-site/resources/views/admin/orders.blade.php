{{-- Вкладка «Užsakymai» · «Orders» (§1.4, W-14): список с поиском и фильтром и под ним карточка выбранного заказа
     (?order=<id>), как #admin-order-list и #admin-order-detail прототипа. --}}
@component('admin.shell', ['tab' => $tab])
    <livewire:admin.orders.index />
    @if ($orderId)
        <div id="admin-order-detail" class="gap">
            <livewire:admin.orders.card :order-id="$orderId" />
        </div>
    @endif
@endcomponent
