{{-- Корзина товаров (§1.2, basket прототипа): продажа товаров выключена (D-12) — всегда пустое состояние с заголовком прототипа
     и ссылкой на калькулятор. Без демо-notice и фразы «Try the purchase flow…» (D-21). --}}
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.cart')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="__('pages.cart.description')" />
    <div class="wrap page-content">
        <x-empty :title="__('public.cart.empty')" icon="cart">
            <x-button :href="route($locale.'.calculator')" arrow>{{ __('shell.quote_cta') }}</x-button>
        </x-empty>
    </div>
</x-layout>
