{{-- PDF счёта (W-17, §3.5; правка 02.11, Г5 — состав и порядок блоков образца счёта заказчика): логотип и дата, рамка
     покупателя, заказ с телефоном и датой заказа, условия поставки, строки с ценой за единицу без VAT, итоги, сумма
     прописью, «Apmokėta», банк, подписи и подвал продавца. Только встроенные стили, логотип — data-URI ($logo), шрифт
     DejaVu Sans (литовские символы). Язык — локаль заявки ($locale); реквизиты — снимок на момент выпуска. Пустые
     необязательные реквизиты не выводятся; продавец не плательщик VAT — VAT 0 и пометка из настроек. --}}
@php
    $t = fn (string $key, array $replace = []): string => __('invoices.'.$key, $replace, $locale);
    $amount = fn (int $cents): string => \Illuminate\Support\Number::format($cents / 100, precision: 2, locale: $locale);
    $date = fn ($moment): string => $moment->timezone(config('app.timezone'))->format('Y-m-d');
    $seller = $invoice->seller;
    $buyer = $invoice->buyer;
    $rate = $seller['vat_payer'] ? $seller['vat_rate'] : 0;
    $optional = fn (string $key): ?string => ($seller[$key] ?? null) ?: null;
    $orderer = implode(', ', array_filter([$buyer['name'] ?? null, ($buyer['phone'] ?? null) ? $t('phone').' '.$buyer['phone'] : null]));
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $t($seller['vat_payer'] ? 'title_vat' : 'title') }} {{ $invoice->label() }}</title>
    <style>
        @page { margin: 14mm 14mm 30mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .head td { padding: 0; }
        .head h1 { font-size: 13pt; margin: 6pt 0 2pt; }
        .head .number { font-size: 13pt; font-weight: bold; }
        .head .logo { text-align: right; }
        .head .logo img { height: 36pt; }
        .box { border: 1px solid #111; margin-top: 12pt; }
        .box td { padding: 5pt 6pt; }
        .box .label { width: 34%; font-weight: bold; }
        .order { margin-top: 8pt; }
        .order td { border: 1px solid #111; padding: 5pt 6pt; }
        .order .orderer { width: 42%; }
        .order .date { width: 24%; }
        .nowrap { white-space: nowrap; }
        .order td.terms { border: 0; text-align: right; }
        .lines { margin-top: 12pt; }
        .lines th { border-top: 1px solid #111; border-bottom: 1px solid #111; padding: 4pt; text-align: right; font-weight: bold; }
        .lines td { border-bottom: 1px solid #bbb; padding: 4pt; text-align: right; }
        .lines th:first-child, .lines td:first-child { text-align: left; }
        .totals td { padding: 3pt 4pt; text-align: right; }
        .totals .words { text-align: left; }
        .totals .grand td { font-weight: bold; border-top: 1px solid #111; }
        .paid { margin-top: 8pt; font-weight: bold; }
        .bank { margin-top: 8pt; }
        .received { margin-top: 14pt; font-weight: bold; }
        .signs { margin-top: 14pt; }
        .signs td { width: 50%; padding-right: 14pt; }
        .signs .line { border-bottom: 1px solid #111; height: 14pt; }
        .signs .caption { font-size: 6.5pt; color: #444; }
        .footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 7pt; color: #444; border-top: 1px solid #bbb; }
        .footer td { padding: 4pt 4pt 0 0; }
        .footer .wide { width: 32%; }
    </style>
</head>
<body>
    <table class="footer">
        <tr>
            <td class="wide">{{ $seller['name'] }}<br>{{ $seller['address'] }}</td>
            <td class="wide">
                {{ $t('company_code') }}: {{ $seller['company_code'] }}
                @if ($seller['vat_code'])
                    <br>{{ $t('vat_code') }}: {{ $seller['vat_code'] }}
                @endif
            </td>
            <td>
                @if ($optional('email'))
                    {{ $optional('email') }}<br>
                @endif
                @if ($optional('phone'))
                    {{ $optional('phone') }}
                @endif
            </td>
            <td>
                @if ($optional('website'))
                    {{ $optional('website') }}
                @endif
            </td>
        </tr>
    </table>

    <table class="head">
        <tr>
            <td>
                <h1>{{ $t($seller['vat_payer'] ? 'title_vat' : 'title') }}</h1>
                <div class="number">{{ $invoice->label() }}</div>
            </td>
            <td class="logo">
                <img src="{{ $logo }}" alt="AIQME"><br>
                {{ $t('date', ['date' => $date($invoice->issued_at)]) }}
            </td>
        </tr>
    </table>

    <table class="box">
        <tr><td class="label">{{ $t('buyer') }}</td><td><b>{{ $buyer['company'] ?: $buyer['name'] }}</b></td></tr>
        @if ($buyer['address'])
            <tr><td></td><td>{{ $buyer['address'] }}</td></tr>
        @endif
        @if ($buyer['company_code'])
            <tr><td class="label">{{ $t('company_code') }}</td><td>{{ $buyer['company_code'] }}</td></tr>
        @endif
        @if ($buyer['vat_code'])
            <tr><td class="label">{{ $t('vat_code') }}</td><td>{{ $buyer['vat_code'] }}</td></tr>
        @endif
    </table>

    <table class="order">
        <tr>
            <td class="orderer"><b>{{ $t('order') }}:</b> {{ $orderer }}</td>
            <td class="date"><b>{{ $t('order_date') }}:</b> <span class="nowrap">{{ $orderDate ? $date($orderDate) : '' }}</span></td>
            <td class="terms">
                @if ($optional('delivery_terms'))
                    <b>{{ $t('delivery_terms') }}:</b> {{ $optional('delivery_terms') }}
                @endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr><th>{{ $t('description') }}</th><th>{{ $t('quantity') }}</th><th>{{ $t('price') }}</th><th>{{ $t('amount') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr><td>{{ $line->description }}</td><td>{{ $line->quantity }}</td><td>{{ $amount((int) round($line->net / max(1, $line->quantity))) }}</td><td>{{ $amount($line->net) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td></td><td>{{ $t('total') }}</td><td>{{ $amount($invoice->net_total) }}</td></tr>
        <tr><td></td><td>{{ $t('vat', ['rate' => $rate]) }}</td><td>{{ $amount($invoice->vat_total) }}</td></tr>
        <tr class="grand"><td class="words">{{ \App\Domain\Invoices\AmountInWords::eur($invoice->total, $locale) }}</td><td>{{ $t('total_vat') }}</td><td>{{ $amount($invoice->total) }}</td></tr>
    </table>

    @unless ($seller['vat_payer'])
        <p>{{ $seller['vat_note'] }}</p>
    @endunless

    <div class="paid">{{ $t('paid', ['amount' => $amount($invoice->total)]) }}</div>

    @if ($optional('bank') || $optional('swift') || $optional('iban'))
        <div class="bank">
            @if ($optional('bank') || $optional('swift'))
                <div>
                    @if ($optional('bank'))
                        {{ $t('bank') }}: {{ $optional('bank') }}
                    @endif
                    @if ($optional('swift'))
                        {{ $t('swift') }}: {{ $optional('swift') }}
                    @endif
                </div>
            @endif
            @if ($optional('iban'))
                <div>{{ $t('account') }}: {{ $optional('iban') }}</div>
            @endif
        </div>
    @endif

    <div class="received">{{ $t('received') }}</div>

    <table class="signs">
        <tr>
            <td>
                @if ($optional('issued_by'))
                    <b>{{ $t('issued_by') }}:</b> {{ $optional('issued_by') }}
                    <div class="line"></div>
                    <div class="caption">{{ $t('signature') }}</div>
                @endif
            </td>
            <td>
                <b>{{ $t('received_by') }}:</b>
                <div class="line"></div>
                <div class="caption">{{ $t('signature') }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
