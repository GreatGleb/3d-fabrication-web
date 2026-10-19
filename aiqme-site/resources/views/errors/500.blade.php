{{-- 500 на обеих локалях: локаль задаёт SetLocaleFromPath по первому сегменту (иначе LT). Разметка — notFound() прототипа. --}}
<x-layout :title="__('pages.server_error.title')" :description="__('pages.server_error.text')" :noindex="true">
    <x-page-top :title="__('pages.server_error.title')" />
    <div class="wrap page-content">
        <div class="empty">
            <p>{{ __('pages.server_error.text') }}</p>
            <x-button :href="route(app()->getLocale() === 'en' ? 'en.home' : 'lt.home')" arrow>{{ __('pages.server_error.home') }}</x-button>
        </div>
    </div>
</x-layout>
