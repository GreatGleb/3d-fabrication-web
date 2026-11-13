<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Lunar\Models\Order;

/**
 * Письмо клиенту с трек-номером, когда администратор сохраняет перевозчика и номер посылки (правка 12.11, K-02;
 * docs/planning/edits-20261112/README.md, решения 11–13): «Jūsų užsakymas išsiųstas!» в общем макете писем о заказе.
 * Перевозчик и номер — из события outbox (то, что сохранено в этот раз). Доставка — outbox (IntegrationEvents::deliverMail).
 */
class OrderShipped extends CustomerOrderMail
{
    /** Тип события outbox письма. */
    public const EVENT = 'order.shipped_customer_mail';

    public function __construct(Order $order, public string $carrier, public string $trackingNumber)
    {
        parent::__construct($order);
    }

    protected function subjectKey(): string
    {
        return 'subject_shipped';
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-shipped', text: 'mail.order-shipped-text', with: ['mail' => $this->data()]);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return [
            ...$this->common(),
            'title' => __('orders.customer_mail.title_shipped', [], $this->meta->locale),
            'carrier' => $this->carrier,
            'tracking_number' => $this->trackingNumber,
        ];
    }
}
