{{-- Список услуг (§1.2): блоки услуг, как на главной, и CTA-полоса. --}}
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.services')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="__('pages.services.description')" />
    <div class="wrap page-content">
        @if ($services->isEmpty())
            <x-placeholder />
        @else
            @include('public.partials.service-features')
        @endif
    </div>
    @include('public.partials.cta')
</x-layout>
