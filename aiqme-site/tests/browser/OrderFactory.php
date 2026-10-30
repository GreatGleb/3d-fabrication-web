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
use Lunar\Models\Transaction;

/**
 * Заказ для bin/browser-check (W-14): гостевая заявка W-12 (два куба 10 мм из tests/fixtures/stl/, PLA, доставка) через
 * OrderSubmission и действия администратора через OrderWorkflow — цена 60 + 55 + 5 EUR и доставка 30 EUR подтверждена,
 * открыт запрос оплаты 50 EUR, введены перевозчик и трек; notes клиента — две строки (E-05). Контакты и notes — без
 * слов-маркеров D-21. STL пишутся на диск local
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
            'preferences' => ['color' => 'black', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'term' => 'standard'],
            'notes' => "Pagaminti iki penktadienio.\nDetales supakuoti atskirai.",
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

    /**
     * Заказ клиента для кабинета (W-15): куб 10 мм binary × 1, PLA, 100 %, самовывоз; цена подтверждена (деталь 30 EUR,
     * плата 5 EUR), получен аванс 10 EUR (тестовая транзакция capture), сообщение администратора ждёт ответа клиента.
     */
    public static function clientOrderWithMessage(User $client, User $admin): Order
    {
        $order = app(OrderSubmission::class)->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => 'lt',
            'contact' => ['name' => $client->name, 'email' => $client->email, 'phone' => '+370 600 00002', 'company' => null, 'company_code' => null, 'vat_code' => null],
            'delivery' => 'pickup',
            'address' => null,
            'material' => 'PLA',
            'infill' => 100,
            'preferences' => ['color' => 'black', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'term' => 'standard'],
            'parts' => [[
                'file' => UploadedFile::fake()->createWithContent('cube-10mm-binary.stl', file_get_contents(base_path('tests/fixtures/stl/cube-10mm-binary.stl'))),
                'unit' => 'mm',
                'scale' => 100,
                'quantity' => 1,
            ]],
        ], $client)->order;

        [$part, $fee] = $order->lines()->orderBy('id')->get()->modelKeys();
        $flow = app(OrderWorkflow::class);
        $flow->confirmPrice($admin, $order, [$part => 3000, $fee => 500], null);
        Transaction::create(['order_id' => $order->id, 'success' => true, 'type' => 'capture', 'driver' => 'browser-check', 'amount' => 1000,
            'reference' => 'browser-check-charge-not-real', 'status' => 'settled', 'captured_at' => now(), 'meta' => []]);
        $flow->postAdminMessage($admin, $order, 'Sienelė per plona: siūlome 1,2 mm.');

        return $order;
    }
}
