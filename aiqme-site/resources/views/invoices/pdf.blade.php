{{-- PDF счёта (W-17, §3.5): только встроенные стили, без внешних ресурсов; шрифт DejaVu Sans (литовские символы).
     Язык — локаль заявки ($locale); реквизиты — снимок на момент выпуска. Продавец не плательщик VAT — пометка из настроек. --}}
@php
    $t = fn (string $key, array $replace = []): string => __('invoices.'.$key, $replace, $locale);
    $money = fn (int $cents): string => \Illuminate\Support\Number::currency($cents / 100, in: 'EUR', locale: $locale);
    $seller = $invoice->seller;
    $buyer = $invoice->buyer;
    $rate = $seller['vat_payer'] ? $seller['vat_rate'] : 0;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $t($seller['vat_payer'] ? 'title_vat' : 'title') }} {{ $invoice->label() }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #111; }
        h1 { font-size: 16pt; margin: 0 0 4pt; }
        table { width: 100%; border-collapse: collapse; }
        .parties td { width: 50%; vertical-align: top; padding: 12pt 0; }
        .lines th, .lines td { border-bottom: 1px solid #999; padding: 4pt; text-align: right; }
        .lines th:first-child, .lines td:first-child { text-align: left; }
        .totals td { padding: 3pt 4pt; text-align: right; }
        .total td { font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $t($seller['vat_payer'] ? 'title_vat' : 'title') }}</h1>
    <div>{{ $t('number', ['number' => $invoice->label()]) }}</div>
    <div>{{ $t('date', ['date' => $invoice->issued_at->timezone(config('app.timezone'))->format('Y-m-d')]) }}</div>
    <div>{{ $t('order', ['number' => $orderNumber]) }}</div>

    <table class="parties">
        <tr>
            <td>
                <b>{{ $t('seller') }}</b><br>
                {{ $seller['name'] }}<br>
                {{ $t('company_code') }}: {{ $seller['company_code'] }}<br>
                @if ($seller['vat_code'])
                    {{ $t('vat_code') }}: {{ $seller['vat_code'] }}<br>
                @endif
                {{ $t('address') }}: {{ $seller['address'] }}
            </td>
            <td>
                <b>{{ $t('buyer') }}</b><br>
                @if ($buyer['company'])
                    {{ $buyer['company'] }}<br>
                @endif
                @if ($buyer['name'])
                    {{ $buyer['name'] }}<br>
                @endif
                @if ($buyer['company_code'])
                    {{ $t('company_code') }}: {{ $buyer['company_code'] }}<br>
                @endif
                @if ($buyer['vat_code'])
                    {{ $t('vat_code') }}: {{ $buyer['vat_code'] }}<br>
                @endif
                @if ($buyer['address'])
                    {{ $t('address') }}: {{ $buyer['address'] }}
                @endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr><th>{{ $t('description') }}</th><th>{{ $t('quantity') }}</th><th>{{ $t('net') }}</th><th>{{ $t('vat', ['rate' => $rate]) }}</th><th>{{ $t('gross') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr><td>{{ $line->description }}</td><td>{{ $line->quantity }}</td><td>{{ $money($line->net) }}</td><td>{{ $money($line->vat) }}</td><td>{{ $money($line->gross) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>{{ $t('net') }}</td><td>{{ $money($invoice->net_total) }}</td></tr>
        <tr><td>{{ $t('vat', ['rate' => $rate]) }}</td><td>{{ $money($invoice->vat_total) }}</td></tr>
        <tr class="total"><td>{{ $t('gross') }}</td><td>{{ $money($invoice->total) }}</td></tr>
    </table>

    @unless ($seller['vat_payer'])
        <p>{{ $seller['vat_note'] }}</p>
    @endunless
</body>
</html>
