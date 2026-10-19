@use('App\Support\Seo\StaticPages')
@use('Illuminate\Support\Facades\Lang')
<x-layout
    :title="__('pages.'.$page.'.title')"
    :description="Lang::has('pages.'.$page.'.description') ? __('pages.'.$page.'.description') : __('pages.default_description')"
    :canonical="route(app()->getLocale().'.'.$page)"
    :languages="StaticPages::alternates($page)"
    :noindex="app()->getLocale() === 'ru' || in_array($page, StaticPages::NOINDEX, true)">
    <h1>{{ __('pages.'.$page.'.title') }}</h1>
</x-layout>
