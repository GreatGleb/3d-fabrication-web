{{-- 429 (W-09): превышена частота отправки форм с одного IP; разметка — как у 404. Текст — через сколько минут повторить
     (правка 11.11, J-06), из Retry-After лимитера. --}}
@php($message = __('public.forms.throttle', ['minutes' => max(1, (int) ceil(($exception->getHeaders()['Retry-After'] ?? 60) / 60))]))
<x-layout :title="$message" :description="$message" :noindex="true">
    <x-page-top :title="$message" />
    <div class="wrap page-content">
        <div class="empty">
            <x-button :href="route(app()->getLocale() === 'en' ? 'en.home' : 'lt.home')" arrow>{{ __('pages.not_found.home') }}</x-button>
        </div>
    </div>
</x-layout>
