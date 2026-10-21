{{-- Вкладка «Klientai» · «Customers» (§1.4, W-14). Выгрузка CSV, флажки полей и notice прототипа не переносятся (§1.4, D-21). --}}
@component('admin.shell', ['tab' => $tab])
    <livewire:admin.customers :customer-id="$customerId" />
@endcomponent
