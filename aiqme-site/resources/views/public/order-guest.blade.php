{{-- Гостевая страница заказа по секретной ссылке (§6.2, W-12) — заготовка: номер, состав и предварительная сумма.
     Полную карточку (стадии, оплата, ответы) и право в сессии добавляют W-15 и W-16. Разметка — orderDetail() прототипа. --}}
@php($locale = app()->getLocale())
<x-layout :title="__('calculator.order.title', ['number' => $number])" :description="__('calculator.order.title', ['number' => $number])" :noindex="true">
    <x-page-top :title="__('calculator.order.title', ['number' => $number])" :eyebrow="__('calculator.eyebrow')" />
    <div class="wrap page-content">
        <div class="panel" data-order-number="{{ $number }}">
            <h3>{{ __('calculator.order.contents') }}</h3>
            @foreach ($parts as $part)
                <div class="summary-row"><span>{{ $part['name'] }}</span><b>× {{ $part['quantity'] }}</b></div>
            @endforeach
            <div class="summary-row total gap"><span>{{ __('calculator.summary.total') }}</span><span data-cents="{{ $totalCents }}">{{ \Illuminate\Support\Number::currency($totalCents / 100, in: 'EUR', locale: $locale) }}</span></div>
            <p class="legal-note">{{ __('calculator.summary.note') }}</p>
        </div>
    </div>
</x-layout>
