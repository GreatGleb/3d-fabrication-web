{{-- Админка (§1.4): вход администратора через форму кабинета и защита адреса (W-07, EnsureAdmin — 404 всем остальным).
     Оболочку, меню вкладок и Overview добавляет W-08; здесь — заголовок прототипа и выход. --}}
@php($locale = app()->getLocale())
<x-layout :title="__('account.admin.title')" :description="__('account.admin.title')" :canonical="route($locale.'.admin')"
    :languages="['lt' => route('lt.admin'), 'en' => route('en.admin')]" noindex>
    <x-page-top :title="__('account.admin.title')" />
    <div class="wrap page-content">
        <form method="post" action="{{ route($locale.'.account.logout') }}">
            @csrf
            <x-button type="submit" variant="secondary" small>{{ __('account.logout') }}</x-button>
        </form>
    </div>
</x-layout>
