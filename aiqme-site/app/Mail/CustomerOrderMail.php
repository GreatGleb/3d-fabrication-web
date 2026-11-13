<?php

namespace App\Mail;

use App\Domain\Invoices\SellerSettings;
use App\Domain\Orders\Models\OrderMeta;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Lunar\Models\Order;

/**
 * Общее письмам клиенту о заказе (правка 12.11, K-01/K-02): язык заказа, Reply-To — aiqme.forms.mail_to, данные общего
 * макета (resources/views/mail/layout*.blade.php): приветствие, кнопка кабинета (только клиенту кабинета — у гостя токен
 * хранится хешем), контакты внизу из реквизитов продавца, пустые — значения шаблона заказчика.
 */
abstract class CustomerOrderMail extends Mailable
{
    public OrderMeta $meta;

    public function __construct(public Order $order)
    {
        $this->meta = OrderMeta::where('order_id', $order->id)->firstOrFail();
        $this->locale($this->meta->locale);
    }

    /** Ключ темы в orders.customer_mail. */
    abstract protected function subjectKey(): string;

    public function envelope(): Envelope
    {
        return new Envelope(replyTo: [config('aiqme.forms.mail_to')], subject: $this->subjectText());
    }

    protected function subjectText(): string
    {
        return __('orders.customer_mail.'.$this->subjectKey(), ['number' => $this->meta->number], $this->meta->locale);
    }

    /**
     * Данные общего макета.
     *
     * @return array<string, mixed>
     */
    protected function common(): array
    {
        $locale = $this->meta->locale;
        $values = app(SellerSettings::class)->values() ?? [];

        return [
            'locale' => $locale,
            'subject' => $this->subjectText(),
            'name' => $this->meta->contact['name'] ?? '',
            'number' => $this->meta->number,
            'url' => $this->order->user_id === null ? null : route($locale.'.account.orders.show', $this->meta->number),
            'email' => ($values['email'] ?? null) ?: config('aiqme.forms.mail_to'),
            'phone' => ($values['phone'] ?? null) ?: config('aiqme.customer_mail.phone'),
            'website' => ($values['website'] ?? null) ?: config('aiqme.customer_mail.website'),
            'address' => ($values['address'] ?? null) ?: config('aiqme.customer_mail.address'),
            'logo' => asset('defaults/logo-white.png'),
        ];
    }
}
