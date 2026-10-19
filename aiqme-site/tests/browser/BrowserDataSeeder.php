<?php

namespace Tests\browser;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Данные bin/browser-check в БД aiqme_browser. Запуск по имени класса:
 * bin/php artisan db:seed --class='Tests\browser\BrowserDataSeeder' (пароль клиента — в BROWSER_CLIENT_PASSWORD,
 * его создаёт bin/browser-check и не пишет в файлы и журналы). Данные следующих задач добавляются сюда из фабрик tests/browser/.
 * W-05: 2 бренда и 2 новости; W-06: проект LT+EN (услуги modelling и scanning) и обучение; медиа hero — из файлов BROWSER_HERO_VIDEO / _MOBILE / _POSTER, которые bin/browser-check
 * создаёт ffmpeg во временном каталоге (без ffmpeg переменных нет — hero без видео).
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

        $content = ContentFactory::as(User::where('is_admin', true)->firstOrFail());
        $content->homeTickers();
        $content->publicSections();
        if (getenv('BROWSER_HERO_VIDEO')) {
            $content->heroMedia((string) getenv('BROWSER_HERO_VIDEO'), (string) getenv('BROWSER_HERO_VIDEO_MOBILE'),
                (string) getenv('BROWSER_HERO_POSTER'), ['text' => 'AIQME']);
            $this->command?->info('Медиа hero: видео, мобильное видео, постер.');
        }
    }
}
