{{-- Вкладка «Integracijos ir duomenų eiga» · «Integrations & data flow» (§1.4) в разметке прототипа (.grid.two, .panel,
     .tag). W-19 — раздел Telegram; Stripe, почту и Google Таблицы добавляет W-18. Карточки TEJA, Bambu Studio,
     «Banking & shipping» и кнопка демо-CSV прототипа не переносятся (§1.4, D-21). --}}
@component('admin.shell', ['tab' => $tab])
    <h2>{{ __('admin.integrations.heading') }}</h2>
    <div class="grid two gap">
        <livewire:admin.integrations />
    </div>
@endcomponent
