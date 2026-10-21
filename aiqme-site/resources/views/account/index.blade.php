{{-- Кабинет клиента (§1.3): разметка account() прототипа — page-top «Customer account», боковое меню вкладок (Overview,
     Orders, Saved models, Addresses & company, Invoices) и кнопка выхода (POST с CSRF), справа — вкладка $tab из
     account/tabs/. Без демо-баннера (D-21). Адрес корня кабинета — Overview. --}}
@php
    $locale = app()->getLocale();
    $route = $tab === 'overview' ? 'account' : 'account.'.$tab;
    $icons = ['overview' => 'user', 'orders' => 'cart', 'models' => 'box', 'addresses' => 'user', 'invoices' => 'user'];
@endphp
<x-layout :title="__('account.customer_title')" :description="__('account.intro')" :canonical="route($locale.'.'.$route)"
    :languages="['lt' => route('lt.'.$route), 'en' => route('en.'.$route)]" noindex>
    <x-page-top :title="__('account.customer_title')" />
    <div class="wrap page-content">
        <div class="account-layout">
            <nav class="side-nav" aria-label="{{ __('account.tabs.label') }}">
                @foreach ($icons as $key => $icon)
                    <a @class(['active' => $tab === $key]) href="{{ route($locale.'.account.'.$key) }}" @if ($tab === $key) aria-current="page" @endif><x-line-icon :name="$icon" /> {{ __('account.tabs.'.$key) }}</a>
                @endforeach
                <button class="btn small secondary" type="submit" form="logout-form">{{ __('account.logout') }}</button>
            </nav>
            <div>
                @include('account.tabs.'.$tab)
            </div>
        </div>
        <form id="logout-form" method="post" action="{{ route($locale.'.account.logout') }}" hidden>@csrf</form>
    </div>
</x-layout>
