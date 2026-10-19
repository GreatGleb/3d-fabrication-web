{{-- 500 на обеих локалях: локаль задаёт SetLocaleFromPath по первому сегменту (иначе LT). --}}
<x-layout :title="__('pages.server_error.title')" :description="__('pages.server_error.text')" :noindex="true">
    <h1>{{ __('pages.server_error.title') }}</h1>
    <p>{{ __('pages.server_error.text') }}</p>
    <a href="{{ route(app()->getLocale() === 'en' ? 'en.home' : 'lt.home') }}">{{ __('pages.server_error.home') }}</a>
</x-layout>
