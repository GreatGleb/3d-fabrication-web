{{-- Invoices (account() прототипа): выпущенные PDF-счета своих заказов (W-17) — строка .file-row «номер · заказ · сумма» и
     скачивание через защищённую выдачу (route invoices.download). Подпись прототипа о макетах превью («Preview layouts
     only…») и HTML-превью «Not valid for accounting» не переносятся (D-21). В демо-сборке списка нет (§8.7). --}}
@php($money = fn (int $cents): string => \Illuminate\Support\Number::currency($cents / 100, in: 'EUR', locale: $locale))
<div id="account-invoices">
    <h2>{{ __('account.invoices.heading') }}</h2>
    @forelse ($invoices as $invoice)
        <div class="file-row" data-invoice="{{ $invoice->label() }}">
            <span>{{ $invoice->label() }} · {{ __('calculator.order.title', ['number' => $numbers[$invoice->order_id]]) }} · {{ $money($invoice->total) }}</span>
            <a class="btn small secondary" href="{{ route('invoices.download', $invoice->id) }}">{{ __('account.invoices.download') }}</a>
        </div>
    @empty
        <x-empty class="gap" :title="__('account.invoices.empty')" icon="cart" />
    @endforelse
</div>
