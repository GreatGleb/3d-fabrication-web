{{-- Письмо AIQME о заявке формы (W-09, §5.2) и о заявке на изготовление (W-12: номер, файлы, предварительная сумма): минимум данных и ссылка в админку; вложений и полного текста нет.
     Заказ (правка 02.11, Г7): у каждого файла способ (files; у событий до правки — summary), при неоценённых позициях к сумме — пометка.
     Заказ товаров (правка 05.11, H-08, products): позиции «название × количество — сумма» в строке «Prekės», сумма — «Suma».
     Тема — «Nauja projekto užklausa» прототипа; подписи — поля форм прототипа; остальное — нейтральные надписи (progress.md). --}}
@php
    $rows = array_filter([
        __('admin.requests.fields.name') => $data['name'],
        __('admin.requests.fields.email') => $data['email'],
        __('admin.requests.fields.phone') => $data['phone'] ?? null,
        __('admin.requests.fields.company') => $data['company'] ?? null,
        __('admin.requests.fields.'.match ($data['kind']) { 'training' => 'training', 'order' => 'order', default => 'service' }) => $data['subject'],
        __('admin.requests.fields.format') => isset($data['format']) ? __('public.training.formats.'.$data['format']) : null,
        __('admin.requests.fields.participants') => $data['participants'] ?? null,
        __('admin.requests.fields.description') => $data['kind'] === 'contact' ? $data['summary'] : null,
        __('admin.requests.fields.files') => match (true) { ! empty($data['products']) => null, $data['kind'] === 'contact' => $data['attachments'], $data['kind'] === 'order' => implode(', ', $data['files'] ?? [$data['summary']]), default => null },
        __('admin.orders.type_products') => empty($data['products']) ? null : implode(', ', $data['files']),
        __('admin.requests.fields.estimate') => $data['kind'] === 'order' && empty($data['products']) ? $data['amount'].' EUR'.(empty($data['specialist']) ? '' : ' '.__('calculator.summary.specialist')) : null,
        __('admin.orders.columns.total') => empty($data['products']) ? null : $data['amount'].' EUR',
    ], fn ($value) => $value !== null && $value !== '');
@endphp
<!DOCTYPE html>
<html lang="lt">
<head><meta charset="utf-8"><title>{{ __('admin.requests.'.$data['kind'].'_subject') }}</title></head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#11171e">
<h1 style="font-size:18px">{{ __('admin.requests.'.$data['kind'].'_subject') }}</h1>
<table cellpadding="4">
    @foreach ($rows as $label => $value)
        <tr><th align="left" valign="top">{{ $label }}</th><td>{{ $value }}</td></tr>
    @endforeach
</table>
<p>{{ __('admin.requests.admin_note') }}</p>
<p><a href="{{ $data['admin_url'] }}">{{ $data['admin_url'] }}</a></p>
</body>
</html>
