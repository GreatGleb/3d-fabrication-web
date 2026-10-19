{{-- Кнопка паузы ленты (resources/js/tickers.js): подписи берёт из data-атрибутов. --}}
<button type="button" class="partner-motion-toggle" aria-controls="{{ $target }}" aria-pressed="false"
    data-pause="{{ __('public.ticker.pause') }}" data-resume="{{ __('public.ticker.resume') }}"><span aria-hidden="true">Ⅱ</span> {{ __('public.ticker.pause') }}</button>
