<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Доверенные прокси (W-20, §6.6): список из config('aiqme.trusted_proxies') читается в момент запроса — тест задаёт его
 * после загрузки приложения; X-Forwarded-For/Proto принимаются только от адреса из списка.
 */
class TrustProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/trust-probe', fn (Request $request): array => ['ip' => $request->ip(), 'scheme' => $request->getScheme()]);
    }

    /**
     * @return array{ip: string, scheme: string}
     */
    private function probe(string $remoteAddress): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
            ->get('http://localhost/trust-probe')->assertOk()->json();
    }

    public function test_forwarded_headers_from_untrusted_address_are_ignored(): void
    {
        config(['aiqme.trusted_proxies' => '172.30.0.1']);

        $this->assertSame(['ip' => '10.9.9.9', 'scheme' => 'http'], $this->probe('10.9.9.9'));
    }

    public function test_forwarded_headers_from_trusted_address_are_used(): void
    {
        config(['aiqme.trusted_proxies' => '172.30.0.1']);

        $this->assertSame(['ip' => '203.0.113.7', 'scheme' => 'https'], $this->probe('172.30.0.1'));
    }

    public function test_trusted_subnet_from_comma_separated_list(): void
    {
        config(['aiqme.trusted_proxies' => '192.0.2.10, 172.30.0.0/16']);

        $this->assertSame(['ip' => '203.0.113.7', 'scheme' => 'https'], $this->probe('172.30.5.5'));
        $this->assertSame(['ip' => '172.31.0.1', 'scheme' => 'http'], $this->probe('172.31.0.1'));
    }

    public function test_empty_list_trusts_nobody(): void
    {
        config(['aiqme.trusted_proxies' => '']);

        $this->assertSame(['ip' => '172.30.0.1', 'scheme' => 'http'], $this->probe('172.30.0.1'));
    }
}
