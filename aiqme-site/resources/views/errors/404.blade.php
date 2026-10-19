{{-- 404 на обеих локалях: локаль задаёт SetLocaleFromPath по первому сегменту (иначе LT). --}}
<x-layout :title="__('pages.not_found.title')" :description="__('pages.not_found.text')" :noindex="true">
    <h1>{{ __('pages.not_found.title') }}</h1>
    <p>{{ __('pages.not_found.text') }}</p>
    <a href="{{ route(app()->getLocale() === 'en' ? 'en.home' : 'lt.home') }}">{{ __('pages.not_found.home') }}</a>
</x-layout>
