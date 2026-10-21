{{-- Подтверждение заявки на изготовление (§3.1, W-12): номер заказа и предварительная сумма (серверная оценка); гостю —
     секретная ссылка на заказ с советом сохранить её (показывается один раз — flash сессии), клиенту — ссылка в кабинет.
     Разметка — блоки карточки заказа прототипа (panel, notice success, summary-row); тексты — нейтральные (progress.md). --}}
@php($locale = app()->getLocale())
<x-layout :title="__('calculator.order.submitted_title')" :description="__('calculator.order.submitted_title')" :noindex="true">
    <x-page-top :title="__('calculator.order.submitted_title')" :eyebrow="__('calculator.eyebrow')" />
    <div class="wrap page-content">
        <div class="panel" data-order-number="{{ $submitted['number'] }}">
            <div class="notice success">{{ __('calculator.order.submitted_text', ['number' => $submitted['number']]) }}</div>
            <div class="summary-row total gap"><span>{{ __('calculator.summary.total') }}</span><span data-total data-cents="{{ $submitted['total_cents'] }}">{{ \Illuminate\Support\Number::currency($submitted['total_cents'] / 100, in: 'EUR', locale: $locale) }}</span></div>
            <p class="legal-note">{{ __('calculator.summary.note') }}</p>
            @if ($submitted['guest_url'])
                <p class="gap">{{ __('calculator.order.guest_link') }}</p>
                <p style="overflow-wrap:anywhere"><a class="text-link" href="{{ $submitted['guest_url'] }}" data-guest-link>{{ $submitted['guest_url'] }}</a></p>
            @else
                <p class="gap">{{ __('calculator.order.account_text') }}</p>
                <x-button :href="route($locale.'.account')" arrow>{{ __('shell.account') }}</x-button>
            @endif
        </div>
    </div>
</x-layout>
