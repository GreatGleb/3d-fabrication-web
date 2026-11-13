{{-- Кнопка «Peržiūrėti užsakymą» писем клиенту (правка 12.11, K-01/K-02): только заказу клиента кабинета ($mail['url']). --}}
@if ($mail['url'])
    <p style="margin:0 0 22px;"><a href="{{ $mail['url'] }}" style="{{ $text }}display:inline-block;background:#1f6aa5;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;padding:11px 20px;border-radius:6px;">{{ $t('view_order') }}</a></p>
@endif
