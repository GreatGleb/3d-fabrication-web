<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PageHead;
use Tests\TestCase;

/**
 * Адреса прототипа против таблицы §4.6 (04-content-seo.md): статические страницы, 301 и строки с 404 (W-02); адреса
 * услуг со slug из БД после сидера W-04 — 200 на своей локали, 404 со slug другой локали (W-05).
 * Читает только отслеживаемый tests/fixtures/prototype-routes.json (без docs/customer-prototype/).
 */
class PrototypeRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../fixtures/prototype-routes.json';

    /** Строки §4.6, которые проверяют другие задачи (адреса сущностей, кабинет, админка). */
    private const LATER_ROWS = [
        6 => 'W-06: демо-проекты — черновики сидера W-04',
        10 => 'W-07/W-15: кабинет',
        11 => 'W-07: вход, регистрация, восстановление',
        13 => 'W-08, W-26, W-11, W-14, W-17, W-18: вкладки админки',
    ];

    /** @return array{rows: array<int, string>, routes: list<array{path: string, row: int}>} */
    private static function fixture(): array
    {
        return json_decode(file_get_contents(self::FIXTURE), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string, int}> */
    public static function prototypeRoutes(): iterable
    {
        foreach (self::fixture()['routes'] as $route) {
            yield $route['path'] => [$route['path'], $route['row']];
        }
    }

    public function test_fixture_has_every_manifest_address_once_with_a_row_and_nothing_else(): void
    {
        $fixture = self::fixture();
        $paths = array_column($fixture['routes'], 'path');

        $this->assertCount(184, $paths);
        $this->assertSame($paths, array_values(array_unique($paths)));
        $this->assertSame(range(1, 14), array_keys($fixture['rows']));
        foreach ($fixture['routes'] as $route) {
            $this->assertSame(['path', 'row'], array_keys($route), $route['path']);
            $this->assertArrayHasKey($route['row'], $fixture['rows'], $route['path']);
        }
    }

    #[DataProvider('prototypeRoutes')]
    public function test_prototype_address_answers_as_its_section_4_6_row(string $path, int $row): void
    {
        if (isset(self::LATER_ROWS[$row])) {
            $this->markTestSkipped('строка '.$row.' §4.6 — '.self::LATER_ROWS[$row]);
        }

        match ($row) {
            1 => $this->get($path)->assertStatus(301)->assertRedirect(route('lt.home')),
            2 => $this->assertPublicPage($path),
            // Slug другой локали — 404 при опубликованных услугах (без сидера 404 ничего бы не доказывал).
            3 => $this->seed(PrototypeContentSeeder::class)->get($path)->assertNotFound(),
            4, 5, 7, 8, 9, 12 => $this->get($path)->assertNotFound(),
            14 => $this->assertNotFoundForGuestAndAdmin($path),
            default => $this->fail('строка '.$row.' §4.6 без проверки: '.$path),
        };
    }

    private function assertPublicPage(string $path): void
    {
        $plainPath = strtok($path, '?');
        $segments = array_values(array_filter(explode('/', $plainPath)));
        if (count($segments) > 2 && ! in_array($segments[1], ['paslaugos', 'services'], true)) {
            $this->markTestSkipped('строка 2 §4.6, адрес статьи — W-06');
        }
        if (count($segments) > 2) {
            $this->seed(PrototypeContentSeeder::class);
        }

        $withSlash = $this->get($path)->assertOk();
        $withoutSlash = $this->get(rtrim($plainPath, '/').(str_contains($path, '?') ? '?'.parse_url($path, PHP_URL_QUERY) : ''))->assertOk();

        // canonical — адрес route() без завершающего «/» и без query; адрес со «/» отдаёт то же содержимое.
        $this->assertSame(config('app.url').rtrim($plainPath, '/'), PageHead::canonical($withSlash->getContent()));
        $this->assertSame($withoutSlash->getContent(), $withSlash->getContent());
    }

    private function assertNotFoundForGuestAndAdmin(string $path): void
    {
        $this->get($path)->assertNotFound();

        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get($path)->assertNotFound();
    }
}
