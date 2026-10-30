<?php

namespace Tests\Feature;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PageHead;
use Tests\TestCase;

/**
 * Адреса прототипа против таблицы §4.6 (04-content-seo.md): статические страницы, 301 и строки с 404 (W-02); адреса
 * услуг со slug из БД после сидера W-04 — 200 на своей локали, 404 со slug другой локали (W-05); статьи по адресу
 * библиотеки — 200, их дубли в /informacija/, /naujienos/ — 404, демо-проекты — 404, пока черновики, 200 после публикации (W-06);
 * демо-товары — 404 и после сидера (W-25); вход, регистрация, восстановление — 200, кабинет (корень и вкладка addresses) —
 * форма входа гостю и кабинет клиенту (вкладки overview, orders, models, invoices — W-15), корень админки — 200 администратору и 404 гостю и клиенту (W-07); вкладки админки
 * overview, services, content, audit — так же (W-08); products, categories, projects, training, translations (W-26);
 * integrations (W-19, раздел Telegram); orders, customers (W-14); settings (W-17); calculator (W-11); /ru/ — 404 гостю и
 * клиенту, 200 с noindex администратору. Вся таблица без пропусков — W-21.
 * Читает только отслеживаемый tests/fixtures/prototype-routes.json (без docs/customer-prototype/).
 */
class PrototypeRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../fixtures/prototype-routes.json';

    protected function setUp(): void
    {
        parent::setUp();
        // Вкладка integrations (W-19) рендерит Livewire-компонент: статический флаг автоподключения ресурсов Livewire
        // сбрасывается только flushState, иначе он переходит в следующие тесты процесса и меняет HTML публичных страниц.
        Livewire::flushState();
    }

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
        match ($row) {
            1 => $this->get($path)->assertStatus(301)->assertRedirect(route('lt.home')),
            2 => $this->assertPublicPage($path),
            // Slug другой локали, дубли статей и демо-товары — 404 после сидера (без сидера 404 ничего бы не доказывал).
            3, 4, 5 => $this->seed(PrototypeContentSeeder::class)->get($path)->assertNotFound(),
            7, 8, 12 => $this->get($path)->assertNotFound(),
            9 => $this->assertRussianAdminOnly($path),
            6 => $this->assertDraftProjectUntilPublished($path),
            10 => $this->assertAccountPage($path),
            11 => $this->assertOk200($path),
            13 => $this->assertAdminOnly($path),
            14 => $this->assertNotFoundForGuestAndAdmin($path),
            default => $this->fail('строка '.$row.' §4.6 без проверки: '.$path),
        };
    }

    private function assertPublicPage(string $path): void
    {
        $plainPath = strtok($path, '?');
        $segments = array_values(array_filter(explode('/', $plainPath)));
        if (count($segments) > 2) {
            $this->seed(PrototypeContentSeeder::class);
        }

        $withSlash = $this->get($path)->assertOk();
        $withoutSlash = $this->get(rtrim($plainPath, '/').(str_contains($path, '?') ? '?'.parse_url($path, PHP_URL_QUERY) : ''))->assertOk();

        // canonical — адрес route() без завершающего «/» и без query; адрес со «/» отдаёт то же содержимое.
        $this->assertSame(config('app.url').rtrim($plainPath, '/'), PageHead::canonical($withSlash->getContent()));
        // Токен отправки заявки калькулятора (W-12) — новый UUID на каждый показ формы.
        // Nonce CSP noscript-стиля шапки (правка 28.10, П2) — новый на каждый ответ.
        $token = fn (string $html): string => preg_replace(['/name="submission_token" value="[0-9a-f-]{36}"/', '/<style nonce="[^"]+">/'], ['name="submission_token"', '<style>'], $html);
        $this->assertSame($token($withoutSlash->getContent()), $token($withSlash->getContent()));
    }

    /** Демо-проект D-11: черновик сидера — 404; после публикации своей локали — 200 по тому же адресу. */
    private function assertDraftProjectUntilPublished(string $path): void
    {
        $this->seed(PrototypeContentSeeder::class);
        $this->get($path)->assertNotFound();

        [, $locale, , $slug] = explode('/', rtrim($path, '/'));
        $project = ContentItem::where('type', ContentType::Project)->where('key', $slug)->firstOrFail();
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        app(ContentService::class)->publish($admin, $project, $locale, $project->translation($locale)->revision, Channel::Web);

        $this->get($path)->assertOk();
    }

    private function assertOk200(string $path): void
    {
        $this->get($path)->assertOk()->assertSee('id="auth-form"', false);
    }

    /** Кабинет: гостю — форма входа (200), вошедшему клиенту — своя вкладка (200; корень — Overview, W-15). */
    private function assertAccountPage(string $path): void
    {
        $this->get($path)->assertOk()->assertSee('id="auth-form" data-mode="login"', false);
        $tab = basename(rtrim($path, '/'));
        $block = ['addresses' => 'address-form', 'orders' => 'account-orders', 'models' => 'account-models', 'invoices' => 'account-invoices'][$tab] ?? 'account-overview';
        $this->actingAs(User::factory()->create())->get($path)->assertOk()->assertSee('id="'.$block.'"', false)->assertDontSee('id="auth-form"', false);
    }

    /** Админка: администратору — 200, гостю и клиенту — 404. */
    private function assertAdminOnly(string $path): void
    {
        $this->get($path)->assertNotFound();
        $this->actingAs(User::factory()->create())->get($path)->assertNotFound();

        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get($path)->assertOk();
    }

    /** /ru/ (§4.3): гостю и клиенту — 404, вошедшему администратору — 200 с noindex. */
    private function assertRussianAdminOnly(string $path): void
    {
        $this->get($path)->assertNotFound();
        $this->actingAs(User::factory()->create())->get($path)->assertNotFound();

        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get($path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function assertNotFoundForGuestAndAdmin(string $path): void
    {
        $this->get($path)->assertNotFound();

        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get($path)->assertNotFound();
    }
}
