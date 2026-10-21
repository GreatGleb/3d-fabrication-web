<?php

namespace App\Domain\Invoices;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Задание «ожидающие счета» (§3.5, §8.4, W-17): после сохранения реквизитов и раз в сутки ставит IssueInvoice каждому
 * заказу, которому может причитаться счёт (сбой одного заказа не задерживает остальные).
 */
class IssuePendingInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(InvoiceService $invoices): void
    {
        foreach ($invoices->pendingOrderIds() as $orderId) {
            IssueInvoice::dispatch($orderId);
        }
    }
}
