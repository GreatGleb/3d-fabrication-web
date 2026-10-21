<?php

namespace App\Mail;

use App\Domain\Integrations\Models\IntegrationEvent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо AIQME о новой заявке формы (§5.2, W-09): тип, имя, контакты, услуга или обучение, краткое описание, число
 * вложений, ссылка в админку — из данных события outbox. Вложений и полного текста нет (они — в админке); на языке
 * LT. Reply-To — проверенный email клиента (без имени: ввод с переводами строк в заголовки не попадает). Очередь —
 * задание канала (DeliverMail), само письмо отправляется синхронно внутри него.
 */
class FormSubmitted extends Mailable
{
    public function __construct(public IntegrationEvent $event)
    {
        $this->locale('lt');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [$this->event->data['email']],
            subject: __('admin.requests.'.$this->event->data['kind'].'_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.form-submitted', with: ['data' => $this->event->data]);
    }
}
