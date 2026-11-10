<?php

namespace App\Domain\Invoices;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Invoices\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF счёта (§3.5): Blade-шаблон invoices.pdf на языке счёта, dompdf (из зависимостей Lunar) со шрифтом DejaVu Sans (с I-04 — запасной) из
 * dompdf/lib/fonts (литовские символы); внешние ресурсы, PHP и JavaScript в шаблоне отключены. Шаблон — код, не контент.
 * Логотип (правка 02.11, Г5) — текущий логотип сайта (свой logo главной, иначе defaults/logo.png, D-43), встроенный
 * data-URI; дата заказа — placed_at. Доработка 09.11, I-04: шрифт образца (Arial Narrow) заменён свободным метрически
 * совместимым Liberation Sans Narrow из resources/fonts; dompdf регистрирует его в кэше storage/framework/cache/dompdf-fonts
 * (vendor не пишется), DejaVu Sans остаётся запасным.
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
            'chroot' => [resource_path('fonts')],
            'fontDir' => $cache = storage_path('framework/cache/dompdf-fonts'),
            'fontCache' => $cache,
        ]));
        if (! is_dir($cache)) {
            mkdir($cache, 0775, true);
        }
        foreach (['normal' => 'Regular', 'bold' => 'Bold'] as $weight => $file) {
            $dompdf->getFontMetrics()->registerFont(
                ['family' => 'Liberation Sans Narrow', 'weight' => $weight, 'style' => 'normal'],
                resource_path('fonts/LiberationSansNarrow-'.$file.'.ttf'),
            );
        }
        $dompdf->loadHtml(view('invoices.pdf', [
            'invoice' => $invoice->load('lines'),
            'locale' => $invoice->locale,
            'orderDate' => $invoice->order->placed_at,
            'logo' => self::logo(),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** Логотип сайта как data-URI: свой файл из админки важнее файла по умолчанию. */
    public static function logo(): string
    {
        $own = ContentItem::where('type', ContentType::PageText)->where('key', 'home')->first()?->getFirstMedia('logo');
        [$path, $mime] = $own !== null && is_file($own->getPath())
            ? [$own->getPath(), $own->mime_type]
            : [public_path(ContentItem::mediaDefault(ContentType::PageText, 'home', 'logo')), 'image/png'];

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
