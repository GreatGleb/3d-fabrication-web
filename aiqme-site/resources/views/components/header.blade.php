{{-- Шапка прототипа (§1.1): логотип ($logo — адрес из layout, D-43), 7 пунктов, поиск, LT/EN, корзина, кнопка меню ≤900 px (resources/js/menu.js)
     и второй ряд с CTA. Активный пункт — текущая страница или вложенная (имя маршрута «<locale>.<page>.…»).
     Поиск — модальное окно прототипа (searchPanel, без JS — ссылка на страницу поиска). Значок кабинета ведёт на кабинет LT/EN (W-07; на /ru — EN). $languages — версии этой страницы для переключателя (W-02); $switchUrl —
     адрес, если версии другой локали нет (W-05).
     Шапка-шторка (правка 28.10, П2): сервер рисует тёмное состояние header--dark (первый кадр уже тёмный), resources/js/header.js
     снимает его после прокрутки — белая шапка прототипа §7.1. В a.logo обычный логотип и белый ($logoWhite) поверх него;
     без белого — обычный на белой подложке (header--logo-plate). $overlay — полупрозрачная шапка поверх hero главной. --}}
@props(['languages' => [], 'switchUrl' => null, 'logo', 'logoWhite' => null, 'overlay' => false])
@php
    $locale = app()->getLocale();
    $switchTo = $locale === 'lt' ? 'en' : 'lt';
    $current = request()->route()?->getName() ?? '';
@endphp
<a class="skip" href="#main">{{ __('shell.skip') }}</a>
<header @class(['header', 'header--dark', 'header--overlay' => $overlay, 'header--logo-plate' => ! $logoWhite])>
    <div class="wrap head-main">
        <a class="logo" href="{{ route($locale.'.home') }}" aria-label="AIQME"><img src="{{ $logo }}" alt="AIQME">@if ($logoWhite)<img class="logo-white" src="{{ $logoWhite }}" alt="">@endif</a>
        <nav class="nav" id="main-nav" aria-label="{{ __('shell.nav_label') }}">
            @foreach (__('shell.nav') as $page => $label)
                @php($active = $current === $locale.'.'.$page || str_starts_with($current, $locale.'.'.$page.'.'))
                <a @class(['active' => $active]) href="{{ route($locale.'.'.$page) }}" @if ($active) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="tools">
            <a class="icon-btn" href="{{ route($locale.'.search') }}" data-modal-open="search-modal" aria-label="{{ __('shell.search') }}"><x-line-icon name="search" /></a>
            <a href="{{ $languages[$switchTo] ?? $switchUrl ?? route($switchTo.'.home') }}" hreflang="{{ $switchTo }}" aria-label="{{ __('pages.switch_language') }}" class="icon-btn lang">@if ($switchTo === 'en')LT / <span>EN</span>@else<span>LT</span> / EN @endif</a>
            <a class="icon-btn" href="{{ route(($locale === 'lt' ? 'lt' : 'en').'.account') }}" aria-label="{{ __('shell.account') }}"><x-line-icon name="user" /></a>
            <a class="icon-btn" href="{{ route($locale.'.cart') }}" aria-label="{{ __('shell.cart') }}"><x-line-icon name="cart" /></a>
            <button class="icon-btn menu-btn" type="button" aria-label="{{ __('shell.menu') }}" aria-expanded="false" aria-controls="main-nav"><x-line-icon name="menu" /></button>
        </div>
    </div>
    <x-header-cta />
</header>
<x-modal id="search-modal" :title="__('public.search.title')">
    @include('public.partials.search-form', ['query' => '', 'results' => collect(), 'inputId' => 'search-modal-input', 'resultsId' => 'search-modal-results'])
</x-modal>
