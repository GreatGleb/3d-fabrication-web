<?php

namespace Tests\browser;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Данные bin/browser-check в БД aiqme_browser. Запуск по имени класса:
 * bin/php artisan db:seed --class='Tests\browser\BrowserDataSeeder' (пароль клиента — в BROWSER_CLIENT_PASSWORD,
 * его создаёт bin/browser-check и не пишет в файлы и журналы). Данные следующих задач добавляются сюда из фабрик tests/browser/.
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
    }
}
