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
use Tests\TestCase;

/**
 * Публичные страницы W-05 (§1.2, §4.1) на исходном контенте сидера W-04: главная, услуги, «О нас», контакты, FAQ, политики.
 */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    /** Услуги в порядке прототипа: ключ => [slug LT, slug EN]. */
    private const SERVICES = [
        'scanning' => ['3d-skenavimas', '3d-scanning'],
        'modelling' => ['3d-modeliavimas', '3d-modelling'],
        'printing' => ['3d-spausdinimas', '3d-printing'],
        'milling' => ['frezavimas', 'cnc-machining'],
        'engraving' => ['graviravimas', 'engraving'],
        'training' => ['3d-modeliavimo-mokymai', '3d-modelling-training'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeContentSeeder::class);
    }

    /** Единственный администратор (§2.4): второй строки is_admin БД не примет. */
    private function admin(): User
    {
        if ($admin = User::where('is_admin', true)->first()) {
            return $admin;
        }
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        return $admin;
    }

    private function item(ContentType $type, string $key): ContentItem
    {
        return ContentItem::where('type', $type)->where('key', $key)->firstOrFail();
    }

    /** Публикует новую версию перевода с изменёнными полями (как правка в админке). */
    private function republish(ContentItem $item, string $locale, array $fields): void
    {
        $content = app(ContentService::class);
        $admin = $this->admin();
        $draft = $item->translation($locale)->latestRevision();
        $saved = $content->saveDraft($admin, $item, $locale, [
            'title' => $draft->title, 'slug' => $draft->slug, 'fields' => array_replace($draft->fields, $fields),
        ], $draft->revision, Channel::Web);
        $content->publish($admin, $item, $locale, $saved->revision, Channel::Web);
    }

    public function test_home_has_hero_scenarios_process_services_categories_and_cta_from_content_without_the_scan_scene(): void
    {
        $xpath = Html::xpath($this->get('/en')->assertOk()->getContent());

        $this->assertSame('From your idea or part to a finished solution.', Html::text($xpath->query('//section[@class="hero"]//h1')->item(0)));
        $this->assertSame(['01SCAN', '02DESIGN', '03MANUFACTURE'], Html::texts($xpath, '//div[@class="stages"]/span'));
        $this->assertSame([route('en.calculator'), route('en.contact')], array_map(fn ($a) => $a->getAttribute('href'),
            iterator_to_array($xpath->query('//section[@class="hero"]//div[@class="buttons"]/a'))));
        $this->assertSame(
            [route('en.services.show', ['slug' => '3d-modelling']), route('en.calculator'), route('en.products')],
            array_map(fn ($a) => $a->getAttribute('href'), iterator_to_array($xpath->query('//div[@class="scenario"]/a'))),
        );
        $this->assertSame(['Your challenge', 'Agreed solution', 'Reviewed production', 'The result for you'],
            Html::texts($xpath, '//section[@class="section dark"]//div[@class="process"]/div/h3'));
        $this->assertSame(array_map(fn (array $slugs) => route('en.services.show', ['slug' => $slugs[1]]), array_values(self::SERVICES)),
            array_map(fn ($a) => $a->getAttribute('href'), iterator_to_array($xpath->query('//div[@class="service-feature-actions"]/a[contains(@class, "btn")]'))));
        $this->assertSame(route('en.products', ['category' => 'scanners']), $xpath->query('//a[@class="category-row"]')->item(0)->getAttribute('href'));
        $this->assertCount(5, $xpath->query('//a[@class="category-row"]'));
        $this->assertSame('Your next step is simple.', Html::text($xpath->query('//section[@class="cta-band"]//h2')->item(0)));

        // Сцены «СКАН → CAD → ДЕТАЛЬ» и крыльчатки нет (D-40): строка этапов — только текст.
        $html = $this->get('/en')->getContent();
        foreach (['gear.js', 'scene', 'impeller', '<canvas'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    public function test_service_cards_start_with_the_title_and_keep_the_number_in_the_media_corner(): void
    {
        // Правка 28.10 (П4): подписи «● NN / label» над названием нет; поле label в данных остаётся.
        foreach (['/en', '/lt', '/lt/paslaugos'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $xpath = Html::xpath($html);
            $this->assertCount(0, $xpath->query('//div[@class="service-feature-copy"]/div[@class="eyebrow"]'), $path);
            $this->assertSame(['01', '02', '03', '04', '05', '06'], Html::texts($xpath, '//span[@class="service-feature-number"]'), $path);
            $first = array_map(fn ($node) => $node->nodeName, iterator_to_array($xpath->query('//div[@class="service-feature-copy"]/*[1]')));
            $this->assertSame(array_fill(0, 6, 'h3'), $first, $path);
            if (str_starts_with($path, '/lt')) {
                $this->assertStringNotContainsString('VIENETINIAI GAMINIAI, PROTOTIPAI IR SERIJINĖ GAMYBA', $html, $path);
            }
        }
    }

    public function test_home_brands_and_news_show_a_placeholder_until_published_then_a_ribbon_of_news_only(): void
    {
        $xpath = Html::xpath($this->get('/lt')->getContent());
        foreach (['partner-section', 'news-section'] as $section) {
            $this->assertSame('Informacija ruošiama', Html::text($xpath->query('//section['.Html::hasClass($section).']//div[@class="empty"]/h3')->item(0)));
        }
        $this->assertCount(0, $xpath->query('//div[@class="partner-marquee" or @class="news-marquee"]'));
        // Статьи библиотеки на главной не выводятся (D-26).
        $this->assertStringNotContainsString('Kaip paruošti failą 3D spausdinimui?', $this->get('/lt')->getContent());

        ContentFactory::as($this->admin())->homeTickers();
        foreach (['lt' => ['Prekių ženklai', 'Planuojami prekių ženklai'], 'en' => ['Technology brands', 'Planned technology brands']] as $locale => [$label, $old]) {
            $html = $this->get('/'.$locale)->getContent();
            $xpath = Html::xpath($html);
            $this->assertSame($label, $xpath->query('//div[@class="partner-marquee-group" and @role="list"]')->item(0)->getAttribute('aria-label'));
            $this->assertStringNotContainsString($old, $html);
            $this->assertSame(['NORDA', 'VEKTA'], Html::texts($xpath, '//div[@class="partner-marquee-group" and @role="list"]/div'));
            $this->assertCount(1, $xpath->query('//div[@class="partner-marquee-group" and @aria-hidden="true"]'));
            $this->assertCount(2, $xpath->query('//div[@class="news-marquee-group" and @role="list"]/a'));
            $this->assertCount(0, $xpath->query('//section['.Html::hasClass('news-section').']//div[@class="empty"]'));
        }
        $this->assertStringNotContainsString('How to prepare a file for 3D printing', $this->get('/en')->getContent());
    }

    public function test_services_list_and_each_service_by_its_own_locale_slug(): void
    {
        $xpath = Html::xpath($this->get('/lt/paslaugos')->assertOk()->getContent());
        $this->assertSame(['3D skenavimas', '3D modeliavimas ir detalių atkūrimas', '3D spausdinimas', 'Frezavimas', 'Graviravimas', '3D modeliavimo mokymai'],
            Html::texts($xpath, '//div[@class="service-features"]//h3'));

        foreach (self::SERVICES as [$lt, $en]) {
            $this->get('/lt/paslaugos/'.$lt)->assertOk();
            $this->get('/en/services/'.$en)->assertOk();
            $this->get('/en/services/'.$lt)->assertNotFound();
            $this->get('/lt/paslaugos/'.$en)->assertNotFound();
        }
    }

    public function test_service_page_uses_shared_process_its_faq_then_services_group_and_no_example_while_projects_are_drafts(): void
    {
        $this->republish($this->item(ContentType::PageText, 'home'), 'en', ['process_steps' => [
            ['title' => 'Changed step', 'text' => 'Shared process text.'],
        ]]);
        $html = $this->get('/en/services/3d-scanning')->assertOk()->getContent();
        $xpath = Html::xpath($html);

        $this->assertCount(0, $xpath->query('//section[@class="page-top"]//div[@class="eyebrow"]')); // правка 30.10, Г2
        $this->assertSame('Turn a physical part into a digital 3D model.', Html::text($xpath->query('//section[@class="page-top"]//p')->item(0)));
        $this->assertSame(['Changed step'], Html::texts($xpath, '//div[@class="section dark panel"]//h3'));
        $this->assertSame(['Is a scan immediately ready for manufacturing?', 'What determines the lead time?'], Html::texts($xpath, '//details[@class="faq"]/summary'));
        $this->assertSame(['One point of contact for the whole job.'], Html::texts($xpath, '//div[@class="grid two"]/div[@class="panel"]/h3'));
        $this->assertStringNotContainsString('Application example', $html);
        $this->assertStringNotContainsString('project-card', $html);
        $this->assertStringNotContainsString('Illustrative image', $html);
        $this->assertSame(route('en.contact', ['service' => '3d-scanning']), $xpath->query('//div[@class="split"]//a[contains(@class, "btn")]')->item(0)->getAttribute('href'));
        $this->assertSame(route('en.calculator'), Html::xpath($this->get('/en/services/3d-printing')->getContent())
            ->query('//div[@class="split"]//a[contains(@class, "btn")]')->item(0)->getAttribute('href'));
    }

    public function test_service_pages_do_not_link_draft_projects_and_show_the_example_once_a_project_is_published(): void
    {
        foreach (self::SERVICES as [$lt, $en]) {
            $this->get('/lt/paslaugos/'.$lt)->assertDontSee('/lt/projektai/', false);
            $this->get('/en/services/'.$en)->assertDontSee('/en/projects/', false);
        }

        $project = $this->item(ContentType::Project, 'part-reconstruction');
        app(ContentService::class)->publish($this->admin(), $project, 'en', $project->translation('en')->revision, Channel::Web);

        $xpath = Html::xpath($this->get('/en/services/3d-modelling')->getContent());
        $this->assertSame('Application example', Html::text($xpath->query('//div[@class="wrap page-content"]/div[@class="section-head"]/h2')->item(0)));
        $card = $xpath->query('//div[@class="grid two"]/a[@class="card project-card"]')->item(0);
        $this->assertSame('A broken part. A new possibility.', Html::text($xpath->query('.//h3', $card)->item(0)));
        $this->assertSame('3D modelling & reverse engineering', Html::text($xpath->query('.//div[@class="card-body"]/p', $card)->item(0)));
        // Пример — только у услуг проекта; LT-перевод проекта не опубликован — на LT примера нет.
        $this->get('/en/services/3d-scanning')->assertDontSee('Application example');
        $this->get('/lt/paslaugos/3d-modeliavimas')->assertDontSee('Pritaikymo pavyzdys');
    }

    public function test_training_service_has_topics_block_with_placeholder_and_the_shared_training_intro_panel(): void
    {
        $this->republish($this->item(ContentType::PageText, 'training'), 'lt', ['intro_title' => 'Programa jūsų komandai']);
        $html = $this->get('/lt/paslaugos/3d-modeliavimo-mokymai')->assertOk()->getContent();
        $xpath = Html::xpath($html);

        // Блок тем и дат — сразу после первого div.grid.two.gap (§1.2).
        $this->assertSame('gap', $xpath->query('//div[@class="wrap page-content"]/div[@class="grid two gap"]/following-sibling::*[1]')->item(0)->getAttribute('class'));
        $topics = $xpath->query('//div[@class="wrap page-content"]/div[@class="gap"]')->item(0);
        $this->assertSame('Mokymų temos ir datos', Html::text($xpath->query('.//h2', $topics)->item(0)));
        $this->assertSame('Informacija ruošiama', Html::text($xpath->query('.//div[@class="empty"]/h3', $topics)->item(0)));
        $this->assertSame('Programa jūsų komandai', Html::text($xpath->query('.//div[@class="inset gap"]/h3', $topics)->item(0)));
        $this->assertSame(route('lt.contact'), $xpath->query('.//div[@class="inset gap"]/a', $topics)->item(0)->getAttribute('href'));
        foreach (['DEMONSTRACINIS TVARKARAŠTIS', '2026-11-12', '3D modeliavimo pradžia', 'demonstracin'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    public function test_faq_page_lists_service_groups_in_service_order_then_general_without_the_services_group(): void
    {
        $xpath = Html::xpath($this->get('/en/faq')->assertOk()->getContent());

        $this->assertSame([
            'Is a scan immediately ready for manufacturing?', 'Can we start from a photo?', 'Which process should I choose?',
            'Can you use my stock material?', 'Can you prepare the layout?', 'Can you train our team on site?',
            'Do I need an account to order?', 'Can I collect in person?',
        ], Html::texts($xpath, '//div[@class="wrap page-content article"]/details[@class="faq"]/summary'));

        $this->item(ContentType::Service, 'milling')->update(['position' => 0]);
        $this->assertSame('Can you use my stock material?', Html::texts(Html::xpath($this->get('/en/faq')->getContent()), '//details/summary')[0]);
    }

    public function test_about_has_intro_and_panels_without_team_block_or_author_note(): void
    {
        foreach (['/lt/apie-mus' => 'Žmonės už sprendimų', '/en/about' => 'The people behind the solutions'] as $path => $team) {
            $html = $this->get($path)->assertOk()->getContent();
            $xpath = Html::xpath($html);
            $this->assertCount(3, $xpath->query('//div[@class="grid three gap"]/div[@class="panel"]'));
            $this->assertCount(0, $xpath->query('//div[@class="inset gap"]'));
            $this->assertSame(asset('defaults/engineering.jpg'), $xpath->query('//div[@class="split-image"]/img')->item(0)->getAttribute('src'));
            foreach ([$team, 'Čia bus pateiktos tikros komandos', 'Real team photographs', 'išgalvotų', 'fictional', 'Demonstracinis vaizdas', 'Illustrative image'] as $absent) {
                $this->assertStringNotContainsString($absent, $html);
            }
        }
    }

    public function test_contact_page_has_details_embedded_map_with_link_and_enquiry_form_without_demo_lines(): void
    {
        // Правка 28.10 П5: спутниковая карта Google Maps (без ключа, язык страницы) сразу при открытии, ссылка — под ней.
        foreach (['/en/contact' => ['en', 'AIQME location map', 'Open map ↗'], '/lt/kontaktai' => ['lt', 'AIQME vieta žemėlapyje', 'Atidaryti žemėlapį ↗']] as $path => [$locale, $title, $link]) {
            $xpath = Html::xpath($this->get($path)->assertOk()->getContent());
            $this->assertCount(1, $xpath->query('//iframe'), $path);
            $iframe = $xpath->query('//div[@class="contact-map"]/iframe')->item(0);
            $this->assertNotNull($iframe, $path);
            $src = $iframe->getAttribute('src');
            $this->assertSame(trans('public.contact.map_embed_url', [], $locale), $src, $path);
            $this->assertStringStartsWith('https://www.google.com/maps?', $src, $path);
            $this->assertStringContainsString('output=embed', $src, $path);
            $this->assertStringContainsString('&hl='.$locale.'&', $src, $path);
            $this->assertSame($title, $iframe->getAttribute('title'), $path);
            $this->assertFalse($iframe->hasAttribute('loading'), $path);
            $this->assertFalse($iframe->hasAttribute('referrerpolicy'), $path);
            $anchor = $xpath->query('//div[@class="contact-map"]/iframe/following-sibling::a')->item(0);
            $this->assertNotNull($anchor, "$path: ссылка после карты");
            $this->assertSame($link, Html::text($anchor), $path);
            $this->assertSame('https://www.google.com/maps?q=54.641192%2C25.268099', $anchor->getAttribute('href'), $path);
        }

        $html = $this->get('/en/contact')->assertOk()->getContent();
        $xpath = Html::xpath($html);

        $this->assertSame('tel:+37068884084', $xpath->query('//ul[contains(@class, "contact-details")]//a')->item(0)->getAttribute('href'));
        $this->assertSame('mailto:info@aiqme.com', $xpath->query('//ul[contains(@class, "contact-details")]//a')->item(1)->getAttribute('href'));
        $this->assertCount(5, $xpath->query('//ul[contains(@class, "contact-details")]/li'));
        $this->assertCount(2, $xpath->query('//ul[contains(@class, "contact-details")]//a'), 'код компании и PVM — текст, не tel:');
        $this->assertSame('Open map ↗', Html::text($xpath->query('//div[@class="contact-map"]/a')->item(0)));
        $this->assertSame(['name', 'company', 'email', 'phone', 'service', 'description', 'attachments[]', 'deadline', 'website_hp'],
            array_map(fn ($field) => $field->getAttribute('name'), iterator_to_array($xpath->query('//form[@id="contact-form"]//*[@name and @name != "_token"]'))));
        $this->assertSame(['Choose a service', '3D scanning', '3D modelling & reverse engineering', '3D printing', 'CNC machining', 'Engraving', '3D modelling training', 'Equipment quotation'],
            Html::texts($xpath, '//select[@name="service"]/option'));
        $this->assertSame('Send enquiry', Html::text($xpath->query('//form[@id="contact-form"]//button[@type="submit"]')->item(0)));
        $this->assertSame('* — required fields.', Html::text($xpath->query('//form[@id="contact-form"]/p')->item(0)));
        $this->assertCount(0, $xpath->query('//input[@type="checkbox"]'));
    }

    public function test_policies_show_a_placeholder_while_drafts_and_the_published_text_afterwards(): void
    {
        $html = $this->get('/en/privacy')->assertOk()->getContent();
        $this->assertSame('Information is being prepared', Html::text(Html::xpath($html)->query('//div[@class="wrap page-content article"]//h3')->item(0)));
        foreach (['Illustrative policy area', 'This is not a final set', 'Your files are not public', 'Clear this demonstration'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }

        $policy = $this->item(ContentType::PageText, 'delivery');
        $this->republish($policy, 'lt', ['intro' => 'Pristatymo sąlygos.', 'notice' => '', 'sections' => [['heading' => 'Pristatymas', 'text' => 'Siunčiame **visoje** ES.']]]);
        $xpath = Html::xpath($this->get('/lt/pristatymas')->getContent());
        $this->assertSame('Pristatymo sąlygos.', Html::text($xpath->query('//section[@class="page-top"]//p')->item(0)));
        $this->assertSame('Pristatymas', Html::text($xpath->query('//div[@class="wrap page-content article"]/h2')->item(0)));
        $this->assertSame('visoje', Html::text($xpath->query('//div[@class="wrap page-content article"]/p/strong')->item(0)));
        $this->get('/en/delivery')->assertSee('Information is being prepared');
    }
}
