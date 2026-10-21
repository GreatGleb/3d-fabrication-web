<?php

namespace App\Domain\Invoices;

use App\Domain\Invoices\Models\Invoice;
use App\Domain\Orders\Models\OrderMeta;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF счёта (§3.5): Blade-шаблон invoices.pdf на языке счёта, dompdf (из зависимостей Lunar) со шрифтом DejaVu Sans из
 * dompdf/lib/fonts (литовские символы); внешние ресурсы, PHP и JavaScript в шаблоне отключены. Шаблон — код, не контент.
 */
final class InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        $dompdf = new Dompdf(new Options([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ]));
        $dompdf->loadHtml(view('invoices.pdf', [
            'invoice' => $invoice->load('lines'),
            'locale' => $invoice->locale,
            'orderNumber' => OrderMeta::where('order_id', $invoice->order_id)->value('number'),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
