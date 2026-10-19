{{-- Второй ряд шапки: подпись и CTA калькулятора (демо-строка прототипа не выводится, D-21). --}}
<div class="header-cta">
    <div class="wrap head-sub">
        <span>{{ __('shell.tagline') }}</span>
        <a class="cta-link" href="{{ route(app()->getLocale().'.calculator') }}"><x-line-icon name="upload" /> {{ __('shell.quote_cta') }} <x-line-icon name="arrow" /></a>
    </div>
</div>
