{{-- Статус письма AIQME по заявке (W-09): сбой почты виден в админке, заявка при этом сохранена. --}}
@php($status = $event?->mail_status ?? 'none')
<span @class(['tag', 'green' => $status === 'sent', 'orange' => $status === 'failed'])>{{ __('admin.requests.mail_status.'.$status) }}</span>
