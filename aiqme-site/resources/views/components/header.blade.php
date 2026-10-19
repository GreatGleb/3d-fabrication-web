{{-- Шапка прототипа (§1.1): логотип (D-25), 7 пунктов, поиск, LT/EN, корзина, кнопка меню ≤900 px (resources/js/menu.js)
     и второй ряд с CTA. Активный пункт — текущая страница или вложенная (имя маршрута «<locale>.<page>.…»).
     Значка кабинета нет до входа W-07. $languages — версии этой страницы для переключателя (W-02); $switchUrl —
     адрес, если версии другой локали нет (W-05). --}}
@props(['languages' => [], 'switchUrl' => null])
@php
    $locale = app()->getLocale();
    $switchTo = $locale === 'lt' ? 'en' : 'lt';
    $current = request()->route()?->getName() ?? '';
@endphp
<a class="skip" href="#main">{{ __('shell.skip') }}</a>
<header class="header">
    <div class="wrap head-main">
        <a class="logo" href="{{ route($locale.'.home') }}" aria-label="AIQME"><img src="{{ asset('images/logo-dark.png') }}" alt="AIQME"></a>
        <nav class="nav" id="main-nav" aria-label="{{ __('shell.nav_label') }}">
            @foreach (__('shell.nav') as $page => $label)
                @php($active = $current === $locale.'.'.$page || str_starts_with($current, $locale.'.'.$page.'.'))
                <a @class(['active' => $active]) href="{{ route($locale.'.'.$page) }}" @if ($active) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="tools">
            <a class="icon-btn" href="{{ route($locale.'.search') }}" aria-label="{{ __('shell.search') }}"><x-line-icon name="search" /></a>
            <a href="{{ $languages[$switchTo] ?? $switchUrl ?? route($switchTo.'.home') }}" hreflang="{{ $switchTo }}" aria-label="{{ __('pages.switch_language') }}" class="icon-btn lang">@if ($switchTo === 'en')LT / <span>EN</span>@else<span>LT</span> / EN @endif</a>
            <a class="icon-btn" href="{{ route($locale.'.cart') }}" aria-label="{{ __('shell.cart') }}"><x-line-icon name="cart" /></a>
            <button class="icon-btn menu-btn" type="button" aria-label="{{ __('shell.menu') }}" aria-expanded="false" aria-controls="main-nav"><x-line-icon name="menu" /></button>
        </div>
    </div>
    <x-header-cta />
</header>
