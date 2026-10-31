{{-- Страница оплаты платёжного запроса (§3.3, W-16): номер заказа, сумма запроса и Stripe Payment Element (только карта,
     D-33). Карточные данные вводятся во фрейме Stripe и не проходят через AIQME. Без формы — состояния: администратор,
     «обрабатывается» (intent succeeded/processing или возврат с redirect_status), «проверяется» (creating), оплачен,
     закрыт, «оплата временно недоступна». Разметка — блоки карточки заказа прототипа (panel, summary-row, notice). --}}
@php($amount = \Illuminate\Support\Number::currency($payment->amount / 100, in: 'EUR', locale: app()->getLocale()))
<x-layout :title="__('checkout.title')" :description="__('calculator.order.title', ['number' => $number])" noindex>
    <x-page-top :title="__('checkout.title')" />
    <div class="wrap page-content">
        <div class="panel" id="checkout" data-checkout-status="{{ $status }}">
            <h2>{{ __('calculator.order.title', ['number' => $number]) }}</h2>
            <div class="summary-row total gap"><span>{{ __('checkout.amount') }}</span><b data-amount="{{ $payment->amount }}">{{ $amount }}</b></div>
            @if ($status === 'form')
                <form class="gap" id="payment-form" data-stripe-key="{{ config('services.stripe.key') }}" data-client-secret="{{ $clientSecret }}" data-return-url="{{ $returnUrl }}">
                    <div id="payment-element"></div>
                    <p class="notice warning gap" data-checkout-error role="alert" hidden></p>
                    <x-button type="submit" class="gap">{{ __('checkout.pay', ['amount' => $amount]) }}</x-button>
                </form>
                <script src="https://js.stripe.com/v3/" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
            @else
                <div @class(['notice gap', 'success' => in_array($status, ['processing', 'paid'], true), 'warning' => ! in_array($status, ['processing', 'paid'], true)]) data-checkout-message>{{ __('checkout.states.'.$status) }}</div>
            @endif
        </div>
    </div>
</x-layout>
