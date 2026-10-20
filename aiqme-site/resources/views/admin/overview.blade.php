{{-- Overview (заготовка W-08): заголовок прототипа; метрики, последние заказы и предупреждения добавляет W-14.
     Демо-метрики, заказ DEMO-1047 и notice «…simulated in the browser» не переносятся (D-21). --}}
@component('admin.shell', ['tab' => $tab])
    <h2>{{ __('admin.overview.heading') }}</h2>
    <x-placeholder class="gap" />
@endcomponent
