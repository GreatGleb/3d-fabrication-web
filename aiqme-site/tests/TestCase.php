<?php

namespace Tests;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLibrary;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Domain\Forms\Altcha;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Тесты не ходят во внешние сервисы (rules, D-20): запрос без Http::fake падает.
        Http::preventStrayRequests();

        // Страницы рендерятся без собранных ресурсов (bin/check собирает Vite после PHPUnit).
        $this->withoutVite();

        // Капча ALTCHA (П3): фиктивный ключ и лёгкие задачи — решение в тестах занимает миллисекунды.
        config([
            'aiqme.forms.altcha.hmac_key' => 'phpunit-only-not-a-secret',
            'aiqme.forms.altcha.cost' => 1,
            'aiqme.forms.altcha.counter' => [1, 20],
        ]);
    }

    /**
     * Решение задачи ALTCHA, как его отправляет виджет (base64 JSON), — средствами пакета, без HTTP.
     */
    protected function altcha(): string
    {
        $challenge = app(Altcha::class)->challenge();
        $solution = (new AltchaLibrary)->solveChallenge(new SolveChallengeOptions(algorithm: new Pbkdf2, challenge: $challenge));

        return (new Payload($challenge, $solution))->toBase64();
    }
}
