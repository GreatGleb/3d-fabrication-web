<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо со ссылкой сброса пароля (W-07, F-11): через очередь, на языке страницы запроса (LT/EN).
 * Ссылка одноразовая, срок — auth.passwords.users.expire (токен хранит брокер паролей Laravel).
 */
class ResetPasswordLink extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $email, public string $token, string $locale)
    {
        $this->locale($locale);
    }

    public function url(): string
    {
        return route($this->locale.'.account.reset.token', ['token' => $this->token, 'email' => $this->email]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account.reset_mail.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'account.reset-mail',
            with: ['url' => $this->url(), 'minutes' => config('auth.passwords.users.expire')],
        );
    }
}
