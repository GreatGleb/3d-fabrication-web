<?php

namespace Tests\Feature;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\browser\ContentFactory;
use Tests\Support\Html;
use Tests\Support\PageHead;
use Tests\TestCase;

/**
 * Публичные страницы W-06 (§1.2, §4.1, §4.2) на исходном контенте сидера W-04: проекты, информация, библиотека, новости,
 * статьи, обучение, поиск и их место в sitemap.
 */
class PublicSectionsTest extends TestCase
{
    use RefreshDatabase;

    private const ARTICLES = ['file-guide', 'material-selection', 'scan-to-cad'];

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

    private function item(ContentType $type, string $key): ContentItem
    {
        return ContentItem::where('type', $type)->where('key', $key)->firstOrFail();
    }

    /** Публикует последнюю версию перевода (черновик сидера) — как «Опубликовать» в админке. */
    private function publish(ContentType $type, string $key, string $locale): void
    {
        $item = $this->item($type, $key);
        app(ContentService::class)->publish($this->admin(), $item, $locale, $item->translation($locale)->revision, Channel::Web);
    }

    private function unpublish(ContentType $type, string $key, string $locale): void
    {
        $item = $this->item($type, $key);
        app(ContentService::class)->unpublish($this->admin(), $item, $locale, $item->translation($locale)->revision, Channel::Web);
    }

    /** @return list<string> */
    private function sitemapLocs(): array
    {
        preg_match_all('~<loc>([^<]+)</loc>~', $this->get('/sitemap.xml')->getContent(), $matches);

        return $matches[1];
    }

    /** @return array<string, string> hreflang => href у адреса sitemap */
    private function sitemapAlternates(string $loc): array
    {
        $xml = $this->get('/sitemap.xml')->getContent();
        preg_match('~<url>\s*<loc>'.preg_quote($loc, '~').'</loc>(.*?)</url>~s', $xml, $block);
        preg_match_all('~hreflang="([^"]+)" href="([^"]+)"~', $block[1] ?? '', $links, PREG_SET_ORDER);

        return array_column($links, 2, 1);
    }

    /** @return list<string> заголовки карточек проектов */
    private function projectTitles(string $url): array
    {
        return Html::texts(Html::xpath($this->get($url)->assertOk()->getContent()), '//div[@id="project-results"]//h3');
    }

    /** @return list<string> заголовки карточек статей */
    private function articleTitles(string $url): array
    {
        return Html::texts(Html::xpath($this->get($url)->assertOk()->getContent()), '//div[@id="resource-results"]//h3');
    }

    public function test_projects_show_the_placeholder_while_drafts_and_published_projects_without_code_changes(): void
    {
        foreach (['/lt/projektai', '/en/projects'] as $url) {
            $xpath = Html::xpath($this->get($url)->assertOk()->getContent());
            $this->assertSame(['Informacija ruošiama', 'Information is being prepared'][$url === '/en/projects' ? 1 : 0],
                Html::text($xpath->query('//main//div[@class="empty"]/h3')->item(0)));
            $this->assertSame(0, $xpath->query('//main//form|//main//*[@id="project-results"]')->length, $url);
        }
        $this->get('/lt/projektai')->assertSee('Nuo užduoties iki rezultato: inžinerinių sprendimų pavyzdžiai.')
            ->assertDontSee('demonstraciniai')->assertDontSee('Visi šiame puslapyje');
        $this->get('/en/projects')->assertSee('From task to result: engineering solution examples.');

        $this->publish(ContentType::Project, 'prototype', 'lt');
        $this->assertSame(['Idėja, kurią galima paimti į rankas.'], $this->projectTitles('/lt/projektai'));
        $html = $this->get('/lt/projektai')->getContent();
        $this->assertStringContainsString('href="'.route('lt.projects.show', ['slug' => 'prototype']).'"', $html);
        foreach (['DEMONSTRACINIS PAVYZDYS', 'DEMONSTRACINĖ ILIUSTRACIJA', '/assets/'] as $demo) {
            $this->assertStringNotContainsString($demo, $html);
        }
        // EN не опубликован: EN-список — заглушка, EN-карточка — 404, LT-текст под EN не подставляется (F-38).
        $this->get('/en/projects')->assertSee('Information is being prepared')->assertDontSee('Idėja, kurią');
        $this->get('/en/projects/prototype')->assertNotFound();
        $this->get('/lt/projektai/part-reconstruction')->assertNotFound();
    }

    public function test_project_page_has_story_related_services_and_contact_and_english_shows_english_texts(): void
    {
        $this->publish(ContentType::Project, 'prototype', 'lt');
        $this->publish(ContentType::Project, 'prototype', 'en');

        $xpath = Html::xpath($this->get('/en/projects/prototype')->assertOk()->getContent());
        $this->assertSame('An idea you can hold.', Html::text($xpath->query('//main//h1')->item(0)));
        $this->assertSame(['01 / Task', '02 / Solution', '03 / Result'], Html::texts($xpath, '//div[@class="project-story"]//span'));
        $this->assertSame('A product needs its shape, mounts and assembly checked before production.',
            Html::text($xpath->query('//div[@class="project-story"]//p')->item(0)));
        $this->assertSame('Related services', Html::text($xpath->query('//div[@class="panel gap"]/h3')->item(0)));
        $this->assertSame([route('en.services.show', ['slug' => '3d-printing']), route('en.contact')],
            array_map(fn ($a) => $a->getAttribute('href'), iterator_to_array($xpath->query('//div[@class="panel gap"]//a'))));
        $this->assertSame(['3D printing ↗', 'Discuss a similar project'], Html::texts($xpath, '//div[@class="panel gap"]//a'));
        $this->assertSame(0, $xpath->query('//main//div[contains(@class, "notice")]')->length);
        $this->assertNull($xpath->query('//section[@class="page-top"]//p')->item(0));

        $html = $this->get('/en/projects/prototype')->getContent();
        $this->assertSame(route('en.projects.show', ['slug' => 'prototype']), PageHead::canonical($html));
        $this->assertSame(['lt' => route('lt.projects.show', ['slug' => 'prototype']), 'en' => route('en.projects.show', ['slug' => 'prototype']),
            'x-default' => route('lt.projects.show', ['slug' => 'prototype'])], PageHead::hreflang($html));
        foreach (['Užduotis', 'Susijusios paslaugos', 'Idėja, kurią'] as $lt) {
            $this->assertStringNotContainsString($lt, $html);
        }
        $this->get('/lt/projektai/prototype')->assertOk()->assertSee('01 / Užduotis')->assertSee('Susijusios paslaugos');
    }

    public function test_project_page_shows_the_youtube_link_only_when_the_project_has_one(): void
    {
        $this->publish(ContentType::Project, 'prototype', 'en');
        $youtube = fn (): array => array_map(fn ($a) => [$a->getAttribute('href'), $a->getAttribute('target'), $a->getAttribute('rel'), Html::text($a)],
            iterator_to_array(Html::xpath($this->get('/en/projects/prototype')->assertOk()->getContent())->query('//div[@class="panel gap"]//a[contains(@href, "youtube")]')));
        $this->assertSame([], $youtube());

        $item = $this->item(ContentType::Project, 'prototype');
        app(ContentService::class)->updateItem($this->admin(), $item, [...$item->meta, 'youtube' => 'https://www.youtube.com/watch?v=example'], $item->position);
        $this->assertSame([['https://www.youtube.com/watch?v=example', '_blank', 'noopener noreferrer', 'YouTube ↗']], $youtube());

        // Только https-адрес: javascript: и прочие схемы не выводятся.
        app(ContentService::class)->updateItem($this->admin(), $item, [...$item->meta, 'youtube' => 'javascript:alert(1)//youtube'], $item->position);
        $this->assertSame([], $youtube());
    }

    public function test_language_switcher_of_a_project_or_article_without_the_other_version_leads_to_the_section_list(): void
    {
        $this->publish(ContentType::Project, 'prototype', 'lt');
        $html = $this->get('/lt/projektai/prototype')->getContent();
        $this->assertSame(['lt' => route('lt.projects.show', ['slug' => 'prototype']), 'x-default' => route('lt.projects.show', ['slug' => 'prototype'])],
            PageHead::hreflang($html));
        $this->assertSame(route('en.projects'), Html::xpath($html)->query('//a[@hreflang="en"]')->item(0)->getAttribute('href'));

        $article = Html::xpath($this->get('/lt/biblioteka/scan-to-cad')->getContent());
        $this->assertSame(route('en.library.show', ['slug' => 'scan-to-cad']), $article->query('//a[@hreflang="en"]')->item(0)->getAttribute('href'));
        $this->unpublish(ContentType::Article, 'scan-to-cad', 'en');
        $this->assertSame(route('en.library'), Html::xpath($this->get('/lt/biblioteka/scan-to-cad')->getContent())->query('//a[@hreflang="en"]')->item(0)->getAttribute('href'));
        $this->get('/en/library/scan-to-cad')->assertNotFound();
        $this->assertNotContains('Scan mesh and CAD: what is the difference?', $this->articleTitles('/en/library'));
    }

    public function test_project_filters_by_service_and_application_with_an_empty_result(): void
    {
        foreach (['part-reconstruction', 'prototype', 'production-fixture'] as $key) {
            $this->publish(ContentType::Project, $key, 'en');
        }

        $this->assertSame(['A broken part. A new possibility.', 'An idea you can hold.', 'A fixture for easier daily work.'],
            $this->projectTitles('/en/projects'));
        $this->assertSame(['An idea you can hold.'], $this->projectTitles('/en/projects?service=printing'));
        $this->assertSame(['A fixture for easier daily work.'], $this->projectTitles('/en/projects?application=manufacturing'));
        $this->assertSame(['A broken part. A new possibility.'], $this->projectTitles('/en/projects?service=modelling&application=maintenance'));

        $xpath = Html::xpath($this->get('/en/projects?service=engraving')->assertOk()->getContent());
        $this->assertSame([], Html::texts($xpath, '//div[@id="project-results"]//h3[not(ancestor::div[@class="empty"])]'));
        $this->assertSame('No projects match these filters', Html::text($xpath->query('//div[@id="project-results"]//div[@class="empty"]/h3')->item(0)));
        $this->assertSame('Choose a different service or application.', Html::text($xpath->query('//div[@id="project-results"]//div[@class="empty"]/p')->item(0)));
        // Выбор сохраняется; варианты — опубликованные услуги локали и применения прототипа.
        $this->assertSame('engraving', $xpath->query('//select[@name="service"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame(['All services', '3D scanning', '3D modelling & reverse engineering', '3D printing', 'CNC machining', 'Engraving', '3D modelling training'],
            Html::texts($xpath, '//select[@name="service"]/option'));
        $this->assertSame(['All applications', 'Maintenance', 'Product development', 'Manufacturing'], Html::texts($xpath, '//select[@name="application"]/option'));
        // Карточка — услуга проекта своей локали, без демо-ярлыка.
        $card = Html::xpath($this->get('/en/projects?service=printing')->getContent());
        $this->assertSame(['3D printing', 'An idea you can hold.'], [Html::text($card->query('//a[contains(@class, "project-card")]//div[@class="card-body"]/p')->item(0)),
            Html::text($card->query('//a[contains(@class, "project-card")]//h3')->item(0))]);
    }

    public function test_every_article_lives_only_at_the_library_address_and_sections_affect_only_lists(): void
    {
        $this->factory()->news('workshop-update', ['Dirbtuvių atnaujinimas', 'Atnaujinome gamybos zoną.'], ['Workshop update', 'We updated the production area.'], 1);

        foreach ([...self::ARTICLES, 'workshop-update'] as $slug) {
            $this->get('/lt/biblioteka/'.$slug)->assertOk();
            $this->get('/en/library/'.$slug)->assertOk();
            foreach (['/lt/informacija/', '/lt/naujienos/', '/en/information/', '/en/news/'] as $duplicate) {
                $this->get($duplicate.$slug)->assertNotFound();
                $this->get($duplicate.$slug.'/')->assertNotFound();
            }
        }

        $library = ['How to prepare a file for 3D printing', 'How to choose a material for your part', 'Scan mesh and CAD: what is the difference?'];
        $this->assertSame($library, $this->articleTitles('/en/library'));
        $this->assertSame(['Workshop update'], $this->articleTitles('/en/news'));
        // «Информация» — все статьи по порядку элементов (новость с позицией 1 — после file-guide с той же позицией).
        $this->assertSame([$library[0], 'Workshop update', $library[1], $library[2]], $this->articleTitles('/en/information'));
        $news = Html::xpath($this->get('/lt/naujienos')->getContent());
        $this->assertSame(route('lt.library.show', ['slug' => 'workshop-update']), $news->query('//div[@id="resource-results"]/a')->item(0)->getAttribute('href'));
        $this->assertSame(['Visa informacija', 'Straipsniai', 'Biblioteka', 'DUK', 'Mokymai'], Html::texts($news, '//div[@class="tabs"]/a'));
        $this->assertSame(['Straipsniai'], Html::texts($news, '//div[@class="tabs"]/a[@class="active"]'));
        $this->assertSame(['BIBLIOTEKA / GAIRĖS'], array_slice(Html::texts(Html::xpath($this->get('/lt/biblioteka')->getContent()), '//div[@id="resource-results"]//span[@class="eyebrow"]'), 0, 1));
    }

    public function test_news_shows_the_placeholder_until_a_news_article_is_published_on_that_locale(): void
    {
        foreach (['/lt/naujienos' => 'Informacija ruošiama', '/en/news' => 'Information is being prepared'] as $url => $placeholder) {
            $xpath = Html::xpath($this->get($url)->assertOk()->getContent());
            $this->assertSame($placeholder, Html::text($xpath->query('//div[@class="wrap page-content"]/div[@class="empty"]/h3')->item(0)));
            $this->assertSame(0, $xpath->query('//*[@id="resource-results"]')->length);
        }

        $this->factory()->published(ContentType::Article, 'opening', ['lt' => ['title' => 'Atidarymas', 'slug' => 'atidarymas', 'fields' => ['intro' => 'Nauja dirbtuvė.']]],
            ['section' => 'news', 'category' => 'technology']);
        $this->assertSame(['Atidarymas'], $this->articleTitles('/lt/naujienos'));
        // Без EN-перевода статьи нет под /en/: ни в списках, ни по адресу (§4.2).
        $this->get('/en/news')->assertSee('Information is being prepared');
        $this->assertNotContains('Atidarymas', $this->articleTitles('/en/information'));
        $this->get('/en/library/atidarymas')->assertNotFound();
    }

    public function test_resource_search_and_category_filter_without_a_language_filter(): void
    {
        $xpath = Html::xpath($this->get('/en/information')->getContent());
        $this->assertSame(0, $xpath->query('//select[@name="resource-language"]')->length);
        $this->get('/en/information')->assertDontSee('Language')->assertDontSee('LT / EN');
        $this->assertSame(['All topics', 'Materials', 'File preparation', 'Technology'], Html::texts($xpath, '//select[@name="category"]/option'));
        $this->assertSame('Search resources...', $xpath->query('//input[@id="resource-search"]')->item(0)->getAttribute('placeholder'));

        $this->assertSame(['How to choose a material for your part'], $this->articleTitles('/en/information?category=materials'));
        $this->assertSame(['Scan mesh and CAD: what is the difference?'], $this->articleTitles('/en/information?q=MESH'));
        $this->assertSame(['How to prepare a file for 3D printing'], $this->articleTitles('/en/library?q=measurement%20units'));
        $this->assertSame(['Kaip pasirinkti medžiagą savo detalei?'], $this->articleTitles('/lt/biblioteka?q=spalvos&category=materials'));

        $empty = Html::xpath($this->get('/en/library?q=material&category=guides')->getContent());
        $this->assertSame('No resources found', Html::text($empty->query('//div[@id="resource-results"]/div[@class="empty"]/h3')->item(0)));
        $this->assertSame('material', $empty->query('//input[@id="resource-search"]')->item(0)->getAttribute('value'));
        $this->assertSame('guides', $empty->query('//select[@name="category"]/option[@selected]')->item(0)->getAttribute('value'));
    }

    public function test_article_card_keeps_the_limits_sentence_without_the_author_note_and_documents_panel_is_a_placeholder(): void
    {
        $lt = Html::xpath($this->get('/lt/biblioteka/file-guide')->assertOk()->getContent());
        $this->assertSame('Konkrečios gamybos ribos nustatomos pagal projektą.', Html::text($lt->query('//article[@class="article"]/div[@class="notice gap"]')->item(0)));
        $this->assertSame(['Pradėkite nuo paskirties', 'Patikrinkite mastelį', 'Pažymėkite svarbias vietas'], Html::texts($lt, '//article[@class="article"]/h2'));
        $this->assertSame('LT · AIQME informacinis tekstas', Html::text($lt->query('//article/span[@class="tag"]')->item(0)));
        $this->assertCount(0, $lt->query('//section[@class="page-top"]//div[@class="eyebrow"]')); // правка 30.10, Г2
        $en = Html::xpath($this->get('/en/library/file-guide')->getContent());
        $this->assertSame('Specific manufacturing limits are set for each project.', Html::text($en->query('//article[@class="article"]/div[@class="notice gap"]')->item(0)));
        $this->assertSame(route('en.contact'), $en->query('//article[@class="article"]/a[@class="btn"]')->item(0)->getAttribute('href'));

        foreach (['/lt/biblioteka/file-guide', '/lt/informacija', '/lt/biblioteka', '/lt/naujienos', '/en/library/file-guide', '/en/information', '/en/library', '/en/news'] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('Techniniai gamintojų dokumentai ir jų vertimai')->assertDontSee('Manufacturer technical documents and translations')
                ->assertDontSee('dar nepateikti')->assertDontSee('have not been provided');
        }
        foreach (['/lt/informacija' => ['Techniniai dokumentai', 'Informacija ruošiama'], '/en/library' => ['Technical documents', 'Information is being prepared']] as $url => [$title, $placeholder]) {
            $panel = Html::xpath($this->get($url)->getContent())->query('//div[@class="panel gap"]')->item(0);
            $this->assertStringStartsWith($title.' '.$placeholder, Html::text($panel));
        }
    }

    public function test_search_finds_published_services_articles_and_products_of_the_current_locale_only(): void
    {
        $this->factory()->published(ContentType::Product, 'filament', [
            'en' => ['title' => 'PETG filament spool', 'slug' => 'petg-filament'],
        ], ['category' => 'materials']);
        $this->factory()->published(ContentType::Article, 'only-lt', ['lt' => ['title' => 'Medžiagų atmintinė', 'slug' => 'medziagu-atmintine', 'fields' => ['intro' => 'Trumpai.']]],
            ['section' => 'library', 'category' => 'materials']);
        $draft = app(ContentService::class)->createItem($this->admin(), ContentType::Article, 'draft-material', ['section' => 'library']);
        app(ContentService::class)->saveDraft($this->admin(), $draft, 'en', ['title' => 'Material draft notes', 'slug' => 'material-draft', 'fields' => ['intro' => 'Draft.']], 0, Channel::Web);

        $results = fn (string $url): array => Html::texts(Html::xpath($this->get($url)->assertOk()->getContent()), '//div[@id="global-results"]/a/strong');

        // Услуга по short, статья по вводному абзацу, товар по названию и категории; черновик и LT-статья — нет.
        $this->assertSame(['PETG filament spool ↗', 'How to choose a material for your part ↗'], $results('/en/search?q=material'));
        // Порядок — услуги, товары, статьи (как searchAll прототипа).
        $this->assertSame(['3D modelling & reverse engineering ↗', '3D modelling training ↗', 'Scan mesh and CAD: what is the difference? ↗'],
            $results('/en/search?q=modelling'));
        $this->assertSame(['PETG filament spool ↗'], $results('/en/search?q=petg'));
        $this->assertSame(['CNC machining ↗'], $results('/en/search?q=machined%20from%20stock'));
        $this->assertSame(['PETG filament spool ↗'], $results('/en/search?q=MATERIALS'));
        $this->assertSame(['Medžiagų atmintinė ↗'], $results('/lt/paieska?q=atmintin'));
        $this->assertSame([], $results('/lt/paieska?q=PETG'));
        $this->assertSame([], $results('/en/search?q=atmintin'));

        $xpath = Html::xpath($this->get('/en/search?q=petg')->getContent());
        $this->assertSame(route('en.products.show', ['slug' => 'petg-filament']), $xpath->query('//div[@id="global-results"]/a')->item(0)->getAttribute('href'));
        $this->assertSame('Materials', Html::text($xpath->query('//div[@id="global-results"]/a/small')->item(0)));
        $service = Html::xpath($this->get('/lt/paieska?q=skenavimas')->getContent());
        $this->assertSame(route('lt.services.show', ['slug' => '3d-skenavimas']), $service->query('//div[@id="global-results"]/a')->item(0)->getAttribute('href'));

        $none = Html::xpath($this->get('/en/search?q=zzzz')->getContent());
        $this->assertSame('No results found', Html::text($none->query('//div[@id="global-results"]//h3')->item(0)));
        $this->assertSame('Try another search term or contact us.', Html::text($none->query('//div[@id="global-results"]//p')->item(0)));
        $start = Html::xpath($this->get('/en/search')->getContent());
        $this->assertSame('Try “modelling”, “PLA” or “file”.', Html::text($start->query('//div[@id="global-results"]/p')->item(0)));
        $this->assertSame('noindex, nofollow', PageHead::meta($this->get('/en/search?q=petg')->getContent(), 'name', 'robots'));
    }

    public function test_header_search_opens_the_search_modal_and_falls_back_to_the_search_page_without_js(): void
    {
        foreach (['/en' => ['en.search', 'What can we help you find?', 'Try “modelling”, “PLA” or “file”.'],
            '/lt/projektai' => ['lt.search', 'Kuo galime padėti?', 'Pabandykite „modeliavimas“, „PLA“ arba „failas“.']] as $path => [$route, $title, $hint]) {
            $xpath = Html::xpath($this->get($path)->assertOk()->getContent());
            $link = $xpath->query('//header//div[@class="tools"]/a[@data-modal-open]')->item(0);
            $this->assertSame([route($route), 'search-modal'], [$link->getAttribute('href'), $link->getAttribute('data-modal-open')], $path);

            $modal = $xpath->query('//div[@id="search-modal"][@data-modal][@hidden]')->item(0);
            $this->assertNotNull($modal, $path);
            $this->assertSame($title, Html::text($xpath->query('.//h2[@id="search-modal-title"]', $modal)->item(0)));
            $form = $xpath->query('.//form[@data-live-search][@method="get"]', $modal)->item(0);
            $this->assertSame(route($route), $form->getAttribute('action'));
            $this->assertSame('q', $xpath->query('.//input[@id="search-modal-input"]', $form)->item(0)->getAttribute('name'));
            $this->assertSame($hint, Html::text($xpath->query('.//div[@id="search-modal-results"]/p', $modal)->item(0)));
        }
    }

    public function test_search_returns_at_most_twelve_results(): void
    {
        foreach (range(1, 14) as $i) {
            $this->factory()->published(ContentType::Article, 'bulk-'.$i, ['en' => ['title' => 'Bulk note '.$i, 'slug' => 'bulk-'.$i, 'fields' => ['intro' => 'Text.']]],
                ['section' => 'library'], 10 + $i);
        }

        $this->assertSame(12, Html::xpath($this->get('/en/search?q=bulk')->getContent())->query('//div[@id="global-results"]/a')->length);
    }

    public function test_training_list_shows_published_topics_on_the_training_page_and_the_training_service(): void
    {
        foreach (['/lt/mokymai', '/en/training', '/lt/paslaugos/3d-modeliavimo-mokymai', '/en/services/3d-modelling-training'] as $url) {
            $xpath = Html::xpath($this->get($url)->assertOk()->getContent());
            $this->assertSame(1, $xpath->query('//div[@class="gap"]/div[@class="empty"]')->length, $url);
            $this->assertSame(0, $xpath->query('//div[@class="gap"]/div[@class="grid two"]')->length, $url);
            $this->get($url)->assertDontSee('ILLUSTRATIVE SCHEDULE')->assertDontSee('DEMONSTRACINIS TVARKARAŠTIS')->assertDontSee('2026-11-12');
        }
        $this->assertSame('Practical 3D modelling training', Html::text(Html::xpath($this->get('/en/training')->getContent())->query('//main//h1')->item(0)));

        $this->publish(ContentType::Training, 'topic-1', 'en');
        foreach (['/en/training', '/en/services/3d-modelling-training'] as $url) {
            $xpath = Html::xpath($this->get($url)->getContent());
            $panel = '//div[@class="gap"]/div[@class="grid two"]/div[@class="panel"]';
            $this->assertSame(['2026-11-12'], Html::texts($xpath, $panel.'/span[@class="tag blue"]'), $url);
            $this->assertSame(['Getting started with 3D modelling'], Html::texts($xpath, $panel.'/h3'));
            $this->assertSame(['Online · group'], Html::texts($xpath, $panel.'/p'));
            $this->assertSame(['Register for a session ↗'], Html::texts($xpath, $panel.'/a'));
            $this->assertSame(route('en.contact', ['service' => '3d-modelling-training']), $xpath->query($panel.'/a')->item(0)->getAttribute('href'));
            $this->assertSame(0, $xpath->query('//div[@class="gap"]/div[@class="empty"]')->length);
            $this->assertSame('A programme for you or your team', Html::text($xpath->query('//div[@class="inset gap"]/h3')->item(0)));
        }
        $this->get('/en/training')->assertDontSee('demo session');
        // LT не опубликован — LT-страница остаётся с заглушкой.
        $this->assertSame(1, Html::xpath($this->get('/lt/mokymai')->getContent())->query('//div[@class="gap"]/div[@class="empty"]')->length);
    }

    public function test_sitemap_has_articles_at_the_library_address_projects_and_sections_only_with_publications(): void
    {
        $locs = $this->sitemapLocs();
        foreach (self::ARTICLES as $slug) {
            $lt = route('lt.library.show', ['slug' => $slug]);
            $this->assertContains($lt, $locs);
            $this->assertSame(['lt' => $lt, 'en' => route('en.library.show', ['slug' => $slug]), 'x-default' => $lt], $this->sitemapAlternates($lt));
        }
        foreach (['/projektai', '/projects', '/naujienos', '/news', '/mokymai', '/training', '/informacija/', '/information/'] as $absent) {
            $this->assertEmpty(array_filter($locs, fn (string $loc): bool => str_contains($loc, $absent)), $absent);
        }
        $this->assertContains(route('lt.information'), $locs);
        $this->assertContains(route('en.library'), $locs);

        $this->publish(ContentType::Project, 'prototype', 'lt');
        $this->publish(ContentType::Project, 'prototype', 'en');
        $this->publish(ContentType::Training, 'topic-1', 'lt');
        $this->factory()->news('workshop-update', ['Dirbtuvės', 'Tekstas.'], ['Workshop', 'Text.'], 1);
        $locs = $this->sitemapLocs();
        $project = route('lt.projects.show', ['slug' => 'prototype']);
        $this->assertSame(['lt' => $project, 'en' => route('en.projects.show', ['slug' => 'prototype']), 'x-default' => $project], $this->sitemapAlternates($project));
        foreach (['lt.projects', 'en.projects', 'lt.news', 'en.news', 'lt.training'] as $name) {
            $this->assertContains(route($name), $locs, $name);
        }
        $this->assertNotContains(route('en.training'), $locs);
        $this->assertContains(route('lt.library.show', ['slug' => 'workshop-update']), $locs);
        $this->assertEmpty(array_filter($locs, fn (string $loc): bool => str_contains($loc, '/naujienos/') || str_contains($loc, '/news/')));
        foreach (['part-reconstruction', 'production-fixture'] as $draft) {
            $this->assertNotContains(route('lt.projects.show', ['slug' => $draft]), $locs);
        }
        foreach ($this->sitemapLocs() as $loc) {
            $this->assertSame($loc, PageHead::canonical($this->get($loc)->assertOk()->getContent()));
        }

        $this->unpublish(ContentType::Project, 'prototype', 'en');
        $this->unpublish(ContentType::Training, 'topic-1', 'lt');
        $locs = $this->sitemapLocs();
        $this->assertNotContains(route('en.projects.show', ['slug' => 'prototype']), $locs);
        $this->assertNotContains(route('en.projects'), $locs);
        $this->assertNotContains(route('lt.training'), $locs);
        $this->assertSame(['lt' => $project, 'x-default' => $project], $this->sitemapAlternates($project));
    }

    public function test_page_top_has_no_eyebrow_and_the_title_follows_the_breadcrumbs(): void
    {
        // Правка 30.10 (Г2): над h1 блока page-top ярлыка нет ни на одной странице; ярлыки разделов вне page-top остаются.
        $this->factory()->catalog();
        $pages = ['/lt/paslaugos', '/en/services', '/lt/produktai', '/lt/projektai', '/lt/informacija', '/lt/apie-mus', '/lt/kontaktai',
            '/lt/paslaugos/3d-skenavimas', '/lt/produktai/norda-s1-skeneris', '/lt/biblioteka/file-guide', '/lt/kalkuliatorius', '/lt/paskyra/register'];
        foreach ($pages as $path) {
            $xpath = Html::xpath($this->get($path)->assertOk()->getContent());
            $this->assertCount(1, $xpath->query('//section[@class="page-top"]'), $path);
            $this->assertCount(0, $xpath->query('//section[@class="page-top"]//*[contains(concat(" ", @class, " "), " eyebrow ")]'), $path);
            $next = $xpath->query('//section[@class="page-top"]/div[@class="wrap"]/div[@class="breadcrumbs"]/following-sibling::*[1]')->item(0);
            $this->assertSame('h1', $next?->nodeName, $path);
        }
        $about = Html::xpath($this->get('/lt/apie-mus')->getContent());
        $this->assertSame(['APIE AIQME'], Html::texts($about, '//main//div[@class="eyebrow"]'));
        $service = Html::xpath($this->get('/lt/paslaugos/3d-skenavimas')->getContent());
        $this->assertContains('KADA NAUDINGA', Html::texts($service, '//main//div[@class="eyebrow"]'));
    }
}
