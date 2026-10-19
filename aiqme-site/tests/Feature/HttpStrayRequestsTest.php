<?php

namespace Tests\Feature;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpStrayRequestsTest extends TestCase
{
    public function test_request_to_external_host_without_fake_fails(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://api.stripe.com/v1/charges');
    }

    public function test_faked_request_is_allowed(): void
    {
        Http::fake(['example.test/*' => Http::response(['ok' => true])]);

        $this->assertTrue(Http::get('https://example.test/ping')->json('ok'));
    }
}
