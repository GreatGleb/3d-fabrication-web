{{-- Вкладка «Addresses & company» (address-form прототипа, W-07): все поля необязательны, хранятся в customer_profiles.
     Без «Demonstration data for this session only.» и «Save demo details» (D-21). Правка 30.10 (Г4): подпись поля +
     «(neprivaloma)»; fields — без пометки, их же выводит карточка клиента в админке. --}}
<form class="panel" id="address-form" method="post" action="{{ route($locale.'.account.addresses.update') }}">
    @csrf
    <h2>{{ __('account.addresses.heading') }}</h2>
    <div class="grid two form-grid">
        @foreach (\App\Models\CustomerProfile::FIELDS as $field)
            <x-field :name="$field" :label="__('account.addresses.fields.'.$field).' '.__('account.addresses.optional')" :value="$profile->{$field}" maxlength="255" />
        @endforeach
    </div>
    <x-button type="submit">{{ __('account.addresses.submit') }}</x-button>
    <x-status class="gap" />
</form>
