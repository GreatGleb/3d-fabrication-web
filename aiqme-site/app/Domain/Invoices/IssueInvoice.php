<?php

namespace App\Domain\Invoices;

use App\Domain\Integrations\DeliverMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Lunar\Models\Order;

/**
 * Задание выпуска счёта заказа (§3.5, W-17): InvoiceService::issueIfDue. Ставится после commit записи capture и refund
 * (PaymentRecorder), изменения цены (OrderWorkflow) и заданием «ожидающие счета». Исключение (например, рендера PDF) не
 * глотается: транзакция выпуска откатывается, задание повторяется, неудача видна в Overview (failed_jobs).
 */
class IssueInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $backoff = 60;

    /** @param  ?int  $mailEventId  письмо клиенту после первой оплаты (правка 12.11, K-01): ставится после выпуска счёта */
    public function __construct(public int $orderId, public ?int $mailEventId = null) {}

    public function handle(InvoiceService $invoices): void
    {
        $invoices->issueIfDue(Order::findOrFail($this->orderId));
        if ($this->mailEventId !== null) {
            DeliverMail::dispatch($this->mailEventId);
        }
    }
}
