{{-- PDF счёта (W-17, §3.5; правка 02.11, Г5 — состав и порядок блоков образца счёта заказчика): логотип и дата, рамка
     покупателя, заказ с телефоном и датой заказа, условия поставки, строки с ценой за единицу без VAT, итоги, сумма
     прописью, «Apmokėta», банк, подписи и подвал продавца. Только встроенные стили, логотип — data-URI ($logo), шрифт
     DejaVu Sans (литовские символы; с I-04 — запасной). Язык — локаль заявки ($locale); реквизиты — снимок на момент выпуска. Пустые
     необязательные реквизиты не выводятся; продавец не плательщик VAT — VAT 0 и пометка из настроек.
     Доработка 09.11, I-04 — вид образца: логотип ~35 мм с линией под ним, узкая рамка покупателя слева, рамки заказа,
     таблица позиций и итогов с сеткой ячеек, обычное начертание Liberation Sans Narrow (замена Arial Narrow образца,
     регистрируется в InvoicePdf), в подвале — ссылки соцсетей config('aiqme.social') текстом. --}}
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
        @page { margin: 12mm 12mm 34mm; }
        body { font-family: "Liberation Sans Narrow", "DejaVu Sans", sans-serif; font-size: 8pt; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .logo { text-align: right; border-bottom: 1px solid #111; padding-bottom: 8pt; }
        .logo img { width: 35mm; }
        .head td { padding: 6pt 6pt 0; font-size: 10pt; }
        .head .number { font-size: 12pt; font-weight: bold; margin-top: 2pt; }
        .head .date { text-align: right; }
        .buyer { width: 46%; border: 1px solid #111; margin-top: 10pt; }
        .buyer td { padding: 1pt 4pt; }
        .buyer .title { text-align: center; border-bottom: 1px solid #111; padding: 5pt 4pt; }
        .buyer .name { font-size: 10pt; padding: 4pt 4pt 6pt; }
        .buyer .gap td { padding-top: 6pt; }
        .buyer .end td { padding-bottom: 5pt; }
        .buyer .code { width: 42%; }
        .order { margin-top: 6pt; }
        .order td { border: 1px solid #111; padding: 3pt 4pt; }
        .order .orderer { width: 36%; }
        .order .terms { width: 30%; }
        .lines { margin-top: 2pt; }
        .lines th, .lines td { border: 1px solid #111; padding: 5pt 4pt; text-align: right; font-weight: normal; }
        .lines th { text-align: center; }
        .lines td { padding: 7pt 4pt; }
        .lines td:first-child { text-align: left; }
        .lines .narrow { width: 9%; }
        .lines .wide { width: 13%; }
        .totals { width: 15%; margin: 4pt 0 0 auto; }
        .totals td { border: 1px solid #111; padding: 1pt 4pt; text-align: right; }
        .grand { margin-top: 8pt; font-weight: bold; }
        .grand td { padding: 0; vertical-align: bottom; }
        .grand .words { font-size: 10pt; }
        .grand .label { text-align: right; font-size: 7pt; }
        .grand .sum { width: 10%; text-align: right; font-size: 10pt; }
        .note, .paid, .bank { font-size: 10pt; }
        .paid { margin-top: 12pt; }
        .bank { margin-top: 14pt; }
        .bank .account { font-weight: bold; margin-top: 2pt; }
        .received { margin: 12pt 0 0 52%; line-height: 1.8; }
        .signs { margin-top: 16pt; }
        .signs td { width: 46%; padding: 0; }
        .signs td.gap { width: 8%; }
        .signs .line { border-top: 1px solid #111; margin-top: 1pt; }
        .signs .caption { font-size: 5pt; text-align: center; }
        .footer { position: fixed; bottom: -6mm; left: 0; right: 0; font-size: 10pt; border-top: 1px solid #111; }
        .footer td { padding: 8pt 4pt 0 6pt; line-height: 1.6; }
        .footer .seller { width: 25%; }
        .footer .codes { width: 33%; }
        .footer .contacts { width: 18%; }
        .footer a { color: #111; text-decoration: none; }
        .footer .social { font-size: 8pt; }
    </style>
</head>
<body>
    <table class="footer">
        <tr>
            <td class="seller">{{ $seller['name'] }}<br>{{ $seller['address'] }}</td>
            <td class="codes">
                {{ $t('company_code') }}: {{ $seller['company_code'] }}
                @if ($seller['vat_code'])
                    <br>{{ $t('vat_code') }}: {{ $seller['vat_code'] }}
                @endif
            </td>
            <td class="contacts">
                @if ($optional('email'))
                    {{ $optional('email') }}<br>
                @endif
                @if ($optional('phone'))
                    {{ $optional('phone') }}
                @endif
            </td>
            <td>
                @if ($optional('website'))
                    {{ $optional('website') }}<br>
                @endif
                <span class="social">
                    @foreach (config('aiqme.social') as $network)
                        <a href="{{ $network['url'] }}">{{ $network['label'] }}</a>
                    @endforeach
                </span>
            </td>
        </tr>
    </table>

    <div class="logo"><img src="{{ $logo }}" alt="AIQME"></div>

    <table class="head">
        <tr>
            <td>
                {{ $t($seller['vat_payer'] ? 'title_vat' : 'title') }}
                <div class="number">{{ $invoice->label() }}</div>
            </td>
            <td class="date">{{ $t('date', ['date' => $date($invoice->issued_at)]) }}</td>
        </tr>
    </table>

    <table class="buyer">
        <tr><td class="title" colspan="2">{{ $t('buyer') }}</td></tr>
        <tr><td class="name" colspan="2">{{ $buyer['company'] ?: $buyer['name'] }}</td></tr>
        @if ($buyer['address'])
            <tr><td colspan="2">{{ $buyer['address'] }}</td></tr>
        @endif
        @if ($buyer['company_code'])
            <tr class="gap"><td class="code">{{ $t('company_code') }}:</td><td>{{ $buyer['company_code'] }}</td></tr>
        @endif
        @if ($buyer['vat_code'])
            <tr><td class="code">{{ $t('vat_code') }}:</td><td>{{ $buyer['vat_code'] }}</td></tr>
        @endif
        <tr class="end"><td colspan="2"></td></tr>
    </table>

    <table class="order">
        <tr>
            <td class="orderer">{{ $t('order') }}: {{ $orderer }}<br>{{ $t('order_date') }}: {{ $orderDate ? $date($orderDate) : '' }}</td>
            <td class="terms">
                @if ($optional('delivery_terms'))
                    {{ $t('delivery_terms') }}:<br>{{ $optional('delivery_terms') }}
                @endif
            </td>
            <td></td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr><th>{{ $t('description') }}</th><th class="wide">{{ $t('quantity') }}</th><th class="narrow">{{ $t('price') }}</th><th class="narrow">{{ $t('amount') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr><td>{{ $line->description }}</td><td>{{ $line->quantity }}</td><td>{{ $amount((int) round($line->net / max(1, $line->quantity))) }}</td><td>{{ $amount($line->net) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>{{ $t('total') }}</td><td>{{ $amount($invoice->net_total) }}</td></tr>
        <tr><td>{{ $t('vat', ['rate' => $rate]) }}</td><td>{{ $amount($invoice->vat_total) }}</td></tr>
    </table>

    <table class="grand">
        <tr><td class="words">{{ \App\Domain\Invoices\AmountInWords::eur($invoice->total, $locale) }}</td><td class="label">{{ $t('total_vat') }}</td><td class="sum">{{ $amount($invoice->total) }}</td></tr>
    </table>

    @unless ($seller['vat_payer'])
        <p class="note">{{ $seller['vat_note'] }}</p>
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
                <div class="account">{{ $t('account') }}: {{ $optional('iban') }}</div>
            @endif
        </div>
    @endif

    <div class="received">{{ $t('received') }}</div>

    <table class="signs">
        <tr>
            <td>
                @if ($optional('issued_by'))
                    {{ $t('issued_by') }}: {{ $optional('issued_by') }}
                    <div class="line"></div>
                    <div class="caption">{{ $t('signature') }}</div>
                @endif
            </td>
            <td class="gap"></td>
            <td>
                {{ $t('received_by') }}:
                <div class="line"></div>
                <div class="caption">{{ $t('signature') }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
