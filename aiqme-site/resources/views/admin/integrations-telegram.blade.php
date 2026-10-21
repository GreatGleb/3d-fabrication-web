{{-- Раздел Telegram экрана Integrations (W-19, §4.5): привязан ли личный чат, настроен ли бот (без значений секретов),
     последняя ошибка Bot API (без токена) и код привязки /start <код> на 10 минут. Описание прототипа о ролях и экспорте
     не переносится: ролей и экспорта клиентов в боте нет (§4.5). --}}
<div class="panel">
    @if ($binding->bound_at)
        <span class="tag green">{{ __('admin.integrations.connected') }}</span>
    @else
        <span class="tag orange">{{ __('admin.integrations.not_connected') }}</span>
    @endif
    <h3 class="gap">Telegram</h3>
    @if ($binding->bound_at)
        <p>{{ __('admin.integrations.telegram.bound', ['time' => $binding->bound_at->timezone(config('app.timezone'))->format('Y-m-d H:i')]) }}</p>
    @endif
    @unless ($configured)
        <p>{{ __('admin.integrations.telegram.not_configured') }}</p>
    @endunless
    @if ($binding->last_error)
        <p>{{ __('admin.integrations.last_error') }}: {{ $binding->last_error_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }} · {{ $binding->last_error }}</p>
    @endif
    @if ($code)
        <p>{{ __('admin.integrations.telegram.code_hint', ['minutes' => \App\Livewire\Admin\Integrations::CODE_MINUTES]) }}</p>
        <p><code>/start {{ $code }}</code></p>
    @endif
    <x-button variant="secondary" class="gap" wire:click="telegramCode">{{ __('admin.integrations.telegram.get_code') }}</x-button>
</div>
