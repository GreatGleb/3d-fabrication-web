<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Тестовых пользователей нет: администратора создаёт aiqme:make-admin (D-29),
     * сидеры контента запускаются по имени класса (db:seed --class=…).
     */
    public function run(): void
    {
        //
    }
}
