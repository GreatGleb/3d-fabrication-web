{{-- Текстовая версия письма клиенту после оплаты (правка 12.11, K-01): те же данные, что в HTML (OrderPaid::data()); text/plain — без экранирования HTML. --}}
@php($t = fn (string $key, array $replace = []): string => __('orders.customer_mail.'.$key, $replace, $mail['locale']))
{!! $mail['title'] !!}

{!! $t('hello', ['name' => $mail['name']]) !!}

{!! $t('received') !!}
@if ($mail['url'])
{!! $t('view_order') !!}: {!! $mail['url'] !!}
@endif

{!! $t('order', ['number' => $mail['number'], 'date' => $mail['date']]) !!}

{!! $t('product') !!} | {!! $t('quantity') !!} | {!! $t('price') !!} | {!! $t('amount') !!}
@foreach ($mail['lines'] as $line)
{!! $line['description'] !!} | {!! $line['quantity'] !!} | {!! $line['price'] !!} | {!! $line['amount'] !!}
@endforeach

@if ($mail['vat'] === 'vat')
{!! $t('total') !!} {!! $mail['net'] !!}
{!! $t('vat', ['rate' => $mail['rate']]) !!} {!! $mail['vat_total'] !!}
@endif
{!! $t($mail['vat'] === 'none' ? 'total' : 'total_vat') !!} {!! $mail['total'] !!}

{!! $t('delivery_info') !!}
{!! $t('delivery_method') !!} {!! $mail['delivery_method'] !!}
{!! $t('delivery_place') !!} {!! $mail['delivery_place'] !!}
@if ($mail['delivery_time'])
{!! $t('delivery_time') !!} {!! $mail['delivery_time'] !!}
@endif

{!! $t('buyer') !!}
@foreach ($mail['buyer'] as $row)
{!! $row !!}
@endforeach
{!! $mail['buyer_contacts'] !!}

{!! $t('next') !!}
{!! $t('next_shipping') !!}
{!! $t('questions') !!}
{!! $mail['email'] !!} | {!! $mail['phone'] !!}

{!! $t('regards') !!}
{!! $t('team') !!}
{!! $mail['website'] !!} • {!! $mail['address'] !!}
