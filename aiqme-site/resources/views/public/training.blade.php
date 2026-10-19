{{-- Обучение (§1.2): заголовок — page_text training, блок тем и дат — общий с услугой обучения. --}}
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.training')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" />
    <div class="wrap page-content">
        @include('public.partials.training-topics')
    </div>
</x-layout>
