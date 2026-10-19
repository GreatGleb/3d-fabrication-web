<?php

namespace Tests\browser;

use App\Models\User;
use Database\Factories\UserFactory;

/**
 * Клиент для bin/browser-check (W-03): e-mail на домене aiqme.test, имя без слов-маркеров D-21.
 * Пароль задаёт BrowserDataSeeder (случайный на каждый запуск, D-29).
 */
class ClientFactory extends UserFactory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            ...parent::definition(),
            'name' => 'Jonas Petraitis',
            'email' => 'client-'.fake()->unique()->numerify('####').'@aiqme.test',
        ];
    }
}
