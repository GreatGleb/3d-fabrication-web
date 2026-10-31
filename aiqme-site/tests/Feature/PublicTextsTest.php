<?php

namespace Tests\Feature;

use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Payments\Models\PaymentRequest;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Tests\browser\ContentFactory;
use Tests\browser\OrderFactory;
use Tests\Support\Html;
use Tests\TestCase;

/** Тексты страниц W-05/W-06/W-25: таблица 1 D-21 на отрендеренных страницах и отсутствие LT-строк ui_text на EN-страницах (§7.2). */
class PublicTextsTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = [
        'lt' => ['/lt', '/lt/paslaugos', '/lt/apie-mus', '/lt/kontaktai', '/lt/duk', '/lt/paslaugos/3d-skenavimas', '/lt/paslaugos/3d-modeliavimas',
            '/lt/paslaugos/3d-spausdinimas', '/lt/paslaugos/frezavimas', '/lt/paslaugos/graviravimas', '/lt/paslaugos/3d-modeliavimo-mokymai',
            '/lt/privatumas', '/lt/slapukai', '/lt/pristatymas', '/lt/grazinimas',
            '/lt/projektai', '/lt/projektai/laikiklio-atkurimas', '/lt/informacija', '/lt/biblioteka', '/lt/naujienos',
            '/lt/biblioteka/file-guide', '/lt/mokymai', '/lt/paieska', '/lt/paieska?q=3D', '/lt/paieska?q=zzzz',
            '/lt/produktai', '/lt/produktai/norda-s1-skeneris', '/lt/palyginimas?products=norda-s1-skeneris,petg-gija', '/lt/palyginimas', '/lt/krepselis'],
        'en' => ['/en', '/en/services', '/en/about', '/en/contact', '/en/faq', '/en/services/3d-scanning', '/en/services/3d-modelling',
            '/en/services/3d-printing', '/en/services/cnc-machining', '/en/services/engraving', '/en/services/3d-modelling-training',
            '/en/privacy', '/en/cookies', '/en/delivery', '/en/returns',
            '/en/projects', '/en/projects/bracket-reconstruction', '/en/information', '/en/library', '/en/news',
            '/en/library/file-guide', '/en/training', '/en/search', '/en/search?q=3D', '/en/search?q=zzzz',
            '/en/products', '/en/products/norda-s1-scanner', '/en/compare?products=norda-s1-scanner,petg-filament', '/en/compare', '/en/cart'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeContentSeeder::class);
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        // С лентами, проектом, обучением (W-06) и товарами (W-25): их тексты и подписи тоже проверяются.
        ContentFactory::as($admin)->homeTickers();
        ContentFactory::as($admin)->publicSections();
        ContentFactory::as($admin)->catalog();
    }

    /** Видимый текст страницы и значения атрибутов, которые читает пользователь или экранный диктор. */
    private function visible(string $path): string
    {
        $xpath = Html::xpath($this->get($path)->assertOk()->getContent());
        $parts = [Html::text($xpath->query('//body')->item(0))];
        foreach ($xpath->query('//@aria-label | //@alt | //@title | //@placeholder') as $attribute) {
            $parts[] = $attribute->nodeValue;
        }

        return implode("\n", $parts);
    }

    public function test_pages_contain_no_d21_source_phrase_and_show_the_replacements(): void
    {
        $all = '';
        foreach (Arr::flatten(self::PAGES) as $path) {
            $text = $this->visible($path);
            $all .= $text;
            foreach (PrototypeContentSeederTest::D21_TABLE_1 as [$sources]) {
                foreach ($sources as $source) {
                    $this->assertStringNotContainsString($source, $text, $path.': исходная фраза D-21');
                }
            }
            // Таблица 2 D-21 (заметки автора в шаблонах W-05/W-06).
            foreach (['Planuojami prekių ženklai', 'Planned technology brands', 'DEMONSTRACINIS TVARKARAŠTIS', 'ILLUSTRATIVE SCHEDULE',
                'Visi šiame puslapyje rodomi projektai', 'demonstraciniai inžinerinių', 'Demonstracinis pavyzdys', 'nepatvirtintas atliktas projektas',
                'Techniniai gamintojų dokumentai ir jų vertimai', 'Manufacturer technical documents and translations',
                'Gamintojų instrukcijos ir techninių duomenų lapai', 'Manufacturer manuals and technical data sheets',
                'Registruotis į demonstracinį mokymą', 'Register for a demo session', 'DEMONSTRACINĖ ILIUSTRACIJA', 'ILLUSTRATIVE IMAGE',
                'DEMONSTRACINIS PAVYZDYS', 'ILLUSTRATIVE EXAMPLE'] as $source) {
                $this->assertStringNotContainsString($source, $text, $path);
            }
        }
        foreach (PrototypeContentSeederTest::D21_TABLE_1 as [, $replacements, $kept]) {
            foreach ([...$replacements, ...$kept] as $phrase) {
                // Правка 30.10 (Г4): строка form_note осталась в контенте, но на сайте не выводится.
                if (in_array($phrase, ['* — būtini laukai.', '* — required fields.'], true)) {
                    $this->assertStringNotContainsString($phrase, $all, 'form_note не выводится');

                    continue;
                }
                $this->assertStringContainsString($phrase, $all, 'фраза в новом виде выводится');
            }
        }
        $this->assertStringContainsString('Prekių ženklai', $this->visible('/lt'));
        $this->assertStringContainsString('Technology brands', $this->visible('/en'));
    }

    /**
     * Словарь §7.2: целая LT-строка ключа ui_text (lang/lt), отличающаяся от EN => ключ; одинаковые («AIQME», «FDM») не входят.
     *
     * @return array<string, string>
     */
    private function lithuanianDictionary(): array
    {
        $lithuanian = [];
        foreach (glob(lang_path('lt/*.php')) as $file) {
            $group = basename($file, '.php');
            // Плоские массивы групп, а не Lang::get по ключу: ключ с точкой ('0.12' слоёв печати) так не находится.
            $englishGroup = Arr::dot(Lang::get($group, [], 'en'));
            foreach (Arr::dot(Lang::get($group, [], 'lt')) as $key => $value) {
                $english = $englishGroup[$key] ?? null;
                if (is_string($value) && $value !== $english && trim($value) !== '') {
                    $lithuanian[trim(preg_replace('/\s+/u', ' ', $value))] = $group.'.'.$key;
                }
            }
        }
        $this->assertArrayHasKey('Rasti sprendimą ↗', $lithuanian, 'словарь собран из lang/lt');

        return $lithuanian;
    }

    /** На странице нет элемента, чей целый видимый текст (или атрибут для читателя) — LT-строка словаря. */
    private function assertNoLithuanianStrings(string $path, string $html, array $lithuanian): void
    {
        $xpath = Html::xpath($html);
        foreach ($xpath->query('//body//*') as $element) {
            $text = Html::text($element);
            $this->assertArrayNotHasKey($text, $lithuanian, $path.': LT-строка '.($lithuanian[$text] ?? '').' на EN-странице');
        }
        foreach ($xpath->query('//@aria-label | //@alt | //@title | //@placeholder') as $attribute) {
            $this->assertArrayNotHasKey(trim($attribute->nodeValue), $lithuanian, $path.': LT-строка в атрибуте');
        }
    }

    /**
     * Автотест по словарю (§7.2): на EN-страницах нет ни одного элемента, чей целый видимый текст — LT-строка ключа
     * ui_text (lang/lt), отличающаяся от EN; одинаковые («AIQME», «FDM») не проверяются.
     */
    public function test_english_pages_have_no_lithuanian_ui_text_strings(): void
    {
        $lithuanian = $this->lithuanianDictionary();
        foreach (self::PAGES['en'] as $path) {
            $this->assertNoLithuanianStrings($path, $this->get($path)->getContent(), $lithuanian);
        }
    }

    /**
     * Словарь по всем EN-страницам (W-21): каждый GET-адрес /en без параметров из таблицы маршрутов (публичные, вход,
     * кабинет клиента, админка администратору), сущности из sitemap, карточка заказа клиента и админки, гостевая страница
     * и страница оплаты на заказах фабрик tests/browser/ (W-14, W-15). Вкладка Translations админки показывает LT-значения
     * ui_text как данные редактора — на ней проверяется только оболочка вне формы редактора.
     */
    public function test_every_english_page_has_no_lithuanian_ui_text_strings(): void
    {
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        $admin = User::where('is_admin', true)->sole();
        $client = User::factory()->create();
        $guest = OrderFactory::confirmedWithRequestAndTracking($admin);
        $guestUrl = '/en/order/'.OrderMeta::where('order_id', $guest->id)->value('number').'/'.app(OrderWorkflow::class)->reissueGuestLink($admin, $guest);
        $own = OrderFactory::clientOrderWithMessage($client, $admin);
        $checkout = '/en/checkout/'.PaymentRequest::where('order_id', $guest->id)->value('public_id');

        $static = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array('GET', $route->methods(), true) && preg_match('#^en(/|$)#', $route->uri()) && ! str_contains($route->uri(), '{'))
            ->map(fn ($route): string => '/'.$route->uri())
            ->reject(fn (string $path): bool => $path === '/en/quote/submitted');
        preg_match_all('#<loc>'.preg_quote(config('app.url'), '#').'(/en/[^<]+)</loc>#', $this->get('/sitemap.xml')->getContent(), $sitemap);
        $public = $static->reject(fn (string $path): bool => str_starts_with($path, '/en/admin') || (str_starts_with($path, '/en/account') && ! preg_match('#/(login|register|reset)$#', $path)));
        $pages = [
            'guest' => [...$public, ...$sitemap[1], ...self::PAGES['en'], $guestUrl],
            'client' => [...$static->filter(fn (string $path): bool => str_starts_with($path, '/en/account') && ! preg_match('#/(login|register|reset)$#', $path)),
                '/en/account/orders/'.OrderMeta::where('order_id', $own->id)->value('number')],
            'admin' => [...$static->filter(fn (string $path): bool => str_starts_with($path, '/en/admin')), '/en/admin/orders?order='.$guest->id, $checkout],
        ];
        $this->assertGreaterThan(60, count(array_unique(Arr::flatten($pages))), 'страниц мало: список не собран');

        $lithuanian = $this->lithuanianDictionary();
        foreach ($pages as $who => $paths) {
            foreach (array_unique($paths) as $path) {
                $request = match ($who) {
                    'client' => $this->actingAs($client),
                    'admin' => $this->actingAs($admin),
                    default => $this,
                };
                $html = $request->get($path)->assertOk()->getContent();
                if ($path === '/en/admin/translations') {
                    $html = preg_replace('#<main\b.*</main>#s', '<main></main>', $html);
                }
                $this->assertNoLithuanianStrings($path.' ('.$who.')', $html, $lithuanian);
            }
            Auth::logout();
        }
    }
}
