{{-- Шапка прототипа (§1.1): логотип ($logo — адрес из layout, D-43), 7 пунктов, поиск, LT/EN, корзина, кнопка меню ≤900 px (resources/js/menu.js)
     (второй ряд с CTA удалён правкой 11.11, J-02). Активный пункт — текущая страница или вложенная (имя маршрута «<locale>.<page>.…»).
     Поиск — модальное окно прототипа (searchPanel, без JS — ссылка на страницу поиска). Значок кабинета ведёт на кабинет LT/EN (W-07; на /ru — EN). $languages — версии этой страницы для переключателя (W-02); $switchUrl —
     адрес, если версии другой локали нет (W-05).
     Шапка-шторка (правка 28.10, П2): сервер рисует тёмное состояние header--dark (первый кадр уже тёмный), resources/js/header.js
     снимает его после прокрутки — белая шапка прототипа §7.1. В a.logo обычный логотип и белый ($logoWhite) поверх него;
     без белого — обычный на белой подложке (header--logo-plate). $overlay — полупрозрачная шапка поверх hero главной.
     Правка 05.11, H-11: тёмная шапка и белый логотип — только при тёмном верхе страницы ($overlay, сейчас только главная);
     на остальных страницах сразу белая шапка §7.1 с обычным логотипом, header.js её не трогает.
     Правка 11.11, J-02: второго ряда (подпись и CTA) нет ни на одной странице. Переключатель языка — кнопка с текущим
     кодом («EN ▾») и список остальных языков config('aiqme.languages') (resources/js/lang-menu.js; без JS список
     открывается наведением и фокусом). Адрес языка — версия этой страницы, иначе $switchUrl, иначе главная языка.
     Правка 05.11, H-08: у значка корзины — число товаров корзины сессии (<small>, как в прототипе); пустая — без числа. --}}
@props(['languages' => [], 'switchUrl' => null, 'logo', 'logoWhite' => null, 'overlay' => false])
@php
    $locale = app()->getLocale();
    $switchTo = $locale === 'lt' ? 'en' : 'lt';
    $current = request()->route()?->getName() ?? '';
    $cartCount = request()->hasSession() ? app(\App\Domain\Orders\ProductCart::class)->count() : 0;
@endphp
<a class="skip" href="#main">{{ __('shell.skip') }}</a>
<header @class(['header', 'header--dark' => $overlay, 'header--overlay' => $overlay, 'header--logo-plate' => ! $logoWhite])>
    <div class="wrap head-main">
        <a class="logo" href="{{ route($locale.'.home') }}" aria-label="AIQME"><img src="{{ $logo }}" alt="AIQME">@if ($overlay && $logoWhite)<img class="logo-white" src="{{ $logoWhite }}" alt="">@endif</a>
        <nav class="nav" id="main-nav" aria-label="{{ __('shell.nav_label') }}">
            @foreach (__('shell.nav') as $page => $label)
                @php($active = $current === $locale.'.'.$page || str_starts_with($current, $locale.'.'.$page.'.'))
                <a @class(['active' => $active]) href="{{ route($locale.'.'.$page) }}" @if ($active) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="tools">
            <a class="icon-btn" href="{{ route($locale.'.search') }}" data-modal-open="search-modal" aria-label="{{ __('shell.search') }}"><x-line-icon name="search" /></a>
            <div class="lang-menu" data-lang-menu>
                <button class="icon-btn lang" type="button" aria-expanded="false" aria-controls="lang-list" aria-label="{{ __('shell.language') }}: {{ strtoupper($locale) }}">{{ strtoupper($locale) }} <span aria-hidden="true">▾</span></button>
                <ul class="lang-list" id="lang-list">
                    @foreach (array_diff(config('aiqme.languages'), [$locale]) as $code)
                        <li><a href="{{ $languages[$code] ?? ($code === $switchTo ? $switchUrl : null) ?? route($code.'.home') }}" hreflang="{{ $code }}" lang="{{ $code }}">{{ strtoupper($code) }}</a></li>
                    @endforeach
                </ul>
            </div>
            <a class="icon-btn" href="{{ route(($locale === 'lt' ? 'lt' : 'en').'.account') }}" aria-label="{{ __('shell.account') }}"><x-line-icon name="user" /></a>
            <a class="icon-btn" href="{{ route($locale.'.cart') }}" aria-label="{{ __('shell.cart') }}"><x-line-icon name="cart" />@if ($cartCount > 0)<small data-cart-count>{{ $cartCount }}</small>@endif</a>
            <button class="icon-btn menu-btn" type="button" aria-label="{{ __('shell.menu') }}" aria-expanded="false" aria-controls="main-nav"><x-line-icon name="menu" /></button>
        </div>
    </div>
</header>
<x-modal id="search-modal" :title="__('public.search.title')">
    @include('public.partials.search-form', ['query' => '', 'results' => collect(), 'inputId' => 'search-modal-input', 'resultsId' => 'search-modal-results'])
</x-modal>
