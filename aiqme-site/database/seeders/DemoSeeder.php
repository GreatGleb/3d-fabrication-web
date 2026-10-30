<?php

namespace Database\Seeders;

use App\Domain\Orders\OrderSubmission;
use App\Domain\Orders\OrderWorkflow;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lunar\Models\Order;
use RuntimeException;

/**
 * Демо-данные сборки GitHub Pages (W-24, §8.7) — только в отдельной БД aiqme_demo, которую создаёт и удаляет
 * aiqme:demo-export. Администратор и клиент на домене aiqme.test со случайными паролями (пароли нигде не выводятся),
 * два заказа на кубе 10 мм из tests/fixtures/stl/: заказ клиента с подтверждённой ценой и сообщением администратора и
 * новая гостевая заявка. Платёжных запросов и платежей нет (разделов оплаты в демо нет). Контент — PrototypeContentSeeder.
 */
class DemoSeeder extends Seeder
{
    public const DATABASE = 'aiqme_demo';

    public const ADMIN_EMAIL = 'admin@aiqme.test';

    public const CLIENT_EMAIL = 'klientas@aiqme.test';

    public function run(OrderSubmission $orders, OrderWorkflow $flow): void
    {
        if (DB::scalar('select current_database()') !== self::DATABASE) {
            throw new RuntimeException('DemoSeeder пишет только в БД '.self::DATABASE.'.');
        }

        $admin = (new User)->forceFill(['name' => 'AIQME', 'email' => self::ADMIN_EMAIL, 'password' => Str::random(32), 'is_admin' => true]);
        $admin->save();
        $client = User::create(['name' => 'Jonas Petraitis', 'email' => self::CLIENT_EMAIL, 'password' => Str::random(32)]);

        $order = $this->submit($orders, $client, 'lt', ['name' => $client->name, 'email' => $client->email, 'phone' => '+370 600 00002']);
        [$part, $fee] = $order->lines()->orderBy('id')->get()->modelKeys();
        $flow->confirmPrice($admin, $order, [$part => 3000, $fee => 500], null);
        $flow->postAdminMessage($admin, $order, 'Sienelė per plona: siūlome 1,2 mm.');

        $this->submit($orders, null, 'en', ['name' => 'Rūta Jankauskienė', 'email' => 'ruta@aiqme.test', 'phone' => '+370 600 00001']);
    }

    /** @param  array{name: string, email: string, phone: string}  $contact */
    private function submit(OrderSubmission $orders, ?User $user, string $locale, array $contact): Order
    {
        return $orders->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => $locale,
            'contact' => [...$contact, 'company' => null, 'company_code' => null, 'vat_code' => null],
            'delivery' => 'pickup',
            'address' => null,
            'material' => 'PLA',
            'infill' => 100,
            'preferences' => ['color' => 'black', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'term' => 'standard'],
            'parts' => [[
                'file' => UploadedFile::fake()->createWithContent('cube-10mm.stl', (string) file_get_contents(base_path('tests/fixtures/stl/cube-10mm-binary.stl'))),
                'unit' => 'mm',
                'scale' => 100,
                'quantity' => 1,
            ]],
        ], $user)->order;
    }
}
