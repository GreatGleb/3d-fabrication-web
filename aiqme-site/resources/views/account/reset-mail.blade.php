{{-- Письмо сброса пароля (W-07): ссылка и срок её действия; текста письма в прототипе нет — нейтральные надписи. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><title>{{ __('account.reset_mail.subject') }}</title></head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#11171e">
<p>{{ __('account.reset_mail.intro') }}</p>
<p><a href="{{ $url }}">{{ __('account.reset_mail.action') }}</a></p>
<p>{{ __('account.reset_mail.expires', ['minutes' => $minutes]) }}</p>
<p>{{ __('account.reset_mail.ignore') }}</p>
<p>AIQME</p>
</body>
</html>
