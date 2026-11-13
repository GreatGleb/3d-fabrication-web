{{-- Текстовая версия письма с трек-номером (правка 12.11, K-02): те же данные, что в HTML (OrderShipped::data()), в общем макете mail.layout-text. --}}
@extends('mail.layout-text')
@php($t = fn (string $key, array $replace = []): string => __('orders.customer_mail.'.$key, $replace, $mail['locale']))
@section('content')
{!! $t('shipped', ['number' => $mail['number']]) !!}

{!! $t('shipment_info') !!}
{!! $t('carrier') !!} {!! $mail['carrier'] !!}
{!! $t('tracking_number') !!} {!! $mail['tracking_number'] !!}
@if ($mail['url'])

{!! $t('view_order') !!}: {!! $mail['url'] !!}
@endif

@endsection
