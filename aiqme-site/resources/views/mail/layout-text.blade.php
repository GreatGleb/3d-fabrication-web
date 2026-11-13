{{-- Общий макет текстовой версии писем клиенту о заказе (правка 12.11, K-01/K-02): заголовок, приветствие, содержимое письма, контакты и подпись; text/plain — без экранирования HTML. --}}
@php($t = fn (string $key, array $replace = []): string => __('orders.customer_mail.'.$key, $replace, $mail['locale']))
{!! $mail['title'] !!}

{!! $t('hello', ['name' => $mail['name']]) !!}

@yield('content')
{!! $t('questions') !!}
{!! $mail['email'] !!} | {!! $mail['phone'] !!}

{!! $t('regards') !!}
{!! $t('team') !!}
{!! $mail['website'] !!} • {!! $mail['address'] !!}
