<?php

namespace App\Mail;

use App\Domain\Invoices\InvoiceService;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Invoices\SellerSettings;
use App\Domain\Orders\ProductCart;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Lunar\Models\Order;

/**
 * Письмо клиенту после первой оплаты заказа (правка 12.11, K-01; docs/planning/edits-20261112/README.md, решения 1–10, 12,
 * 13; общий макет и данные — CustomerOrderMail, K-02): шаблон 1 (заказ товаров) или 2 (заказ калькулятора) на языке заказа. Данные читаются из БД в момент отправки:
 * позиции — строками счёта (цена без VAT), итоги — как в счёте («Iš viso», «PVM», «Iš viso su PVM» = цена заказа);
 * выпущенный счёт — его снимок и PDF во вложении. Кнопка кабинета — только заказу клиента кабинета (у гостя токен
 * хранится хешем). Reply-To — aiqme.forms.mail_to. Доставка — outbox (IntegrationEvents::deliverMail).
 */
class OrderPaid extends CustomerOrderMail
{
    /** Тип события outbox письма. */
    public const EVENT = 'order.paid_customer_mail';

    public ?Invoice $invoice;

    public function __construct(Order $order)
    {
        parent::__construct($order);
        $this->invoice = Invoice::where('order_id', $order->id)->first();
    }

    protected function subjectKey(): string
    {
        return 'subject_paid';
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-paid', text: 'mail.order-paid-text', with: ['mail' => $this->data()]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return $this->invoice === null ? [] : [
            Attachment::fromStorageDisk('local', $this->invoice->pdf_path)->as($this->invoice->label().'.pdf')->withMime('application/pdf'),
        ];
    }

    /**
     * Данные письма. vat — режим итогов: vat (плательщик VAT: «Iš viso», «PVM», «Iš viso su PVM»), none (не плательщик:
     * только «Iš viso»), unknown (реквизиты не заданы: только «Iš viso su PVM» = цена заказа).
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $locale = $this->meta->locale;
        $settings = app(SellerSettings::class);
        $seller = $this->invoice?->seller ?? ($settings->complete() ? $settings->values() : null);
        $vat = match (true) {
            $seller === null => 'unknown',
            (bool) $seller['vat_payer'] => 'vat',
            default => 'none',
        };
        $rate = $vat === 'vat' ? (int) $seller['vat_rate'] : 0;
        $lines = $this->invoice !== null
            ? $this->invoice->lines()->orderBy('id')->get()->map(fn ($line): array => $line->only(['description', 'quantity', 'net', 'vat', 'gross']))
            : InvoiceService::lines($this->order, $locale, $rate);
        $money = fn (int $cents): string => number_format($cents / 100, 2, '.', '').' €';
        $contact = $this->meta->contact;
        $values = $settings->values() ?? [];
        $address = $this->meta->address;

        return [
            ...$this->common(),
            'title' => __('orders.customer_mail.'.($this->meta->products ? 'title_products' : 'title_calculator'), [], $locale),
            'date' => ($this->order->placed_at ?? $this->order->created_at)->timezone(config('app.timezone'))->format('d.m.Y'),
            'lines' => $lines->map(fn (array $line): array => [
                'description' => $line['description'],
                'quantity' => __('orders.customer_mail.units', ['quantity' => number_format($line['quantity'], 2, '.', '')], $locale),
                'price' => $money((int) round($line['net'] / max(1, $line['quantity']))),
                'amount' => $money($line['net']),
            ])->all(),
            'vat' => $vat,
            'rate' => $rate,
            'net' => $money($lines->sum('net')),
            'vat_total' => $money($lines->sum('vat')),
            'total' => $money($lines->sum('gross')),
            'delivery_method' => __('calculator.checkout.deliveries.'.$this->meta->delivery, [], $locale),
            'delivery_place' => $this->meta->delivery === 'ship' && $address !== null
                ? implode(', ', array_filter([$address['address'] ?? null, $address['city'] ?? null, $address['postcode'] ?? null,
                    isset($address['country']) ? __('calculator.countries.'.$address['country'], [], $locale) : null]))
                : (($values['address'] ?? null) ?: config('aiqme.customer_mail.address')),
            'delivery_time' => ProductCart::deliveryTime($locale),
            'buyer' => array_values(array_filter([$contact['name'] ?? null, $contact['company'] ?? null])),
            'buyer_contacts' => implode('  |  ', array_filter([$contact['email'] ?? null, $contact['phone'] ?? null])),
        ];
    }
}
