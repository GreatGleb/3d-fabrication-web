@use('App\Support\Seo\StaticPages')
{{-- Вход, регистрация, восстановление и новый пароль по ссылке из письма (W-07) — форма auth-form прототипа.
     Гость на вкладке кабинета видит форму входа (§4.6). Без демо-баннера, «Demo password», подсказки о пробном
     пароле и кнопки «Open an account example» (D-21). Пароль — minlength 8 (§6.3), autocomplete current/new-password. --}}
@php
    $locale = app()->getLocale();
    $route = request()->route()->getName();
    $page = Str::after($route, $locale.'.');
    // Параметры адреса (номер заказа карточки кабинета, W-15) — те же у версий страницы.
    $parameters = $mode === 'new_password' ? [] : request()->route()->parameters();
    // Версии страницы для переключателя: та же форма на другом языке; у ссылки сброса с токеном — форма запроса ссылки.
    $languages = $mode === 'new_password' ? [] : collect(StaticPages::PUBLIC_LOCALES)->mapWithKeys(fn ($l) => [$l => route($l.'.'.$page, $parameters)])->all();
    $other = $locale === 'lt' ? 'en' : 'lt';
    $action = match ($mode) {
        'register' => route($locale.'.account.register.store'),
        'reset' => route($locale.'.account.reset.store'),
        'new_password' => route($locale.'.account.reset.update', ['token' => $token]),
        default => route($locale.'.account.login.store'),
    };
@endphp
<x-layout :title="__('account.title')" :description="__('account.intro')" :canonical="$mode === 'new_password' ? null : route($route, $parameters)"
    :languages="$languages" :switch-url="route($other.'.account.reset')" noindex>
    <x-page-top :title="__('account.title')" :intro="__('account.intro')" />
    <div class="wrap page-content">
        <div class="auth">
            <form class="panel" id="auth-form" data-mode="{{ $mode }}" method="post" action="{{ $action }}">
                @csrf
                <h2>{{ __('account.'.$mode.'.heading') }}</h2>
                @if ($mode === 'register')
                    <x-field name="name" :label="__('account.fields.name')" required maxlength="255" autocomplete="name" />
                @endif
                <x-field name="email" type="email" :label="__('account.fields.email')" :value="$email ?? null" required maxlength="255" autocomplete="email" />
                @if ($mode !== 'reset')
                    <x-field name="password" type="password" :label="$mode === 'new_password' ? __('account.new_password.field') : __('account.fields.password')"
                        required minlength="8" :autocomplete="$mode === 'login' ? 'current-password' : 'new-password'" />
                @endif
                <x-button type="submit">{{ __('account.'.$mode.'.submit') }}</x-button>
                <div id="auth-result" role="status">
                    @if (session('status'))
                        <div class="notice success gap">{{ session('status') }}</div>
                    @endif
                </div>
                <div class="buttons gap">
                    <a class="text-link" href="{{ route($locale.'.account.register') }}">{{ __('account.links.register') }}</a>
                    <a class="text-link" href="{{ route($locale.'.account.reset') }}">{{ __('account.links.reset') }}</a>
                    <a class="text-link" href="{{ route($locale.'.account.login') }}">{{ __('account.links.login') }}</a>
                </div>
            </form>
            <div class="buttons gap">
                <x-button variant="secondary" :href="route($locale.'.calculator')" arrow>{{ __('account.guest') }}</x-button>
            </div>
        </div>
    </div>
</x-layout>
