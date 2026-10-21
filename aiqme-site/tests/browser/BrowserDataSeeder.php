<?php

namespace Tests\browser;

use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\OrderWorkflow;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Данные bin/browser-check в БД aiqme_browser. Запуск по имени класса:
 * bin/php artisan db:seed --class='Tests\browser\BrowserDataSeeder' (пароль клиента — в BROWSER_CLIENT_PASSWORD,
 * его создаёт bin/browser-check и не пишет в файлы и журналы). Данные следующих задач добавляются сюда из фабрик tests/browser/.
 * W-05: 2 бренда и 2 новости; W-06: проект LT+EN (услуги modelling и scanning) и обучение; W-25: 4 товара с брендом и категорией;
 * W-33: опубликованный черновик проекта prototype; W-14: гостевой заказ с подтверждённой ценой, открытым запросом и треком; W-15: его гостевая ссылка и заказ клиента с авансом и сообщением. Медиа не загружаются: места прототипа показывают файлы по умолчанию D-43.
 */
class BrowserDataSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) getenv('BROWSER_CLIENT_PASSWORD');
        if (strlen($password) < 12) {
            throw new RuntimeException('BROWSER_CLIENT_PASSWORD не задан: сидер запускает bin/browser-check.');
        }

        $client = ClientFactory::new()->create(['password' => $password]);
        $this->command?->info('Клиент: '.$client->email);

        $admin = User::where('is_admin', true)->firstOrFail();
        $content = ContentFactory::as($admin);
        $content->homeTickers();
        $content->publicSections();
        $content->catalog();
        $guest = OrderFactory::confirmedWithRequestAndTracking($admin);
        // W-15: гостевая ссылка заказа выше (открытый токен выдаётся один раз — перевыпуск) и заказ клиента для кабинета.
        $meta = OrderMeta::where('order_id', $guest->id)->sole();
        $this->command?->info('Гостевая ссылка: /en/order/'.$meta->number.'/'.app(OrderWorkflow::class)->reissueGuestLink($admin, $guest));
        OrderFactory::clientOrderWithMessage($client, $admin);
    }
}
