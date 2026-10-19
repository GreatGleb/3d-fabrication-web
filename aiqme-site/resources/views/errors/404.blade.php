{{-- 404 на обеих локалях: локаль задаёт SetLocaleFromPath по первому сегменту (иначе LT). Разметка — notFound() прототипа. --}}
<x-layout :title="__('pages.not_found.title')" :description="__('pages.not_found.text')" :noindex="true">
    <x-page-top :title="__('pages.not_found.title')" />
    <div class="wrap page-content">
        <div class="empty">
            <p>{{ __('pages.not_found.text') }}</p>
            <x-button :href="route(app()->getLocale() === 'en' ? 'en.home' : 'lt.home')" arrow>{{ __('pages.not_found.home') }}</x-button>
        </div>
    </div>
</x-layout>
