<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Seo\StaticPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Support\PageHead;
use Tests\TestCase;

/** Локали, /ru, 404/500, SEO-head и переключатель языка (W-02, §4.3, §4.4). */
class LocaleRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        return $admin;
    }

    public function test_root_redirects_permanently_to_lithuanian_home(): void
    {
        $this->get('/')->assertStatus(301)->assertRedirect(config('app.url').'/lt');
    }

    public function test_every_static_page_exists_on_both_public_locales_with_its_own_segment(): void
    {
        foreach (array_keys(StaticPages::PAGES) as $page) {
            $this->get(route('lt.'.$page))->assertOk()->assertSee('<html lang="lt">', escape: false)
                ->assertSee('<h1>'.e(__('pages.'.$page.'.title', [], 'lt')).'</h1>', escape: false);
            $this->get(route('en.'.$page))->assertOk()->assertSee('<html lang="en">', escape: false)
                ->assertSee('<h1>'.e(__('pages.'.$page.'.title', [], 'en')).'</h1>', escape: false);
        }
        $this->assertSame(config('app.url').'/lt/kalkuliatorius', route('lt.calculator'));
        $this->assertSame(config('app.url').'/en/quote', route('en.calculator'));
    }

    public function test_segment_of_the_other_locale_is_not_found(): void
    {
        foreach (['/en/paslaugos', '/lt/services', '/en/kalkuliatorius', '/lt/quote', '/en/apie-mus', '/lt/about'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_unknown_address_is_a_real_404_in_the_language_of_the_path_not_the_home_page(): void
    {
        $lithuanianHome = __('pages.home.title', [], 'lt');

        foreach (['/xyz', '/lt/nera-tokio', '/lt/paslaugos/nera-tokios'] as $path) {
            $this->get($path)->assertNotFound()
                ->assertSee('Puslapis nerastas')
                ->assertDontSee($lithuanianHome)
                ->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false)
                ->assertDontSee('rel="canonical"', escape: false);
        }
        $this->get('/en/no-such-page')->assertNotFound()
            ->assertSee('<html lang="en">', escape: false)
            ->assertSee('Page not found')
            ->assertSee('href="'.route('en.home').'"', escape: false);
    }

    public function test_server_error_page_exists_on_both_locales(): void
    {
        config(['app.debug' => false]);
        foreach (['lt' => 'Serverio klaida', 'en' => 'Server error'] as $locale => $title) {
            Route::get($locale.'/w02-broken', fn () => throw new RuntimeException('broken'));
            $this->get('/'.$locale.'/w02-broken')->assertStatus(500)
                ->assertSee('<html lang="'.$locale.'">', escape: false)
                ->assertSee('<h1>'.$title.'</h1>', escape: false)
                ->assertDontSee('broken');
        }
    }

    public function test_russian_pages_are_not_found_for_guest_and_customer(): void
    {
        $customer = User::factory()->create();
        $unknown = $this->get('/lt/nera-tokio')->getContent();

        foreach (['/ru', '/ru/', '/ru/services', '/ru/quote'] as $path) {
            $guest = $this->get($path)->assertNotFound();
            $this->assertSame($unknown, $guest->getContent(), 'как любой неизвестный адрес: '.$path);
            $this->actingAs($customer)->get($path)->assertNotFound();
            auth()->logout();
        }
    }

    public function test_russian_pages_for_admin_are_noindex_and_show_english_values_not_keys(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->get('/ru/services/')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<html lang="ru">', escape: false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false)
            ->assertSee('<title>Services for your ideas and parts. | AIQME</title>', escape: false)
            ->assertDontSee('pages.');

        $this->assertSame([], PageHead::hreflang($response->getContent()), 'RU нет в hreflang');
        $this->assertSame(route('ru.services'), PageHead::canonical($response->getContent()));
        // RU нет в переключателе (§4.3): он ведёт на LT; ссылки шапки и подвала на /ru остаются внутри RU для администратора.
        $this->assertStringContainsString('<a href="'.route('lt.services').'" hreflang="lt"', $response->getContent());
        $this->assertStringNotContainsString('hreflang="ru"', $response->getContent());
        $this->actingAs($admin)->get('/ru')->assertOk()->assertSee('From your idea or part to a finished solution.');
    }

    public function test_public_page_head_has_title_description_canonical_hreflang_robots_and_open_graph(): void
    {
        $html = $this->get('/lt/kontaktai/')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Papasakokite, ką norite sukurti. | AIQME</title>', $html);
        $description = 'Pradžiai pakanka idėjos, nuotraukos ar failo. Padėsime suprasti kitą žingsnį.';
        $this->assertSame($description, PageHead::meta($html, 'name', 'description'));
        $this->assertSame('index, follow', PageHead::meta($html, 'name', 'robots'));
        $this->assertSame(route('lt.contact'), PageHead::canonical($html));
        $this->assertSame(['lt' => route('lt.contact'), 'en' => route('en.contact'), 'x-default' => route('lt.contact')], PageHead::hreflang($html));
        $this->assertSame('Papasakokite, ką norite sukurti.', PageHead::meta($html, 'property', 'og:title'));
        $this->assertSame($description, PageHead::meta($html, 'property', 'og:description'));
        $this->assertNull(PageHead::meta($html, 'property', 'og:image'), 'у страницы нет медиа');

        $english = $this->get('/en/contact')->getContent();
        $this->assertSame(route('en.contact'), PageHead::canonical($english));
        $this->assertSame(PageHead::hreflang($html), PageHead::hreflang($english), 'hreflang взаимный');
    }

    public function test_page_without_own_description_uses_site_description_not_demo_text(): void
    {
        $html = $this->get('/lt/privatumas')->getContent();
        $this->assertSame('AIQME — vienas partneris nuo užduoties iki produkto.', PageHead::meta($html, 'name', 'description'));
        $this->assertStringNotContainsString('Demonstracin', $html);
        $this->assertStringNotContainsString('Illustrative', $this->get('/en/quote')->getContent());
    }

    public function test_search_page_is_noindex_without_hreflang(): void
    {
        $html = $this->get('/en/search')->assertOk()->getContent();
        $this->assertSame('noindex, nofollow', PageHead::meta($html, 'name', 'robots'));
        $this->assertSame([], PageHead::hreflang($html));
    }

    public function test_seo_head_outputs_only_given_alternates_and_open_graph_image_when_present(): void
    {
        $html = (string) $this->blade(
            '<x-seo-head title="T" description="D" canonical="https://x.test/lt/a" :alternates="[\'lt\' => \'https://x.test/lt/a\']" image="https://x.test/a.jpg" />'
        );

        $this->assertSame(['lt' => 'https://x.test/lt/a', 'x-default' => 'https://x.test/lt/a'], PageHead::hreflang($html));
        $this->assertSame('https://x.test/a.jpg', PageHead::meta($html, 'property', 'og:image'));

        $withoutLithuanian = (string) $this->blade('<x-seo-head title="T" description="D" :alternates="[\'en\' => \'https://x.test/en/a\']" />');
        $this->assertSame(['en' => 'https://x.test/en/a'], PageHead::hreflang($withoutLithuanian), 'x-default только при LT-версии');
    }

    public function test_language_switcher_links_to_the_same_page_in_the_other_locale(): void
    {
        $this->get('/lt/paslaugos/')->assertSee('<a href="'.route('en.services').'" hreflang="en" aria-label="Switch to English"', escape: false);
        $this->get('/en/services')->assertSee('<a href="'.route('lt.services').'" hreflang="lt" aria-label="Keisti į lietuvių kalbą"', escape: false);
        $this->get('/en/missing')->assertSee('<a href="'.route('lt.home').'" hreflang="lt"', escape: false);
    }

    public function test_internal_links_are_plain_anchors_built_by_route_without_trailing_slash(): void
    {
        foreach (['/lt', '/en/faq/', '/lt/nera-tokio'] as $path) {
            preg_match_all('~href="([^"]+)"~', $this->get($path)->getContent(), $matches);
            $this->assertNotEmpty($matches[1]);
            foreach (array_filter($matches[1], fn (string $href): bool => $href !== '#main') as $href) {
                $this->assertStringStartsWith(config('app.url').'/', $href);
                $this->assertStringEndsNotWith('/', $href);
            }
        }
    }

    public function test_absolute_urls_come_from_app_url_not_from_the_host_header(): void
    {
        $html = $this->get('http://other-host.test/en/faq')->assertOk()->getContent();
        $this->assertSame(config('app.url').'/en/faq', PageHead::canonical($html));
    }
}
