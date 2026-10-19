<?php

namespace Database\Seeders;

use App\Domain\Content\ContentService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Исходный контент из прототипа (D-11, D-19, D-21; W-04). Читает только отслеживаемый
 * database/seeders/data/prototype-content.json — работает на чистом клоне и в образе без docs/customer-prototype/.
 * Повторный запуск создаёт только недостающие элементы и ничего не перезаписывает.
 * Запуск: bin/php artisan db:seed --class='Database\Seeders\PrototypeContentSeeder'.
 */
class PrototypeContentSeeder extends Seeder
{
    public const DATA = __DIR__.'/data/prototype-content.json';

    public function run(ContentService $content): void
    {
        $data = json_decode((string) file_get_contents(self::DATA), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data['items'] ?? null)) {
            throw new RuntimeException('prototype-content.json: нет списка items.');
        }

        $created = 0;
        foreach ($data['items'] as $item) {
            $created += (int) $content->import($item);
        }

        $this->command?->info(sprintf('Контент прототипа: создано %d, уже было %d.', $created, count($data['items']) - $created));
    }
}
