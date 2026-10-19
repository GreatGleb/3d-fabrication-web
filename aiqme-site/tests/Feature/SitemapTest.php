<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\Support\PageHead;
use Tests\TestCase;

/** sitemap.xml и robots.txt (§4.4) без XSD и сети. */
class SitemapTest extends TestCase
{
    private const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';

    /** @return array<string, array<string, string>> loc => hreflang => href */
    private function sitemap(): array
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringStartsWith('application/xml', $response->headers->get('Content-Type'));

        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($response->getContent(), LIBXML_NONET), 'XML разбирается');
        $this->assertSame('urlset', $document->documentElement->localName);
        $this->assertSame(self::SITEMAP_NS, $document->documentElement->namespaceURI);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', self::SITEMAP_NS);
        $xpath->registerNamespace('x', self::XHTML_NS);

        $entries = [];
        foreach ($xpath->query('/s:urlset/s:url') as $url) {
            $loc = $xpath->evaluate('string(s:loc)', $url);
            $this->assertArrayNotHasKey($loc, $entries, 'адрес один раз: '.$loc);
            $entries[$loc] = [];
            foreach ($xpath->query('x:link[@rel="alternate"]', $url) as $link) {
                $entries[$loc][$link->getAttribute('hreflang')] = $link->getAttribute('href');
            }
        }

        return $entries;
    }

    public function test_sitemap_is_a_valid_urlset_of_absolute_app_urls(): void
    {
        $entries = $this->sitemap();

        $this->assertNotEmpty($entries);
        $this->assertLessThanOrEqual(50000, count($entries));
        foreach ($entries as $loc => $alternates) {
            $this->assertStringStartsWith(config('app.url').'/', $loc);
            foreach ($alternates as $href) {
                $this->assertStringStartsWith(config('app.url').'/', $href);
            }
        }
        $this->assertArrayHasKey(route('lt.home'), $entries);
        $this->assertArrayHasKey(route('en.calculator'), $entries);
    }

    public function test_sitemap_alternates_are_reciprocal_with_x_default_lithuanian(): void
    {
        $entries = $this->sitemap();

        foreach ($entries as $loc => $alternates) {
            $this->assertContains($loc, $alternates, 'адрес среди своих alternates');
            $this->assertSame($alternates['lt'], $alternates['x-default']);
            foreach (['lt', 'en'] as $locale) {
                $target = $alternates[$locale];
                $this->assertArrayHasKey($target, $entries, 'alternate есть в sitemap: '.$target);
                $this->assertSame($alternates, $entries[$target], 'взаимно: '.$loc.' ↔ '.$target);
            }
        }
    }

    public function test_sitemap_excludes_russian_account_admin_search_and_placeholder_sections(): void
    {
        $locs = array_keys($this->sitemap());
        $excluded = ['/ru', '/paskyra', '/account', '/administravimas', '/admin', '/paieska', '/search',
            '/produktai', '/products', '/naujienos', '/news', '/projektai', '/projects', '/mokymai', '/training', '/privatumas', '/privacy'];

        foreach ($locs as $loc) {
            $path = substr($loc, strlen(config('app.url')));
            foreach ($excluded as $prefix) {
                $this->assertFalse($path === $prefix || str_starts_with($path, $prefix.'/') || str_starts_with($path, '/lt'.$prefix) || str_starts_with($path, '/en'.$prefix), $loc);
            }
        }
    }

    public function test_every_sitemap_address_answers_200_with_itself_as_canonical(): void
    {
        foreach (array_keys($this->sitemap()) as $loc) {
            $html = $this->get($loc)->assertOk()->getContent();
            $this->assertSame($loc, PageHead::canonical($html));
            $this->assertSame('index, follow', PageHead::meta($html, 'name', 'robots'));
        }
    }

    public function test_robots_txt_points_to_sitemap_and_closes_nothing(): void
    {
        $response = $this->get('/robots.txt')->assertOk();

        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Sitemap: '.config('app.url')."/sitemap.xml\n", $response->getContent());
        $this->assertSame(['Disallow:'], array_values(preg_grep('/^Disallow/', explode("\n", $response->getContent()))));
    }
}
