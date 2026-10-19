<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Заголовки §6.6: CSP (enforce, nonce на каждый ответ), nosniff, Referrer-Policy, frame-ancestors — на всех ответах. */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, list<string>> директива => источники */
    private function csp(TestResponse $response): array
    {
        $header = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($header, 'нет Content-Security-Policy');
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'), 'CSP должна действовать, а не только сообщать');

        $directives = [];
        foreach (array_filter(array_map('trim', explode(';', $header))) as $directive) {
            $parts = preg_split('/\s+/', $directive);
            $directives[array_shift($parts)] = $parts;
        }

        return $directives;
    }

    private function nonce(array $csp, string $directive): string
    {
        $nonces = preg_grep("/^'nonce-[A-Za-z0-9+\/=]{16,}'$/", $csp[$directive] ?? []);
        $this->assertCount(1, $nonces, "$directive без nonce");

        return reset($nonces);
    }

    public function test_every_kind_of_response_has_the_security_headers(): void
    {
        $responses = [
            '/lt' => $this->get('/lt'),
            '/en/services' => $this->get('/en/services'),
            '404' => $this->get('/lt/nera-tokio')->assertNotFound(),
            '/ru (404 гостю)' => $this->get('/ru')->assertNotFound(),
            'redirect /' => $this->get('/')->assertStatus(301),
            'sitemap' => $this->get('/sitemap.xml')->assertOk(),
            'robots' => $this->get('/robots.txt')->assertOk(),
        ];
        Route::get('/__test-500', fn () => throw new \RuntimeException('boom'));
        $responses['500'] = $this->get('/__test-500')->assertStatus(500);

        foreach ($responses as $name => $response) {
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $name);
            $this->assertSame('same-origin', $response->headers->get('Referrer-Policy'), $name);
            $csp = $this->csp($response);
            $this->assertSame(["'none'"], $csp['frame-ancestors'] ?? null, $name);
            $this->assertSame(["'self'"], $csp['default-src'] ?? null, $name);
            $this->assertSame(["'none'"], $csp['object-src'] ?? null, $name);
            $this->assertSame(["'self'"], $csp['base-uri'] ?? null, $name);
            $this->assertSame(["'self'"], $csp['form-action'] ?? null, $name);
            $this->assertSame(["'self'", 'data:'], $csp['img-src'] ?? null, $name);
        }
    }

    public function test_csp_uses_nonce_for_scripts_and_style_elements_and_allows_only_style_attributes_inline(): void
    {
        $csp = $this->csp($this->get('/lt'));

        $nonce = $this->nonce($csp, 'script-src');
        $this->assertSame($nonce, $this->nonce($csp, 'style-src-elem'));
        $this->assertSame($nonce, $this->nonce($csp, 'style-src'));
        foreach (['script-src', 'style-src', 'style-src-elem'] as $directive) {
            $this->assertNotContains("'unsafe-inline'", $csp[$directive], $directive);
            $this->assertContains("'self'", $csp[$directive], $directive);
        }
        // Признанные компромиссы §6.6: атрибуты style="…" прототипа и Livewire; eval Alpine в Livewire 3.8.10.
        $this->assertSame(["'unsafe-inline'"], $csp['style-src-attr']);
        $this->assertContains("'unsafe-eval'", $csp['script-src']);
    }

    public function test_nonce_is_new_on_every_response_and_is_handed_to_vite_and_livewire(): void
    {
        $first = $this->nonce($this->csp($this->get('/lt')), 'script-src');
        $this->assertSame("'nonce-".Vite::cspNonce()."'", $first, 'Vite (и Livewire через Vite::cspNonce) получают nonce ответа');

        $second = $this->nonce($this->csp($this->get('/lt')), 'script-src');
        $this->assertNotSame($first, $second);
    }

    public function test_russian_pages_of_the_administrator_have_the_headers_too(): void
    {
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        $response = $this->actingAs($admin)->get('/ru/services')->assertOk();
        $this->nonce($this->csp($response), 'script-src');
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}
