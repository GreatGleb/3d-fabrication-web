{{-- Панели экрана Integrations (W-18, W-19) в разметке прототипа (.grid.two, .panel, .tag): Stripe, почта и Google
     Таблицы (W-18) — настроены ли на сервере (без значений секретов) и последняя ошибка канала; ссылка на Google
     Таблицу; Telegram (W-19) — привязан ли личный чат, настроен ли бот, последняя ошибка Bot API и код привязки
     /start <код> на 10 минут. Описание прототипа о ролях и экспорте не переносится (§4.5). Надписи, которых нет
     в прототипе, — нейтральные (docs/progress.md, W-18, W-19). --}}
@php($at = fn ($time) => $time?->timezone(config('app.timezone'))->format('Y-m-d H:i'))
<div class="grid two gap">
    <div class="panel" id="integration-stripe">
        <span class="tag {{ $stripe ? 'green' : 'orange' }}">{{ $stripe ? __('admin.integrations.connected') : __('admin.integrations.not_connected') }}</span>
        <h3 class="gap">Stripe</h3>
        @unless ($stripe)
            <p>{{ __('admin.integrations.not_configured') }}</p>
        @endunless
    </div>
    <div class="panel" id="integration-mail">
        <span class="tag {{ $mail ? 'green' : 'orange' }}">{{ $mail ? __('admin.integrations.connected') : __('admin.integrations.not_connected') }}</span>
        <h3 class="gap">{{ __('admin.integrations.mail') }}</h3>
        @unless ($mail)
            <p>{{ __('admin.integrations.not_configured') }}</p>
        @endunless
        @if ($mailError)
            <p>{{ __('admin.integrations.last_error') }}: {{ $at($mailError->updated_at) }} · {{ $mailError->mail_error }}</p>
        @endif
    </div>
    <form class="panel" id="integration-sheets" wire:submit="saveSheetsUrl">
        <span class="tag {{ $sheets ? 'green' : 'orange' }}">{{ $sheets ? __('admin.integrations.connected') : __('admin.integrations.not_connected') }}</span>
        <h3 class="gap">Google Sheets</h3>
        @unless ($sheets)
            <p>{{ __('admin.integrations.sheets.not_configured') }}</p>
        @endunless
        @if ($sheetsError)
            <p>{{ __('admin.integrations.last_error') }}: {{ $at($sheetsError->updated_at) }} · {{ $sheetsError->sheets_error }}</p>
        @endif
        @if ($savedUrl)
            <p><a href="{{ $savedUrl }}" target="_blank" rel="noopener noreferrer">{{ __('admin.integrations.sheets.open') }}</a></p>
        @endif
        <x-status :message="$status" />
        <x-field name="sheetsUrl" type="url" :label="__('admin.integrations.sheets.url')" :value="$sheetsUrl" maxlength="500" wire:model="sheetsUrl" />
        <x-button type="submit" variant="secondary" class="gap">{{ __('admin.integrations.sheets.save') }}</x-button>
    </form>
    <div class="panel" id="integration-telegram">
        @if ($binding->bound_at)
            <span class="tag green">{{ __('admin.integrations.connected') }}</span>
        @else
            <span class="tag orange">{{ __('admin.integrations.not_connected') }}</span>
        @endif
        <h3 class="gap">Telegram</h3>
        @if ($binding->bound_at)
            <p>{{ __('admin.integrations.telegram.bound', ['time' => $at($binding->bound_at)]) }}</p>
        @endif
        @unless ($configured)
            <p>{{ __('admin.integrations.telegram.not_configured') }}</p>
        @endunless
        @if ($binding->last_error)
            <p>{{ __('admin.integrations.last_error') }}: {{ $at($binding->last_error_at) }} · {{ $binding->last_error }}</p>
        @endif
        @if ($code)
            <p>{{ __('admin.integrations.telegram.code_hint', ['minutes' => \App\Livewire\Admin\Integrations::CODE_MINUTES]) }}</p>
            <p><code>/start {{ $code }}</code></p>
        @endif
        <x-button variant="secondary" class="gap" wire:click="telegramCode">{{ __('admin.integrations.telegram.get_code') }}</x-button>
    </div>
</div>
