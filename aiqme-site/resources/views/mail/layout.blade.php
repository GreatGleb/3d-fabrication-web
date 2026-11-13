{{-- Общий макет писем клиенту о заказе (правка 12.11, K-01/K-02): шапка с логотипом и заголовком, «Sveiki, {Vardas},»,
     содержимое письма (@section('content')), «Jei turite klausimų…» с контактами и подпись. Почтовая вёрстка на таблицах со
     встроенными стилями, ширина 600 px (на телефоне сжимается), без внешнего CSS и JS. Данные — CustomerOrderMail::common() + письма. --}}
@php
    $t = fn (string $key, array $replace = []): string => __('orders.customer_mail.'.$key, $replace, $mail['locale']);
    $text = 'font-family:Arial,Helvetica,sans-serif;color:#333333;';
    $blue = 'color:#1f6aa5;';
@endphp
<!DOCTYPE html>
<html lang="{{ $mail['locale'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $mail['subject'] }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fa;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
    <tr>
        <td align="center" style="padding:16px 8px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #d5dae0;border-radius:12px;border-collapse:separate;">
                <tr>
                    <td style="background:#000000;border-radius:11px 11px 0 0;padding:20px 30px 18px;">
                        <img src="{{ $mail['logo'] }}" width="210" alt="AIQME" style="display:block;width:210px;max-width:100%;height:auto;border:0;">
                        <h1 style="{{ $text }}color:#ffffff;font-size:20px;font-weight:bold;margin:22px 0 0;">{{ $mail['title'] }}</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:26px 30px 30px;">
                        <p style="{{ $text }}font-size:16px;margin:0 0 14px;">{{ $t('hello', ['name' => $mail['name']]) }}</p>
@yield('content')
                        <p style="{{ $text }}color:#555555;font-size:13px;margin:0 0 8px;">{{ $t('questions') }}</p>
                        <p style="{{ $text }}{{ $blue }}font-size:14px;font-weight:bold;margin:0;">{{ $mail['email'] }}  |  {{ $mail['phone'] }}</p>

                        <p style="border-top:1px solid #d5dae0;margin:26px 0 22px;font-size:0;line-height:0;">&nbsp;</p>
                        <p style="{{ $text }}color:#555555;font-size:13px;margin:0 0 8px;">{{ $t('regards') }}</p>
                        <p style="{{ $text }}font-size:15px;font-weight:bold;margin:0 0 20px;">{{ $t('team') }}</p>
                        <p style="{{ $text }}color:#777777;font-size:12px;margin:0;">{{ $mail['website'] }}  •  {{ $mail['address'] }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
