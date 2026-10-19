<?php

namespace Tests;

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
    }
}
