<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Оболочка по прототипу (W-03, §1.1): шапка, второй ряд, меню, подвал, cookie-плашка; надписи оболочки D-21 не выводятся. */
class ShellTest extends TestCase
{
    use RefreshDatabase;

    /** Надписи оболочки прототипа (D-21, «Надписи оболочки») и ссылки группы «Prototipo aplinkos» · «Prototype workspaces». */
    private const SHELL_LABELS = [
        'DEMONSTRACINĖ SVETAINĖ', 'DEMONSTRATION WEBSITE', 'demo-strip',
        'Demonstracinis prototipas', 'Demonstration prototype',
        'Šiam prototipui reikalingas JavaScript', 'This prototype requires JavaScript', '<noscript',
        'Ši demonstracija prisimena', 'This demo remembers',
        'Prototipo aplinkos', 'Prototype workspaces',
        'Kliento paskyra', 'Customer account', 'Administravimas', 'Administration',
        'Aprašas programuotojui', 'Developer brief', 'programuotojui', '/developer', '/administravimas', '/admin',
        '/assets/',
    ];

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /** @return list<array{0: string, 1: string}> [текст, href] ссылок по XPath */
    private function links(DOMXPath $xpath, string $query): array
    {
        $links = [];
        foreach ($xpath->query($query) as $link) {
            $links[] = [trim(preg_replace('/\s+/u', ' ', $link->textContent)), $link->getAttribute('href')];
        }

        return $links;
    }

    public function test_shell_labels_of_the_prototype_are_absent_on_lt_and_en_pages(): void
    {
        foreach (['/lt', '/en', '/lt/paslaugos', '/en/contact', '/lt/nera-tokio', '/en/missing'] as $path) {
            // Единственный допустимый noscript — стиль шапки-шторки (правка 28.10, П2), не надпись прототипа о JavaScript.
            $html = preg_replace('#<noscript><style nonce="[^"]+">\.header--overlay\{background:var\(--dark\)\}</style></noscript>#', '', $this->get($path)->getContent(), 1);
            foreach (self::SHELL_LABELS as $label) {
                $this->assertStringNotContainsStringIgnoringCase($label, $html, "$path: «{$label}»");
            }
        }
    }

    public function test_header_has_the_prototype_navigation_tools_and_quote_cta(): void
    {
        $xpath = $this->xpath($this->get('/lt/paslaugos')->getContent());

        $this->assertSame([
            ['Pagrindinis', route('lt.home')], ['Paslaugos', route('lt.services')], ['Produktai', route('lt.products')],
            ['Projektai', route('lt.projects')], ['Informacija', route('lt.information')], ['Apie mus', route('lt.about')],
            ['Kontaktai', route('lt.contact')],
        ], $this->links($xpath, '//header//nav[@id="main-nav"]/a'));
        $this->assertSame('Pagrindinis meniu', $xpath->query('//nav[@id="main-nav"]/@aria-label')->item(0)->value);

        $this->assertSame([['Paslaugos', route('lt.services')]], $this->links($xpath, '//nav[@id="main-nav"]/a[@class="active"][@aria-current="page"]'));

        $this->assertSame([['Pereiti prie turinio', '#main']], $this->links($xpath, '//a[@class="skip"]'));
        $this->assertSame(1, $xpath->query('//main[@id="main"][@tabindex="-1"]')->length);
        $this->assertSame(route('lt.search'), $xpath->query('//header//a[@aria-label="Paieška"]/@href')->item(0)->value);
        $this->assertSame(route('lt.cart'), $xpath->query('//header//a[@aria-label="Krepšelis"]/@href')->item(0)->value);
        $this->assertSame(route('lt.account'), $xpath->query('//header//a[@aria-label="Paskyra"]/@href')->item(0)->value);

        $menu = $xpath->query('//button[contains(@class,"menu-btn")]')->item(0);
        $this->assertSame('false', $menu->getAttribute('aria-expanded'));
        $this->assertSame('main-nav', $menu->getAttribute('aria-controls'));
        $this->assertSame('Atverti meniu', $menu->getAttribute('aria-label'));

        $this->assertSame('3D INŽINERIJA IR TECHNOLOGIJOS', trim($xpath->query('//div[contains(@class,"head-sub")]/span')->item(0)->textContent));
        $this->assertSame([['Įkelti modelį ir sužinoti kainą', route('lt.calculator')]], $this->links($xpath, '//a[@class="cta-link"]'));
    }

    public function test_english_shell_texts_and_no_active_item_on_404(): void
    {
        $xpath = $this->xpath($this->get('/en/missing')->getContent());

        $this->assertSame(['Home', 'Services', 'Products', 'Projects', 'Resources', 'About', 'Contact'],
            array_column($this->links($xpath, '//nav[@id="main-nav"]/a'), 0));
        $this->assertSame(0, $xpath->query('//nav[@id="main-nav"]/a[@class="active"]')->length);
        $this->assertSame([['Upload a model & get a price', route('en.calculator')]], $this->links($xpath, '//a[@class="cta-link"]'));
        $this->assertSame('3D ENGINEERING & TECHNOLOGY', trim($xpath->query('//div[contains(@class,"head-sub")]/span')->item(0)->textContent));
        $this->assertSame([['Go home', route('en.home')]], $this->links($xpath, '//main//a[contains(@class,"btn")]'));
    }

    public function test_footer_has_two_public_link_groups_and_policy_links_without_the_prototype_group(): void
    {
        $xpath = $this->xpath($this->get('/en')->getContent());

        $this->assertSame(['Solutions', 'Get to know us'], array_map(fn ($h) => $h->textContent, iterator_to_array($xpath->query('//footer//h4'))));
        $this->assertSame([
            ['Services', route('en.services')], ['Products', route('en.products')], ['Manufacturing quote', route('en.calculator')],
            ['Training', route('en.training')], ['About AIQME', route('en.about')], ['Projects', route('en.projects')],
            ['Free library', route('en.library')], ['Contact', route('en.contact')],
        ], $this->links($xpath, '//footer//div[@class="footer-top"]/div/a[not(@class="logo")]'));
        $this->assertSame([
            ['Delivery', route('en.delivery')], ['Returns', route('en.returns')], ['Privacy', route('en.privacy')], ['Cookies', route('en.cookies')],
        ], $this->links($xpath, '//footer//div[@class="footer-bottom"]//a'));
        $this->assertSame('One partner — from your challenge to a finished product.', $xpath->query('//footer//p')->item(0)->textContent);
        $this->assertSame('© '.now()->year.' AIQME', $xpath->query('//div[@class="footer-bottom"]/span')->item(0)->textContent);
    }

    public function test_cookie_notice_is_informational_essential_only(): void
    {
        foreach (['lt' => ['Analitika ir reklamos sekimas nenaudojami. Puslapyje „Kontaktai“ rodomas „Google Maps“ žemėlapis – jį įkeliant „Google“ gali naudoti savo slapukus.', 'Tik būtini', 'Nustatymai'],
            'en' => ['No analytics or advertising tracking is used. The Contact page shows a Google Maps map; Google may use its own cookies when it loads.', 'Essential only', 'Settings']] as $locale => [$text, $accept, $settings]) {
            $xpath = $this->xpath($this->get(route($locale.'.home'))->getContent());

            $cookie = $xpath->query('//div[@class="cookie"]')->item(0);
            $this->assertNotNull($cookie, $locale);
            $this->assertSame($text, $xpath->query('p', $cookie)->item(0)->textContent);
            $this->assertSame($accept, $xpath->query('.//button[@data-cookie-accept]', $cookie)->item(0)->textContent);
            $this->assertSame([[$settings, route($locale.'.cookies')]], $this->links($xpath, '//div[@class="cookie"]//a'));
            // Выбора аналитики нет (D-11): одна кнопка-подтверждение.
            $this->assertSame(1, $xpath->query('.//button', $cookie)->length);
        }
    }

    public function test_logo_and_favicon_are_the_prototype_defaults(): void
    {
        $xpath = $this->xpath($this->get('/lt')->getContent());

        foreach (['//header//a[@class="logo"]/img', '//footer//a[@class="logo"]/img'] as $query) {
            $this->assertSame(asset('defaults/logo.jpg'), $xpath->query($query)->item(0)->getAttribute('src'));
            $this->assertSame('AIQME', $xpath->query($query)->item(0)->getAttribute('alt'));
        }
        $this->assertSame(asset('defaults/favicon.svg'), $xpath->query('//head/link[@rel="icon"]')->item(0)->getAttribute('href'));
        // D-43 заменяет D-25: прежних копий логотипа нет.
        $this->assertDirectoryDoesNotExist(public_path('images'));
    }

    public function test_static_pages_use_the_prototype_page_top_with_breadcrumbs(): void
    {
        $xpath = $this->xpath($this->get('/lt/paslaugos')->getContent());

        $this->assertSame([['Pagrindinis', route('lt.home')]], $this->links($xpath, '//section[@class="page-top"]//div[@class="breadcrumbs"]/a'));
        $this->assertSame(['Pagrindinis', '/', 'Paslaugos jūsų idėjoms ir detalėms.'],
            array_map(fn ($node) => $node->textContent, iterator_to_array($xpath->query('//div[@class="breadcrumbs"]/*'))));
        $this->assertSame('AIQME', $xpath->query('//section[@class="page-top"]//div[@class="eyebrow"]')->item(0)->textContent);
        $this->assertSame('Paslaugos jūsų idėjoms ir detalėms.', $xpath->query('//section[@class="page-top"]//h1')->item(0)->textContent);
        $this->assertSame('Nuo vienos užduoties iki viso gamybos proceso. Aiškiai sutariame apimtį, eigą ir rezultatą.',
            $xpath->query('//section[@class="page-top"]//p')->item(0)->textContent);

        $home = $this->xpath($this->get('/lt')->getContent());
        $this->assertSame(0, $home->query('//div[@class="breadcrumbs"]')->length, 'на главной крошек нет');
    }

    public function test_prototype_css_is_carried_over_without_prototype_images_and_demo_classes(): void
    {
        $css = file_get_contents(resource_path('css/prototype/style.css'));

        $this->assertStringNotContainsString('url(', $css);
        $this->assertStringNotContainsStringIgnoringCase('demo', $css);
        $this->assertStringContainsString(':root{--blue:#0867ff;--ink:#11171e;--muted:#64717e;--line:#dce2e8;--soft:#f3f6f9;--dark:#0a1119;', $css);
        $this->assertStringContainsString('font-family:Arial,Helvetica,sans-serif', $css);
        // Поздние правила каскада сохраняют порядок исходника: светлый подвал перекрывает ранний тёмный.
        $this->assertGreaterThan(strpos($css, '.footer{background:var(--dark)'), strpos($css, '.footer{background:#fff'));

        $app = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString("@import './prototype/style.css';", $app);
        $this->assertStringNotContainsString('tailwindcss', $app, 'preflight Tailwind менял бы вид прототипа');
    }

    /** Шапка-шторка (правка 28.10, П2): сервер рисует тёмное состояние на всех страницах, полупрозрачное — только над hero. */
    public function test_header_is_dark_everywhere_and_overlays_only_the_home_hero(): void
    {
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        $client = User::factory()->create();
        $classes = fn (string $html): array => explode(' ', $this->xpath($html)->query('//header')->item(0)->getAttribute('class'));

        // Без записи home главная — заглушка page-top: тёмная шапка без полупрозрачности.
        foreach (['/lt', '/en'] as $path) {
            $this->assertSame(['header', 'header--dark'], $classes($this->get($path)->getContent()), "$path без home");
        }
        $this->seed(PrototypeContentSeeder::class);
        $pages = ['/lt' => true, '/en' => true, '/lt/apie-mus' => false, '/en/quote' => false, '/lt/nera-tokio' => false];
        foreach ($pages as $path => $overlay) {
            $this->assertSame($overlay ? ['header', 'header--dark', 'header--overlay'] : ['header', 'header--dark'], $classes($this->get($path)->getContent()), $path);
        }
        $this->assertSame(['header', 'header--dark'], $classes($this->actingAs($admin)->get('/en/admin')->assertOk()->getContent()), '/en/admin');
        $this->assertSame(['header', 'header--dark'], $classes($this->actingAs($client)->get('/en/account')->assertOk()->getContent()), '/en/account');
    }

    public function test_header_logo_holds_the_regular_and_the_white_logo_and_noscript_keeps_the_header_solid(): void
    {
        $response = $this->get('/en');
        $xpath = $this->xpath($response->getContent());

        $images = $xpath->query('//header//a[@class="logo"]/img');
        $this->assertCount(2, $images);
        $this->assertSame([asset('defaults/logo.jpg'), 'AIQME', null], [$images->item(0)->getAttribute('src'), $images->item(0)->getAttribute('alt'), $images->item(0)->attributes->getNamedItem('class')?->nodeValue]);
        $this->assertSame([asset('defaults/logo-white.png'), '', 'logo-white'], [$images->item(1)->getAttribute('src'), $images->item(1)->getAttribute('alt'), $images->item(1)->getAttribute('class')]);

        // Без JS полупрозрачная шапка главной сплошная: стиль в noscript с nonce этого ответа (CSP style-src-elem).
        preg_match("/style-src-elem 'self' 'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $nonce);
        $style = $xpath->query('//head/noscript/style');
        $this->assertCount(1, $style);
        $this->assertSame($nonce[1], $style->item(0)->getAttribute('nonce'));
        $this->assertSame('.header--overlay{background:var(--dark)}', $style->item(0)->textContent);
    }
}
