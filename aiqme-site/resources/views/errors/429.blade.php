{{-- 429 (W-09): превышена частота отправки форм с одного IP; разметка — как у 404. Текста в прототипе нет — нейтральная надпись. --}}
<x-layout :title="__('public.forms.throttle')" :description="__('public.forms.throttle')" :noindex="true">
    <x-page-top :title="__('public.forms.throttle')" />
    <div class="wrap page-content">
        <div class="empty">
            <x-button :href="route(app()->getLocale() === 'en' ? 'en.home' : 'lt.home')" arrow>{{ __('pages.not_found.home') }}</x-button>
        </div>
    </div>
</x-layout>
