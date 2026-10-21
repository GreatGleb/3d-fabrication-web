<?php

namespace App\Http\Controllers;

use App\Domain\Orders\OrderAccess;
use App\Models\StlFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Защищённая выдача STL заказа (§6.4, W-32): права — OrderAccess на каждый запрос (GET, HEAD, Range); файл без заказа,
 * чужой или несуществующий — 404. Только attachment с экранированным именем, nosniff, private, no-store; подписанных
 * публичных ссылок нет. Range и HEAD обслуживает BinaryFileResponse.
 */
class StlDownloadController extends Controller
{
    public function __invoke(Request $request, StlFile $stlFile, OrderAccess $access): BinaryFileResponse
    {
        $order = $stlFile->order;
        abort_unless($order !== null && $access->allows($order, $request->user()), 404);
        abort_unless(Storage::disk('local')->exists($stlFile->path), 404);

        $response = new BinaryFileResponse(Storage::disk('local')->path($stlFile->path), 200, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ], false);
        // Имя от клиента: косая черта недопустима в заголовке, ASCII-замена — для старых клиентов (filename=), UTF-8 — filename*.
        $name = str_replace(['/', '\\'], '_', $stlFile->original_name);
        $response->setContentDisposition('attachment', $name, preg_replace('/[^\x20-\x7e]|%/', '_', Str::ascii($name)));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
