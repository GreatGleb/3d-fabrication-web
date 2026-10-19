{{-- Поиск по сайту (§1.2, searchPanel прототипа): форма GET ?q=, результаты сервера (App\Domain\Content\Search); noindex. --}}
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.search')" :languages="$languages" noindex>
    <x-page-top :title="$heading" />
    <div class="wrap page-content">
        <h2>{{ __('public.search.title') }}</h2>
        @include('public.partials.search-form', ['inputId' => 'global-search', 'resultsId' => 'global-results'])
    </div>
</x-layout>
