<?php

namespace Tests\Feature;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\browser\ContentFactory;
use Tests\Support\Html;
use Tests\Support\PageHead;
use Tests\TestCase;

/**
 * Каталог W-25 (§1.2, F-03–F-06, D-12, D-21, D-36, D-41): заглушка до публикации, фильтры категории и бренда, поиск по
 * названию, сортировки, страница товара с «Request a quote» в контактную форму, сравнение до 4, пустая корзина, sitemap.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    /** Плановые пометки каталога и демо-фразы прототипа (D-21 таблица 2, D-36), которых нет ни на одной странице каталога. */
    private const REMOVED = [
        'Planuojamas asortimentas', 'Planned range', 'PLANUOJAMA', 'PLANNED RANGE', '/ PLANNED',
        'Planuojami prekių ženklai ir sprendimai', 'Planned brands and solutions', 'official representation', 'oficialaus atstovavimo',
        'Tiekimas tikslinamas', 'Supply to be confirmed', 'Tiekimo būsena', 'Supply status',
        'Scheminė kategorijos iliustracija', 'Schematic category illustration', 'Techniniai dokumentai nepateikti',
        'Technical documents have not been provided', 'Prototipe', 'Demonstracinis prototipas', 'Demonstration prototype',
        'DEMO PREKĖ', 'DEMO PRODUCT', 'Demo kaina', 'Demo price', 'be PVM', 'excl. VAT', 'Technologija', 'Technology',
        'Iki kainos', 'Maximum price', 'Kaina didėjančiai', 'Price: low to high', 'Į krepšelį', 'Add to basket',
        'Pirkimo eigą galite išbandyti', 'Try the purchase flow', 'To be confirmed', 'Tikslinamas',
        'Antras demonstracinis vaizdas', 'Second illustrative view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeContentSeeder::class);
    }

    private function admin(): User
    {
        if ($admin = User::where('is_admin', true)->first()) {
            return $admin;
        }
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        return $admin;
    }

    private function factory(): ContentFactory
    {
        return ContentFactory::as($this->admin());
    }

    private function cards(string $url): array
    {
        return Html::texts(Html::xpath($this->get($url)->assertOk()->getContent()), '//div[@id="product-results"]/div['.Html::hasClass('product-card').']//h3');
    }

    private function unpublish(ContentItem $item, string $locale): void
    {
        app(ContentService::class)->unpublish($this->admin(), $item, $locale, $item->translation($locale)->revision, Channel::Web);
    }

    public function test_products_show_a_placeholder_until_published_then_cards_without_code_changes(): void
    {
        foreach (['/lt/produktai' => 'Informacija ruošiama', '/en/products' => 'Information is being prepared'] as $url => $placeholder) {
            $xpath = Html::xpath($this->get($url)->assertOk()->getContent());
            $this->assertSame($placeholder, Html::text($xpath->query('//main//div[@class="empty"]/h3')->item(0)));
            $this->assertCount(0, $xpath->query('//form[@class="catalog"] | //div[@class="brand-row"] | //div[@data-compare-bar]'));
        }

        $this->factory()->catalog();

        $xpath = Html::xpath($this->get('/en/products')->getContent());
        $this->assertCount(0, $xpath->query('//main//div[@class="empty"]'));
        $this->assertSame('4 products found', Html::text($xpath->query('//p[@id="result-count"]')->item(0)));
        $card = $xpath->query('//div[@id="product-results"]/div['.Html::hasClass('product-card').']')->item(0);
        // Одна дата публикации — порядок позиций: первым идёт сканер.
        $this->assertSame(['NORDA', '3D scanners', 'NORDA S1 scanner', 'On request', 'Request a quote', 'Compare'],
            Html::texts($xpath, './/span[@class="tag"] | .//small | .//h3 | .//div[@class="price"] | .//a[contains(@class,"btn")] | .//label', $card));
        $this->assertSame(route('en.products.show', ['slug' => 'norda-s1-scanner']), $xpath->query('.//a[contains(@class,"btn")]', $card)->item(0)->getAttribute('href'));
        $this->assertSame('norda-s1-scanner', $xpath->query('.//input[@type="checkbox"]', $card)->item(0)->getAttribute('data-compare'));
        $this->assertCount(0, $xpath->query('//button[@data-action="add-cart"]'));
        $this->assertSame(['NORDA', 'VEKTA'], Html::texts($xpath, '//div[@class="brand-row"]/span'));
        $this->assertSame(['Rasta produktų: 4'], Html::texts(Html::xpath($this->get('/lt/produktai')->getContent()), '//p[@id="result-count"]'));

        // Снятие с публикации на EN возвращает заглушку только EN.
        foreach (ContentItem::where('type', ContentType::Product)->get() as $item) {
            $this->unpublish($item, 'en');
        }
        $this->assertSame('Information is being prepared', Html::text(Html::xpath($this->get('/en/products')->getContent())->query('//main//div[@class="empty"]/h3')->item(0)));
        $this->assertCount(4, $this->cards('/lt/produktai'));
    }

    public function test_filters_by_category_slug_and_brand_id_search_by_name_and_empty_result(): void
    {
        $this->factory()->catalog();
        $vekta = ContentItem::where('type', ContentType::Brand)->where('key', 'vekta')->firstOrFail();

        $xpath = Html::xpath($this->get('/en/products')->getContent());
        $this->assertSame(['All categories', '3D scanners', '3D printers', 'Materials', 'Parts & accessories', 'Software'],
            Html::texts($xpath, '//select[@name="category"]/option'));
        $this->assertSame(['', 'scanners', 'printers', 'materials', 'accessories', 'software'],
            array_map(fn ($option) => $option->getAttribute('value'), iterator_to_array($xpath->query('//select[@name="category"]/option'))));
        $this->assertSame(['All manufacturers', 'NORDA', 'VEKTA'], Html::texts($xpath, '//select[@name="brand"]/option'));
        $this->assertSame((string) $vekta->id, $xpath->query('//select[@name="brand"]/option[3]')->item(0)->getAttribute('value'));
        // Фильтров технологии и цены и сортировки по цене нет (D-41).
        $this->assertCount(0, $xpath->query('//select[@name="tech"] | //input[@name="max"]'));
        $this->assertSame(['Recommended', 'Name A–Z'], Html::texts($xpath, '//select[@name="sort"]/option'));
        $this->assertSame(['form', 'get', route('en.products')], [$xpath->query('//form[@class="catalog"]')->item(0)->nodeName,
            $xpath->query('//form[@class="catalog"]')->item(0)->getAttribute('method'), $xpath->query('//form[@class="catalog"]')->item(0)->getAttribute('action')]);

        $this->assertSame(['VEKTA P2 printer'], $this->cards('/en/products?category=printers'));
        $this->assertSame(['PETG gija'], $this->cards('/lt/produktai?category=materials'));
        $this->assertEqualsCanonicalizing(['PETG filament', 'VEKTA P2 printer'], $this->cards('/en/products?brand='.$vekta->id));
        $this->assertSame(['PETG filament'], $this->cards('/en/products?brand='.$vekta->id.'&category=materials'));
        // Поиск — по названию без учёта регистра; описание и характеристики не ищутся.
        $this->assertSame(['NORDA S1 scanner'], $this->cards('/en/products?q=s1%20SCANNER'));
        $this->assertSame([], $this->cards('/en/products?q=optical'));
        $this->assertSame(['NORDA S1 skeneris'], $this->cards('/lt/produktai?q=skeneris'));
        // Выбранные значения остаются в форме.
        $xpath = Html::xpath($this->get('/en/products?category=printers&q=vekta&sort=name')->getContent());
        $this->assertSame('printers', $xpath->query('//select[@name="category"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('vekta', $xpath->query('//input[@id="catalog-search"]')->item(0)->getAttribute('value'));
        $this->assertSame('name', $xpath->query('//select[@name="sort"]/option[@selected]')->item(0)->getAttribute('value'));

        // Пустой результат: неизвестная категория, бренд без товаров этой категории.
        foreach (['/en/products?category=unknown', '/en/products?brand='.$vekta->id.'&category=software', '/en/products?q=zzzz'] as $url) {
            $xpath = Html::xpath($this->get($url)->assertOk()->getContent());
            $this->assertSame(['No results'], Html::texts($xpath, '//div[@id="product-results"]/div[@class="empty"]/h3'), $url);
            $this->assertSame('0 products found', Html::text($xpath->query('//p[@id="result-count"]')->item(0)));
            $this->assertSame(route('en.products'), $xpath->query('//div[@id="product-results"]//a[contains(@class,"btn")]')->item(0)->getAttribute('href'));
        }
    }

    public function test_sorts_recommended_by_newest_translation_publication_and_by_name(): void
    {
        $factory = $this->factory();
        foreach (['2026-10-20 10:00:00', '2026-10-20 12:00:00', '2026-10-20 11:00:00'] as $index => $time) {
            Carbon::setTestNow($time);
            $factory->product('p'.$index, null, 'materials', [
                'en' => [['Bravo', 'Charlie', 'alpha'][$index].' item', 'p-'.$index, '', '', ''],
                'lt' => [['Žalia', 'Čiaupas', 'Ąsotis'][$index], 'p-'.$index, '', '', ''],
            ], $index);
        }
        Carbon::setTestNow();

        $this->assertSame(['Charlie item', 'alpha item', 'Bravo item'], $this->cards('/en/products'));
        $this->assertSame(['Charlie item', 'alpha item', 'Bravo item'], $this->cards('/en/products?sort=recommended'));
        $this->assertSame(['alpha item', 'Bravo item', 'Charlie item'], $this->cards('/en/products?sort=name'));
        // Литовский алфавит: Ą после A, Č после C, Ž в конце.
        $this->assertSame(['Ąsotis', 'Čiaupas', 'Žalia'], $this->cards('/lt/produktai?sort=name'));
        // Неизвестная сортировка (в том числе по цене) — «Recommended».
        $this->assertSame(['Charlie item', 'alpha item', 'Bravo item'], $this->cards('/en/products?sort=price-asc'));

        // Повторная публикация перевода поднимает товар в «Recommended».
        Carbon::setTestNow('2026-10-20 13:00:00');
        $item = ContentItem::where('key', 'p0')->firstOrFail();
        $service = app(ContentService::class);
        $draft = $service->saveDraft($this->admin(), $item, 'en', ['title' => 'Bravo item', 'slug' => 'p-0', 'fields' => ['description' => 'Updated.']],
            $item->translation('en')->revision, Channel::Web);
        $service->publish($this->admin(), $item, 'en', $draft->revision, Channel::Web);
        Carbon::setTestNow();
        $this->assertSame(['Bravo item', 'Charlie item', 'alpha item'], $this->cards('/en/products'));
    }

    public function test_product_page_by_own_locale_slug_with_quote_link_and_without_purchase(): void
    {
        $this->factory()->catalog();

        $html = $this->get('/en/products/vekta-p2-printer')->assertOk()->getContent();
        $xpath = Html::xpath($html);
        $this->assertSame('VEKTA P2 printer', Html::text($xpath->query('//h1')->item(0)));
        $this->assertSame(['AIQME / PRODUCTS'], Html::texts($xpath, '//section[@class="page-top"]//div[@class="eyebrow"]'));
        $this->assertSame(['3D printers'], Html::texts($xpath, '//section[@class="page-top"]//p'));
        $this->assertSame(['VEKTA'], Html::texts($xpath, '//span[@class="tag blue"]'));
        $this->assertSame('An FDM printer for functional parts.', Html::text($xpath->query('//p[@class="lead"]')->item(0)));
        $this->assertSame('Tailored quotation', Html::text($xpath->query('//div[@class="price"]')->item(0)));
        $quote = $xpath->query('//main//a[normalize-space()="Request a quote"]')->item(0);
        $this->assertSame(route('en.contact', ['product' => 'vekta-p2-printer']), $quote->getAttribute('href'));
        $this->assertSame(['Delivery estimate: confirmed in the proposal', 'PLA, PETG and ABS filament.'], Html::texts($xpath, '//ul[@class="list-clean"]/li'));
        $this->assertStringContainsString('Build volume 250 × 250 × 250 mm.', Html::text($xpath->query('//div[@class="panel"]')->item(0)));
        $this->assertSame(['Category', '3D printers'], Html::texts($xpath, '//div[@class="summary-row"]/*'));
        $this->assertSame(['Documents'], Html::texts($xpath, '//div[@class="panel"][2]/h3[2]'));
        $this->assertSame(['Information is being prepared'], Html::texts($xpath, '//div[@class="panel"][2]//div[@class="empty"]/h3'));
        $this->assertSame(['An administrator must confirm compatibility against the exact equipment model. Unconfirmed accessories are not presented as compatible.'],
            Html::texts($xpath, '//div[@class="notice gap"]'));
        $this->assertCount(0, $xpath->query('//button[@data-action="add-cart"] | //input[@name="quantity"] | //select[@name="variant"]'));
        $this->assertSame(config('app.url').'/en/products/vekta-p2-printer', PageHead::canonical($html));
        $this->assertSame(['lt' => config('app.url').'/lt/produktai/vekta-p2-spausdintuvas', 'en' => config('app.url').'/en/products/vekta-p2-printer',
            'x-default' => config('app.url').'/lt/produktai/vekta-p2-spausdintuvas'], PageHead::hreflang($html));

        $lt = Html::xpath($this->get('/lt/produktai/vekta-p2-spausdintuvas')->assertOk()->getContent());
        $this->assertSame(['Suderinamumą turi patvirtinti administratorius pagal tikslų įrangos modelį. Nepatvirtinti priedai nesiūlomi kaip tinkami.'],
            Html::texts($lt, '//div[@class="notice gap"]'));
        $this->assertSame(route('lt.contact', ['product' => 'vekta-p2-spausdintuvas']), $lt->query('//main//a[normalize-space()="Gauti pasiūlymą"]')->item(0)->getAttribute('href'));

        // Slug другой локали, неизвестный, черновик, снятый с публикации и демо-товар прототипа — 404.
        $this->get('/en/products/vekta-p2-spausdintuvas')->assertNotFound();
        $this->get('/lt/produktai/vekta-p2-printer')->assertNotFound();
        $this->get('/en/products/bambu-demo')->assertNotFound();
        $draft = app(ContentService::class)->createItem($this->admin(), ContentType::Product, 'draft-product', ['category' => 'materials']);
        app(ContentService::class)->saveDraft($this->admin(), $draft, 'en', ['title' => 'Draft product', 'slug' => 'draft-product'], 0, Channel::Web);
        $this->get('/en/products/draft-product')->assertNotFound();
        $this->assertNotContains('Draft product', $this->cards('/en/products'));
        $this->unpublish(ContentItem::where('key', 'vekta-printer')->firstOrFail(), 'en');
        $this->get('/en/products/vekta-p2-printer')->assertNotFound();
        $this->get('/lt/produktai/vekta-p2-spausdintuvas')->assertOk();
    }

    public function test_product_page_gallery_uses_product_photos_or_a_placeholder(): void
    {
        [$scanner] = $this->factory()->catalog();
        $xpath = Html::xpath($this->get('/en/products/norda-s1-scanner')->getContent());
        $this->assertCount(1, $xpath->query('//div[@id="gallery"]/div[@class="gallery-view"]/*[name()="svg"]'));
        $this->assertCount(0, $xpath->query('//div[@class="gallery-thumbs"]'));

        $directory = sys_get_temp_dir().'/aiqme-catalog-'.uniqid();
        mkdir($directory);
        foreach (['a', 'b'] as $name) {
            $image = imagecreatetruecolor(600, 400);
            imagepng($image, $directory.'/'.$name.'.png');
            app(ContentService::class)->addMedia($this->admin(), $scanner, $directory.'/'.$name.'.png', 'images', ['lt' => 'Skeneris', 'en' => 'Scanner']);
        }

        $xpath = Html::xpath($this->get('/en/products/norda-s1-scanner')->getContent());
        $views = $xpath->query('//div[@id="gallery"]/div[@data-gallery-view]');
        $this->assertCount(2, $views);
        $this->assertFalse($views->item(0)->hasAttribute('hidden'));
        $this->assertTrue($views->item(1)->hasAttribute('hidden'));
        $this->assertSame('Scanner', $xpath->query('.//img', $views->item(0))->item(0)->getAttribute('alt'));
        $this->assertSame(['Main view', 'View 2'], array_map(fn ($button) => $button->getAttribute('aria-label'),
            iterator_to_array($xpath->query('//div[@class="gallery-thumbs"]/button'))));
        $this->assertSame('true', $xpath->query('//div[@class="gallery-thumbs"]/button[1]')->item(0)->getAttribute('aria-pressed'));
        // Карточка списка — первое фото товара.
        $card = Html::xpath($this->get('/en/products')->getContent())->query('//div[@id="product-results"]//a[@class="visual"][.//img]');
        $this->assertCount(1, $card);
        array_map('unlink', glob($directory.'/*'));
        rmdir($directory);
    }

    public function test_compare_takes_at_most_four_published_products_and_shows_a_table_from_two(): void
    {
        $this->factory()->catalog();
        $this->factory()->product('fifth', null, 'accessories', ['en' => ['Nozzle set', 'nozzle-set', '', '', '']], 5);
        $draft = app(ContentService::class)->createItem($this->admin(), ContentType::Product, 'draft-product', []);
        app(ContentService::class)->saveDraft($this->admin(), $draft, 'en', ['title' => 'Draft product', 'slug' => 'draft-product'], 0, Channel::Web);

        $empty = fn (string $url) => Html::texts(Html::xpath($this->get($url)->assertOk()->getContent()), '//main//div[@class="empty"]/h3');
        $this->assertSame(['Select at least two products'], $empty('/en/compare'));
        $this->assertSame(['Select at least two products'], $empty('/en/compare?products=norda-cad'));
        // Черновик, другая локаль, неизвестный slug и повтор не считаются.
        $this->assertSame(['Select at least two products'], $empty('/en/compare?products=norda-cad,draft-product,petg-gija,unknown,norda-cad'));
        $this->assertSame(['Pasirinkite bent du produktus'], $empty('/lt/palyginimas'));
        $xpath = Html::xpath($this->get('/en/compare')->getContent());
        $this->assertSame(route('en.products'), $xpath->query('//main//div[@class="empty"]/a')->item(0)->getAttribute('href'));
        $this->assertCount(0, $xpath->query('//table'));

        $xpath = Html::xpath($this->get('/en/compare?products=norda-cad,petg-filament')->assertOk()->getContent());
        $this->assertSame(['Feature', 'NORDA CAD', 'PETG filament'], Html::texts($xpath, '//table/thead/tr/th'));
        $this->assertSame([
            ['Manufacturer', 'NORDA', 'VEKTA'],
            ['Category', 'Software', 'Materials'],
            ['Price', 'On request', 'On request'],
            ['Compatibility', 'Windows 11.', 'FDM printers.'],
            ['', 'View', 'View'],
        ], array_map(fn ($row) => Html::texts($xpath, './td', $row), iterator_to_array($xpath->query('//table/tbody/tr'))));
        $this->assertSame(route('en.products.show', ['slug' => 'petg-filament']), $xpath->query('//table//tr[last()]/td[3]/a')->item(0)->getAttribute('href'));

        // Пять выбранных — в таблице первые четыре.
        $xpath = Html::xpath($this->get('/en/compare?products=norda-s1-scanner,vekta-p2-printer,draft-product,petg-filament,norda-cad,nozzle-set')->getContent());
        $this->assertSame(['Feature', 'NORDA S1 scanner', 'VEKTA P2 printer', 'PETG filament', 'NORDA CAD'], Html::texts($xpath, '//table/thead/tr/th'));
        $this->assertStringNotContainsString('Nozzle set', $this->get('/en/compare?products=norda-s1-scanner,vekta-p2-printer,petg-filament,norda-cad,nozzle-set')->getContent());

        // Панель выбора на странице товаров: предел 4, адрес сравнения.
        $bar = Html::xpath($this->get('/en/products')->getContent())->query('//div[@data-compare-bar]')->item(0);
        $this->assertSame(['4', route('en.compare')], [$bar->getAttribute('data-compare-limit'), $bar->getElementsByTagName('a')->item(0)->getAttribute('href')]);
        $this->assertTrue($bar->hasAttribute('hidden'));
    }

    public function test_cart_is_an_empty_state_with_a_link_to_the_calculator(): void
    {
        $this->factory()->catalog();
        foreach (['/lt/krepselis' => ['Jūsų krepšelis', 'Krepšelis kol kas tuščias', 'lt.calculator'], '/en/cart' => ['Your basket', 'Your basket is empty', 'en.calculator']] as $url => [$title, $empty, $calculator]) {
            $xpath = Html::xpath($this->get($url)->assertOk()->getContent());
            $this->assertSame($title, Html::text($xpath->query('//h1')->item(0)));
            $this->assertSame([$empty], Html::texts($xpath, '//main//div[@class="empty"]/h3'));
            $this->assertSame(route($calculator), $xpath->query('//main//div[@class="empty"]/a')->item(0)->getAttribute('href'));
            $this->assertCount(0, $xpath->query('//main//div[@class="empty"]/p | //main//table'));
        }
    }

    public function test_catalog_pages_have_no_planned_range_marks_or_demo_phrases(): void
    {
        $this->factory()->catalog();
        $pages = ['/lt/produktai', '/en/products', '/lt/produktai?q=zzzz', '/en/products?q=zzzz', '/lt/produktai/norda-s1-skeneris', '/en/products/norda-s1-scanner',
            '/lt/palyginimas?products=norda-s1-skeneris,petg-gija,norda-cad,vekta-p2-spausdintuvas', '/en/compare?products=norda-s1-scanner,petg-filament,norda-cad,vekta-p2-printer',
            '/lt/palyginimas', '/en/compare', '/lt/krepselis', '/en/cart'];
        foreach ($pages as $url) {
            $text = Html::text(Html::xpath($this->get($url)->assertOk()->getContent())->query('//main')->item(0));
            foreach (self::REMOVED as $phrase) {
                $this->assertStringNotContainsStringIgnoringCase($phrase, $text, $url);
            }
        }
        // Остаются: «Pagal užklausą» · «On request», «Gauti pasiūlymą» · «Request a quote».
        $this->assertStringContainsString('Pagal užklausą', $this->get('/lt/produktai')->getContent());
        $this->assertStringContainsString('Gauti pasiūlymą', $this->get('/lt/produktai/norda-s1-skeneris')->getContent());
        $this->assertStringContainsString('On request', $this->get('/en/compare?products=norda-cad,petg-filament')->getContent());
    }

    public function test_sitemap_lists_published_products_with_alternates_and_drops_them_after_unpublishing(): void
    {
        $xml = fn (): string => $this->get('/sitemap.xml')->getContent();
        $this->assertStringNotContainsString('/produktai', $xml());
        $this->assertStringNotContainsString('/products', $xml());

        [$scanner] = $this->factory()->catalog();
        $this->factory()->product('lt-only', null, 'materials', ['lt' => ['Tik LT', 'tik-lt', '', '', '']], 9);
        $sitemap = $xml();
        foreach (['/lt/produktai', '/en/products', '/lt/produktai/norda-s1-skeneris', '/en/products/norda-s1-scanner', '/lt/produktai/tik-lt'] as $path) {
            $this->assertStringContainsString('<loc>'.config('app.url').$path.'</loc>', $sitemap, $path);
        }
        preg_match('~<url>\s*<loc>'.preg_quote(config('app.url').'/en/products/norda-s1-scanner', '~').'</loc>(.*?)</url>~s', $sitemap, $block);
        $this->assertStringContainsString('hreflang="lt" href="'.config('app.url').'/lt/produktai/norda-s1-skeneris"', $block[1]);
        preg_match('~<url>\s*<loc>'.preg_quote(config('app.url').'/lt/produktai/tik-lt', '~').'</loc>(.*?)</url>~s', $sitemap, $block);
        $this->assertStringNotContainsString('hreflang="en"', $block[1]);
        // Сравнение и корзина — не в sitemap.
        $this->assertStringNotContainsString('/palyginimas', $sitemap);
        $this->assertStringNotContainsString('/krepselis', $sitemap);

        $this->unpublish($scanner, 'en');
        $sitemap = $xml();
        $this->assertStringNotContainsString('/en/products/norda-s1-scanner', $sitemap);
        $this->assertStringContainsString('/lt/produktai/norda-s1-skeneris', $sitemap);
        preg_match('~<url>\s*<loc>'.preg_quote(config('app.url').'/lt/produktai/norda-s1-skeneris', '~').'</loc>(.*?)</url>~s', $sitemap, $block);
        $this->assertStringNotContainsString('hreflang="en"', $block[1]);
    }

    public function test_home_brand_ribbon_shows_a_brand_after_it_is_published(): void
    {
        $this->assertCount(0, Html::xpath($this->get('/en')->getContent())->query('//div[@class="partner-marquee"]'));
        $this->factory()->brand('orbis', 'ORBIS', 1);
        $xpath = Html::xpath($this->get('/en')->getContent());
        $this->assertSame(['ORBIS'], Html::texts($xpath, '//div[@class="partner-marquee-group" and @role="list"]/div'));
    }
}
