<?php

namespace Tests\browser;

use App\Domain\Orders\OrderSubmission;
use App\Domain\Orders\OrderWorkflow;
use App\Models\OrderCharge;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;

/**
 * Заказ для bin/browser-check (W-14): гостевая заявка W-12 (два куба 10 мм из tests/fixtures/stl/, PLA, доставка) через
 * OrderSubmission и действия администратора через OrderWorkflow — цена 60 + 55 + 5 EUR и доставка 30 EUR подтверждена,
 * открыт запрос оплаты 50 EUR, введены перевозчик и трек. Контакты — без слов-маркеров D-21. STL пишутся на диск local
 * (игнорируемый storage/).
 */
final class OrderFactory
{
    public static function confirmedWithRequestAndTracking(User $admin): Order
    {
        $cube = file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));
        $part = fn (string $name, int $quantity): array => [
            'file' => UploadedFile::fake()->createWithContent($name, $cube),
            'unit' => 'mm',
            'scale' => 100,
            'quantity' => $quantity,
        ];
        $order = app(OrderSubmission::class)->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => 'en',
            'contact' => ['name' => 'Rūta Jankauskienė', 'email' => 'ruta@aiqme.test', 'phone' => '+370 600 00001', 'company' => null, 'company_code' => null, 'vat_code' => null],
            'delivery' => 'ship',
            'address' => ['country' => 'LT', 'city' => 'Kaunas', 'address' => 'Laisvės al. 1', 'postcode' => '44001'],
            'material' => 'PLA',
            'infill' => 100,
            'preferences' => ['color' => 'black', 'quality' => 'standard', 'layer' => '0.2', 'finish' => 'none', 'term' => 'standard'],
            'parts' => [$part('bracket.stl', 1), $part('cover.stl', 2)],
        ], null)->order;

        $ids = $order->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
            ->modelKeys();
        $flow = app(OrderWorkflow::class);
        $flow->confirmPrice($admin, $order, array_combine($ids, [6000, 5500, 500]), 3000);
        $flow->requestPayment($admin, $order, 5000);
        $flow->setTracking($admin, $order, 'DPD', 'LT000000001');

        return $order;
    }
}
