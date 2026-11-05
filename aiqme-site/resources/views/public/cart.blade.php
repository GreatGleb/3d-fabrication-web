{{-- Корзина товаров (§1.2, basket прототипа). Пустая — прежнее пустое состояние с заголовком прототипа и ссылкой на
     калькулятор, без демо-notice и фразы «Try the purchase flow…» (D-21). Правка 05.11, H-08: товары «Pirkti» —
     таблица basket прототипа (картинка и название, количество — отправка формы при изменении, cart.js; без JS — Enter,
     сумма строки, «Pašalinti»), под ней форма «Pirkėjas ir pristatymas» шага калькулятора (те же поля, телефон обязателен,
     доставка ES / atsiėmimas) с «Tęsti apsipirkimą» и «Pereiti prie apmokėjimo»; справа — «Užsakymo suvestinė» с
     доставкой товаров и итогом. Цены — с PVM, без демо-строк прототипа (скидка, PVM 21 %, пометки demo, D-21). --}}
@php
    $locale = app()->getLocale();
    $money = fn (int $cents): string => \Illuminate\Support\Number::currency($cents / 100, in: 'EUR', locale: $locale);
    $subtotal = $lines->sum('total');
    $shipping = old('delivery', 'ship') === 'ship';
@endphp
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.cart')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="__('pages.cart.description')" />
    <div class="wrap page-content">
        <x-status />
        @if ($lines->isEmpty())
            <x-empty :title="__('public.cart.empty')" icon="cart">
                <x-button :href="route($locale.'.calculator')" arrow>{{ __('shell.quote_cta') }}</x-button>
            </x-empty>
        @else
            <div class="quote-layout" id="cart">
                <div>
                    <x-table class="cart-table">
                        <thead><tr><th>{{ __('public.cart.product') }}</th><th>{{ __('public.cart.quantity') }}</th><th>{{ __('public.cart.price') }}</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($lines as $id => $line)
                                @php($image = $line['product']->item->getFirstMedia('images'))
                                <tr data-cart-line="{{ $id }}">
                                    <td>
                                        <a class="cart-product" href="{{ route($locale.'.products.show', $line['product']->slug) }}">
                                            @if ($image)
                                                @include('public.partials.media', ['mediaImage' => $image, 'mediaSizes' => '60px'])
                                            @endif
                                            <span>{{ $line['product']->title }}</span>
                                        </a>
                                    </td>
                                    <td>
                                        <form method="post" action="{{ route($locale.'.cart.update') }}" data-cart-quantity>
                                            @csrf
                                            <input type="hidden" name="item" value="{{ $id }}">
                                            <input type="number" name="quantity" min="1" max="{{ \App\Domain\Orders\ProductCart::QUANTITY_MAX }}" value="{{ $line['quantity'] }}" required aria-label="{{ __('public.cart.quantity') }}: {{ $line['product']->title }}">
                                        </form>
                                    </td>
                                    <td data-line-total>{{ $money($line['total']) }}</td>
                                    <td>
                                        <form method="post" action="{{ route($locale.'.cart.update') }}">
                                            @csrf
                                            <input type="hidden" name="item" value="{{ $id }}">
                                            <input type="hidden" name="quantity" value="0">
                                            <button class="icon-btn" type="submit" aria-label="{{ __('public.cart.remove') }}: {{ $line['product']->title }}"><x-line-icon name="close" /></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>

                    <form class="panel gap" id="checkout-form" method="post" novalidate action="{{ Route::has($locale.'.cart.checkout') ? route($locale.'.cart.checkout') : '' }}">
                        @csrf
                        @include('public.partials.honeypot')
                        <h2>{{ __('calculator.checkout.title') }}</h2>
                        <p>{{ __('calculator.checkout.intro') }}</p>
                        @error('form')<div class="notice error" role="alert">{{ $message }}</div>@enderror
                        <div class="grid two form-grid">
                            <x-field name="name" :label="__('calculator.checkout.name')" :value="$buyer['name'] ?? null" required autocomplete="name" />
                            <x-field name="email" type="email" :label="__('calculator.checkout.email')" :value="$buyer['email'] ?? null" required autocomplete="email" />
                            <x-field name="company" :label="__('calculator.checkout.company')" :value="$buyer['company'] ?? null" autocomplete="organization" />
                            <x-field name="company_code" :label="__('calculator.checkout.company_code')" :value="$buyer['company_code'] ?? null" />
                            <x-field name="vat_code" :label="__('calculator.checkout.vat_code')" :value="$buyer['vat_code'] ?? null" />
                            <x-field name="phone" type="tel" :label="__('calculator.checkout.phone')" required autocomplete="tel" />
                        </div>
                        <x-field name="delivery" type="select" :label="__('calculator.checkout.delivery')" :options="__('calculator.checkout.deliveries')" data-cart-delivery />
                        <div class="grid two form-grid" data-address @if (! $shipping) hidden @endif>
                            <x-field name="country" type="select" :label="__('calculator.checkout.country')" :options="__('calculator.countries')" required />
                            <x-field name="city" :label="__('calculator.checkout.city')" :value="$buyer['city'] ?? null" required autocomplete="address-level2" />
                            <x-field name="address" :label="__('calculator.checkout.address')" :value="$buyer['address'] ?? null" required autocomplete="street-address" />
                            <x-field name="postcode" :label="__('calculator.checkout.postcode')" :value="$buyer['postcode'] ?? null" required autocomplete="postal-code" />
                        </div>
                        <div class="quote-actions">
                            <x-button :href="route($locale.'.products')" variant="secondary">{{ __('public.cart.continue') }}</x-button>
                            <button type="submit" class="btn" @disabled(! Route::has($locale.'.cart.checkout'))>{{ __('public.cart.checkout') }} <x-line-icon name="arrow" /></button>
                        </div>
                    </form>
                </div>

                <aside class="panel quote-summary">
                    <h3>{{ __('calculator.summary.title') }}</h3>
                    @foreach ($lines as $line)
                        <div class="summary-row"><span>{{ $line['product']->title }} × {{ $line['quantity'] }}</span><b>{{ $money($line['total']) }}</b></div>
                    @endforeach
                    <div class="summary-row" data-delivery-row @if (! $shipping) hidden @endif><span>{{ __('admin.orders.delivery') }}</span><b>{{ $money($delivery) }}</b></div>
                    <div class="summary-row total"><span>{{ __('public.cart.total') }}</span><span data-cart-total data-ship="{{ $money($subtotal + $delivery) }}" data-pickup="{{ $money($subtotal) }}">{{ $money($subtotal + ($shipping ? $delivery : 0)) }}</span></div>
                </aside>
            </div>
        @endif
    </div>
</x-layout>
