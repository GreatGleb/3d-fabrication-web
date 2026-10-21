<?php

namespace App\Domain\Invoices;

use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderNumber;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Models\CustomerProfile;
use App\Models\OrderCharge;
use App\Models\StlPart;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use RuntimeException;

/**
 * Выпуск счетов (§3.5, W-17). issueIfDue(order) — единая идемпотентная проверка момента выпуска по D-04 (по умолчанию —
 * итоговый счёт при полной оплате): счёта ещё нет, цена подтверждена, оплачено (capture − refund) ≥ подтверждённой цены,
 * заказ не отменён, реквизиты продавца, VAT и нумерация заданы. Под блокировкой строки заказа в одной транзакции: номер
 * (атомарный UPDATE … RETURNING счётчика серии), снимок реквизитов, строки с VAT, выделенным из брутто, PDF и событие
 * §5.1 «Счёт выпущен». Исключение рендера откатывает всё (номер не расходуется), повтор задания выпускает счёт заново.
 * Итог счёта = подтверждённая цена (D-22), излишек переплаты остаётся на сверке. Вызывают только задания IssueInvoice и
 * IssuePendingInvoices.
 */
final class InvoiceService
{
    public function __construct(
        private readonly SellerSettings $settings,
        private readonly InvoicePdf $pdf,
        private readonly OrderWorkflow $workflow,
        private readonly IntegrationEvents $events,
    ) {}

    public function issueIfDue(Order $order): ?Invoice
    {
        return DB::transaction(function () use ($order): ?Invoice {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $state = ProductionState::where('order_id', $order->id)->first();
            $balance = $this->workflow->balance($order);
            if (Invoice::where('order_id', $order->id)->exists()
                || $state?->confirmed_at === null || $state->stage === ProductionStage::Cancelled
                || $balance['paid'] < $balance['total'] || ! $this->settings->complete()) {
                return null;
            }

            $seller = $this->settings->values();
            $meta = OrderMeta::where('order_id', $order->id)->firstOrFail();
            $rate = $seller['vat_payer'] ? $seller['vat_rate'] : 0;
            $lines = $this->lines($order, $meta->locale, $rate);
            $number = OrderNumber::next('invoices:'.$seller['series'], $seller['first_number']);
            $invoice = Invoice::create([
                'order_id' => $order->id,
                'series' => $seller['series'],
                'number' => $number,
                'kind' => Invoice::FINAL,
                'locale' => $meta->locale,
                'seller' => $seller,
                'buyer' => $this->buyer($order, $meta),
                'net_total' => $lines->sum('net'),
                'vat_total' => $lines->sum('vat'),
                'total' => $lines->sum('gross'),
                'pdf_path' => 'invoices/'.$seller['series'].'-'.$number.'.pdf',
                'issued_at' => now(),
            ]);
            $invoice->lines()->createMany($lines->all());
            $pdf = $this->pdf->render($invoice);
            $this->events->record('invoice.issued', $invoice, [
                'number' => $meta->number,
                'summary' => 'Invoice issued: '.$invoice->label(),
                'amount' => number_format($invoice->total / 100, 2, '.', ''),
                'invoice_number' => $invoice->label(),
            ], mail: false);
            // Файл — последним: сбой до него откатывает всё без файла; путь с тем же номером повтор перезапишет.
            if (! Storage::disk('local')->put($invoice->pdf_path, $pdf)) {
                throw new RuntimeException('Invoice PDF was not written: '.$invoice->pdf_path);
            }

            return $invoice;
        });
    }

    /**
     * Заказы, которым может причитаться счёт: цена подтверждена, не отменён, есть оплата, счёта нет. Остальные условия
     * проверяет issueIfDue.
     *
     * @return Collection<int, int>
     */
    public function pendingOrderIds(): Collection
    {
        return ProductionState::whereNotNull('confirmed_at')
            ->where('stage', '!=', ProductionStage::Cancelled->value)
            ->whereNotIn('order_id', Invoice::select('order_id'))
            ->whereIn('order_id', DB::table('lunar_transactions')->where('type', 'capture')->select('order_id'))
            ->orderBy('order_id')
            ->pluck('order_id');
    }

    /**
     * VAT строки в центах = floor(брутто × ставка / (100 + ставка) + 0,5), нетто = брутто − VAT (§3.5, целочисленно).
     */
    public static function vat(int $gross, int $rate): int
    {
        return intdiv(2 * $gross * $rate + 100 + $rate, 2 * (100 + $rate));
    }

    /** @return Collection<int, array{description: string, quantity: int, net: int, vat: int, gross: int}> */
    private function lines(Order $order, string $locale, int $rate): Collection
    {
        return $order->lines()->with('purchasable')->orderBy('id')->get()->map(function (OrderLine $line) use ($locale, $rate): array {
            $part = $line->purchasable instanceof StlPart ? $line->purchasable : null;
            $gross = $line->total->value;
            $vat = self::vat($gross, $rate);

            return [
                'description' => match (true) {
                    $part !== null => $part->file->original_name,
                    $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY => __('admin.orders.delivery', [], $locale),
                    default => __('calculator.js.order_fee', [], $locale),
                },
                'quantity' => $part !== null ? (int) $part->parameters['quantity'] : 1,
                'net' => $gross - $vat,
                'vat' => $vat,
                'gross' => $gross,
            ];
        });
    }

    /**
     * Покупатель (§3.5, D-23): контакты заявки; адрес — из заявки или профиля клиента, если есть, иначе без адреса.
     *
     * @return array{name: ?string, company: ?string, company_code: ?string, vat_code: ?string, address: ?string}
     */
    private function buyer(Order $order, OrderMeta $meta): array
    {
        $contact = $meta->contact;
        $join = fn (array $parts): ?string => implode(', ', array_filter(array_map(fn ($part): string => trim((string) $part), $parts))) ?: null;
        $address = $meta->address === null ? null : $join([$meta->address['address'] ?? null, $meta->address['city'] ?? null, $meta->address['postcode'] ?? null, $meta->address['country'] ?? null]);
        if ($address === null && $order->user_id !== null) {
            $profile = CustomerProfile::where('user_id', $order->user_id)->first();
            $address = $profile === null ? null : $join([$profile->address, $profile->city, $profile->postcode]);
        }

        return [
            'name' => $contact['name'] ?? null,
            'company' => $contact['company'] ?? null,
            'company_code' => $contact['company_code'] ?? null,
            'vat_code' => $contact['vat_code'] ?? null,
            'address' => $address,
        ];
    }
}
