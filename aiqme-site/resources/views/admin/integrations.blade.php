{{-- Вкладка «Integracijos ir duomenų eiga» · «Integrations & data flow» (§1.4) в разметке прототипа (.grid.two, .panel,
     .tag): Stripe, почта и Google Таблицы (W-18), Telegram (W-19) — admin.integrations-panel. Карточки TEJA, Bambu Studio,
     «Banking & shipping» и кнопка демо-CSV прототипа не переносятся (§1.4, D-21). --}}
@component('admin.shell', ['tab' => $tab])
    <h2>{{ __('admin.integrations.heading') }}</h2>
    <livewire:admin.integrations />
@endcomponent
