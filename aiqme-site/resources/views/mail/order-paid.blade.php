{{-- Письмо клиенту после оплаты (правка 12.11, K-01): шаблоны заказчика 1 (товары) и 2 (калькулятор) в общем макете
     mail.layout (K-02). Данные — OrderPaid::data(). --}}
@extends('mail.layout')
@php
    $t = fn (string $key, array $replace = []): string => __('orders.customer_mail.'.$key, $replace, $mail['locale']);
    $text = 'font-family:Arial,Helvetica,sans-serif;color:#333333;';
    $cell = $text.'font-size:14px;padding:12px 10px;border:1px solid #d5dae0;vertical-align:top;';
    $head = $text.'font-size:14px;font-weight:bold;padding:12px 10px;border:1px solid #d5dae0;background:#f5f7fa;text-align:left;';
    $blue = 'color:#1f6aa5;';
@endphp
@section('content')
                        <p style="{{ $text }}font-size:16px;margin:0 0 20px;">{{ $t('received') }}</p>
                        @include('mail.order-button')
                        <h2 style="{{ $text }}font-size:19px;font-weight:bold;margin:0 0 18px;">{{ $t('order', ['number' => $mail['number'], 'date' => $mail['date']]) }}</h2>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                            <tr>
                                <th style="{{ $head }}">{{ $t('product') }}</th>
                                <th style="{{ $head }}width:15%;">{{ $t('quantity') }}</th>
                                <th style="{{ $head }}width:15%;">{{ $t('price') }}</th>
                                <th style="{{ $head }}width:15%;">{{ $t('amount') }}</th>
                            </tr>
                            @foreach ($mail['lines'] as $line)
                                <tr data-line>
                                    <td style="{{ $cell }}word-break:break-word;">{{ $line['description'] }}</td>
                                    <td style="{{ $cell }}white-space:nowrap;">{{ $line['quantity'] }}</td>
                                    <td style="{{ $cell }}white-space:nowrap;">{{ $line['price'] }}</td>
                                    <td style="{{ $cell }}white-space:nowrap;">{{ $line['amount'] }}</td>
                                </tr>
                            @endforeach
                        </table>

                        @if ($mail['vat'] === 'vat')
                            <table role="presentation" align="right" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:14px;width:55%;">
                                <tr><td style="{{ $cell }}font-size:13px;padding:8px 16px;border-right:0;">{{ $t('total') }}</td><td style="{{ $cell }}font-size:13px;padding:8px 16px;border-left:0;text-align:right;white-space:nowrap;">{{ $mail['net'] }}</td></tr>
                                <tr><td style="{{ $cell }}font-size:13px;padding:8px 16px;border-right:0;">{{ $t('vat', ['rate' => $mail['rate']]) }}</td><td style="{{ $cell }}font-size:13px;padding:8px 16px;border-left:0;text-align:right;white-space:nowrap;">{{ $mail['vat_total'] }}</td></tr>
                            </table>
                        @endif
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="clear:both;">
                            <tr data-grand>
                                <td style="{{ $text }}font-size:15px;font-weight:bold;text-align:right;padding:18px 20px 0 0;">{{ $t($mail['vat'] === 'none' ? 'total' : 'total_vat') }}</td>
                                <td style="{{ $text }}color:#111111;font-size:20px;font-weight:bold;text-align:right;padding:16px 16px 0 0;white-space:nowrap;width:30%;">{{ $mail['total'] }}</td>
                            </tr>
                        </table>

                        <h2 style="{{ $text }}font-size:19px;font-weight:bold;margin:30px 0 14px;">{{ $t('delivery_info') }}</h2>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #d5dae0;border-radius:6px;border-collapse:separate;">
                            <tr><td style="padding:12px 16px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                    <tr><td style="{{ $text }}color:#555555;font-size:14px;font-weight:bold;padding:6px 10px 6px 0;width:38%;vertical-align:top;">{{ $t('delivery_method') }}</td><td style="{{ $text }}font-size:14px;padding:6px 0;">{{ $mail['delivery_method'] }}</td></tr>
                                    <tr><td style="{{ $text }}color:#555555;font-size:14px;font-weight:bold;padding:6px 10px 6px 0;vertical-align:top;">{{ $t('delivery_place') }}</td><td style="{{ $text }}font-size:14px;padding:6px 0;">{{ $mail['delivery_place'] }}</td></tr>
                                    @if ($mail['delivery_time'])
                                        <tr data-delivery-time><td style="{{ $text }}color:#555555;font-size:14px;font-weight:bold;padding:6px 10px 6px 0;vertical-align:top;">{{ $t('delivery_time') }}</td><td style="{{ $text }}{{ $blue }}font-size:14px;font-weight:bold;padding:6px 0;">{{ $mail['delivery_time'] }}</td></tr>
                                    @endif
                                </table>
                            </td></tr>
                        </table>

                        <h2 style="{{ $text }}font-size:19px;font-weight:bold;margin:30px 0 14px;">{{ $t('buyer') }}</h2>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #d5dae0;border-radius:6px;border-collapse:separate;">
                            <tr><td style="padding:16px;">
                                @foreach ($mail['buyer'] as $row)
                                    <p style="{{ $text }}font-size:14px;margin:0 0 8px;">{{ $row }}</p>
                                @endforeach
                                <p style="{{ $text }}{{ $blue }}font-size:14px;margin:0;word-break:break-word;">{{ $mail['buyer_contacts'] }}</p>
                            </td></tr>
                        </table>

                        <h2 style="{{ $text }}font-size:19px;font-weight:bold;margin:30px 0 12px;">{{ $t('next') }}</h2>
                        <p style="{{ $text }}color:#555555;font-size:13px;margin:0 0 8px;">{{ $t('next_shipping') }}</p>

@endsection
