{{-- Кабинет клиента (§1.3), вкладка «Addresses & company» (address-form прототипа, W-07): все поля необязательны, хранятся в
     customer_profiles. Остальные вкладки прототипа (Overview, Orders, Saved models, Invoices) добавляет W-15 — до неё в меню
     только эта вкладка. Без «Demonstration data for this session only.» и «Save demo details» (D-21). Выход — POST с CSRF. --}}
@php($locale = app()->getLocale())
<x-layout :title="__('account.customer_title')" :description="__('account.intro')" :canonical="route($locale.'.account.addresses')"
    :languages="['lt' => route('lt.account.addresses'), 'en' => route('en.account.addresses')]" noindex>
    <x-page-top :title="__('account.customer_title')" />
    <div class="wrap page-content">
        <div class="account-layout">
            <nav class="side-nav" aria-label="{{ __('account.tabs.label') }}">
                <a class="active" href="{{ route($locale.'.account.addresses') }}" aria-current="page"><x-line-icon name="user" /> {{ __('account.tabs.addresses') }}</a>
                <button class="btn small secondary" type="submit" form="logout-form">{{ __('account.logout') }}</button>
            </nav>
            <div>
                <form class="panel" id="address-form" method="post" action="{{ route($locale.'.account.addresses.update') }}">
                    @csrf
                    <h2>{{ __('account.addresses.heading') }}</h2>
                    <div class="grid two form-grid">
                        @foreach (\App\Models\CustomerProfile::FIELDS as $field)
                            <x-field :name="$field" :label="__('account.addresses.fields.'.$field)" :value="$profile->{$field}" maxlength="255" />
                        @endforeach
                    </div>
                    <x-button type="submit">{{ __('account.addresses.submit') }}</x-button>
                    @if (session('status'))
                        <div class="notice success gap" role="status">{{ session('status') }}</div>
                    @endif
                </form>
            </div>
        </div>
        <form id="logout-form" method="post" action="{{ route($locale.'.account.logout') }}" hidden>@csrf</form>
    </div>
</x-layout>
