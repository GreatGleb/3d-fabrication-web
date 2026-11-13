{{-- Письмо клиенту с трек-номером (правка 12.11, K-02) в общем макете mail.layout. Данные — OrderShipped::data(). --}}
@extends('mail.layout')
@php
    $t = fn (string $key, array $replace = []): string => __('orders.customer_mail.'.$key, $replace, $mail['locale']);
    $text = 'font-family:Arial,Helvetica,sans-serif;color:#333333;';
    $blue = 'color:#1f6aa5;';
@endphp
@section('content')
                        <p style="{{ $text }}font-size:16px;margin:0 0 20px;">{{ $t('shipped', ['number' => $mail['number']]) }}</p>

                        <h2 style="{{ $text }}font-size:19px;font-weight:bold;margin:30px 0 14px;">{{ $t('shipment_info') }}</h2>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #d5dae0;border-radius:6px;border-collapse:separate;margin:0 0 22px;">
                            <tr><td style="padding:12px 16px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                    <tr><td style="{{ $text }}color:#555555;font-size:14px;font-weight:bold;padding:6px 10px 6px 0;width:38%;vertical-align:top;">{{ $t('carrier') }}</td><td style="{{ $text }}font-size:14px;padding:6px 0;word-break:break-word;">{{ $mail['carrier'] }}</td></tr>
                                    <tr><td style="{{ $text }}color:#555555;font-size:14px;font-weight:bold;padding:6px 10px 6px 0;vertical-align:top;">{{ $t('tracking_number') }}</td><td style="{{ $text }}{{ $blue }}font-size:14px;font-weight:bold;padding:6px 0;word-break:break-word;">{{ $mail['tracking_number'] }}</td></tr>
                                </table>
                            </td></tr>
                        </table>
                        @include('mail.order-button')

@endsection
