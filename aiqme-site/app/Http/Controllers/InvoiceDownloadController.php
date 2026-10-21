<?php

namespace App\Http\Controllers;

use App\Domain\Invoices\Models\Invoice;
use App\Domain\Orders\OrderAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Скачивание PDF счёта (§3.5 «Доступ», §6.1, W-17): права — OrderAccess на каждый запрос (владелец, администратор, гость
 * с правом на заказ в сессии; GET и HEAD); чужой или несуществующий счёт — 404. Токена в адресе нет; attachment, nosniff,
 * private, no-store.
 */
class InvoiceDownloadController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, OrderAccess $access): BinaryFileResponse
    {
        abort_unless($access->allows($invoice->order, $request->user()), 404);
        abort_unless(Storage::disk('local')->exists($invoice->pdf_path), 404);

        $response = new BinaryFileResponse(Storage::disk('local')->path($invoice->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ], false);
        $response->setContentDisposition('attachment', $invoice->label().'.pdf');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
