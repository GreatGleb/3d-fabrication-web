@use('App\Support\Seo\StaticPages')
@use('Illuminate\Support\Facades\Lang')
{{-- Заготовка статической страницы: верх страницы прототипа; содержимое добавляют задачи страниц (W-05 и далее). --}}
@php($description = Lang::has('pages.'.$page.'.description') ? __('pages.'.$page.'.description') : null)
<x-layout
    :title="__('pages.'.$page.'.title')"
    :description="$description ?? __('pages.default_description')"
    :canonical="route(app()->getLocale().'.'.$page)"
    :languages="StaticPages::alternates($page)"
    :noindex="app()->getLocale() === 'ru' || in_array($page, StaticPages::NOINDEX, true)">
    <x-page-top :title="__('pages.'.$page.'.title')" :intro="$description" :breadcrumbs="$page !== 'home'" />
</x-layout>
