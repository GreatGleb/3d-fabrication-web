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

/** SEO публичных страниц W-05 (§4.4, §7.2, F-45): SEO-поля, перенос в заголовке, описание, Open Graph, hreflang, sitemap. */
class PublicSeoTest extends TestCase
{
    use RefreshDatabase;

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

    private function title(string $html): string
    {
        return preg_match('~<title>(.*?)</title>~s', $html, $match) ? html_entity_decode($match[1]) : '';
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
        preg_match('~<url>\s*<loc>'.preg_quote($loc, '~').'</loc>(.*?)</url>~s', $xml, $url);
        preg_match_all('~hreflang="([^"]+)" href="([^"]+)"~', $url[1] ?? '', $links, PREG_SET_ORDER);

        return array_column(array_map(fn (array $m): array => [$m[1], $m[2]], $links), 1, 0);
    }

    public function test_seo_fields_of_the_translation_are_applied_to_title_description_and_open_graph(): void
    {
        $content = app(ContentService::class);
        $admin = $this->admin();
        $service = $this->item(ContentType::Service, 'scanning');
        $draft = $service->translation('en')->latestRevision();
        $saved = $content->saveDraft($admin, $service, 'en', ['title' => $draft->title, 'slug' => $draft->slug, 'fields' => $draft->fields,
            'seo_title' => 'Industrial 3D scanning in Vilnius', 'seo_description' => 'Scanning of parts for reconstruction.'], $draft->revision, Channel::Web);
        $content->publish($admin, $service, 'en', $saved->revision, Channel::Web);

        $html = $this->get('/en/services/3d-scanning')->getContent();
        $this->assertSame('Industrial 3D scanning in Vilnius | AIQME', $this->title($html));
        $this->assertSame('Scanning of parts for reconstruction.', PageHead::meta($html, 'name', 'description'));
        $this->assertSame('Industrial 3D scanning in Vilnius', PageHead::meta($html, 'property', 'og:title'));
        $this->assertSame('Scanning of parts for reconstruction.', PageHead::meta($html, 'property', 'og:description'));
        $this->assertSame('3D scanning', Html::text(Html::xpath($html)->query('//h1')->item(0)), 'h1 — название, не SEO-заголовок');

        // Без SEO-полей: заголовок страницы и вводный абзац (short), не слоган подвала.
        $html = $this->get('/lt/paslaugos/3d-skenavimas')->getContent();
        $this->assertSame('3D skenavimas | AIQME', $this->title($html));
        $this->assertSame('Tikra detalė tampa skaitmeniniu 3D modeliu.', PageHead::meta($html, 'name', 'description'));
        $this->assertSame('Tikra detalė tampa skaitmeniniu 3D modeliu.', PageHead::meta($html, 'property', 'og:description'));

        $page = $this->item(ContentType::PageText, 'home');
        $draft = $page->translation('lt')->latestRevision();
        $saved = $content->saveDraft($admin, $page, 'lt', ['title' => $draft->title, 'fields' => $draft->fields, 'seo_title' => 'AIQME Vilniuje'], $draft->revision, Channel::Web);
        $content->publish($admin, $page, 'lt', $saved->revision, Channel::Web);
        $html = $this->get('/lt')->getContent();
        $this->assertSame('AIQME Vilniuje | AIQME', $this->title($html));
        $this->assertStringStartsWith('Skenuojame, projektuojame ir koordinuojame gamybą.', PageHead::meta($html, 'name', 'description'));
    }

    public function test_heading_with_a_line_break_is_br_in_h1_and_a_space_in_breadcrumbs_and_title_and_about_description_is_its_intro(): void
    {
        $html = $this->get('/en/about')->getContent();
        $xpath = Html::xpath($html);

        $this->assertStringContainsString('<h1>Engineering starts<br>'."\n".'with understanding.</h1>', $html);
        $this->assertSame('Engineering starts with understanding.', Html::text($xpath->query('//div[@class="breadcrumbs"]/span[last()]')->item(0)));
        $this->assertSame('Engineering starts with understanding. | AIQME', $this->title($html));
        $this->assertStringNotContainsString('&lt;br', $html);

        $description = PageHead::meta($html, 'name', 'description');
        $this->assertStringStartsWith('AIQME helps businesses, creators, education organisations', $description);
        $this->assertNotSame(__('shell.footer.slogan', [], 'en'), $description, 'не слоган подвала (§7.2)');
    }

    public function test_service_hreflang_and_switcher_use_only_published_versions(): void
    {
        $html = $this->get('/lt/paslaugos/3d-skenavimas')->getContent();
        $this->assertSame([
            'lt' => route('lt.services.show', ['slug' => '3d-skenavimas']),
            'en' => route('en.services.show', ['slug' => '3d-scanning']),
            'x-default' => route('lt.services.show', ['slug' => '3d-skenavimas']),
        ], PageHead::hreflang($html));
        $this->assertSame(route('lt.services.show', ['slug' => '3d-skenavimas']), PageHead::canonical($html));

        $service = $this->item(ContentType::Service, 'scanning');
        app(ContentService::class)->unpublish($this->admin(), $service, 'en', $service->translation('en')->revision, Channel::Web);

        $html = $this->get('/lt/paslaugos/3d-skenavimas')->getContent();
        $this->assertSame(['lt', 'x-default'], array_keys(PageHead::hreflang($html)));
        // Переключатель ведёт на список услуг EN, а не на LT-адрес с префиксом EN.
        $this->assertSame(route('en.services'), Html::xpath($html)->query('//a[@hreflang="en"]')->item(0)->getAttribute('href'));
        $this->get('/en/services/3d-scanning')->assertNotFound();
    }

    public function test_sitemap_has_published_services_with_alternates_and_follows_publication_of_a_translation(): void
    {
        $lt = route('lt.services.show', ['slug' => '3d-skenavimas']);
        $en = route('en.services.show', ['slug' => '3d-scanning']);
        $locs = $this->sitemapLocs();
        $this->assertContains($lt, $locs);
        $this->assertContains($en, $locs);
        $this->assertSame(['lt' => $lt, 'en' => $en, 'x-default' => $lt], $this->sitemapAlternates($lt));
        foreach (['privatumas', 'privacy', 'slapukai', 'pristatymas', 'grazinimas', '/projektai/', '/projects/'] as $draft) {
            $this->assertEmpty(array_filter($locs, fn (string $loc): bool => str_contains($loc, $draft)), 'черновик не в sitemap: '.$draft);
        }

        $content = app(ContentService::class);
        $admin = $this->admin();
        $service = ContentFactory::as($admin)->published(ContentType::Service, 'metrology', [
            'lt' => ['title' => 'Matavimai', 'slug' => 'matavimai', 'fields' => ['short' => 'Detalių matavimai.']],
        ], position: 7);
        $newLt = route('lt.services.show', ['slug' => 'matavimai']);
        $newEn = route('en.services.show', ['slug' => 'measurement']);
        $this->assertSame(['lt' => $newLt, 'x-default' => $newLt], $this->sitemapAlternates($newLt));
        $this->assertNotContains($newEn, $this->sitemapLocs());

        $saved = $content->saveDraft($admin, $service, 'en', ['title' => 'Measurement', 'slug' => 'measurement', 'fields' => ['short' => 'Part measurement.']], 0, Channel::Web);
        $published = $content->publish($admin, $service, 'en', $saved->revision, Channel::Web);
        $this->assertContains($newEn, $this->sitemapLocs());
        $this->assertSame(['lt' => $newLt, 'en' => $newEn, 'x-default' => $newLt], $this->sitemapAlternates($newLt));
        $this->assertSame(['lt' => $newLt, 'en' => $newEn, 'x-default' => $newLt], $this->sitemapAlternates($newEn));

        $content->unpublish($admin, $service, 'en', $published->revision, Channel::Web);
        $this->assertNotContains($newEn, $this->sitemapLocs());
        $this->assertSame(['lt' => $newLt, 'x-default' => $newLt], $this->sitemapAlternates($newLt));
    }

    public function test_published_policy_enters_the_sitemap_only_on_its_locale(): void
    {
        $content = app(ContentService::class);
        $policy = $this->item(ContentType::PageText, 'privacy');
        $content->publish($this->admin(), $policy, 'lt', $policy->translation('lt')->revision, Channel::Web);

        $this->assertContains(route('lt.privacy'), $this->sitemapLocs());
        $this->assertNotContains(route('en.privacy'), $this->sitemapLocs());
    }
}
