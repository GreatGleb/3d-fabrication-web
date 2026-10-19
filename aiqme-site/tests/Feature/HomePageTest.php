<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_home_pages_render_without_external_font_links(): void
    {
        foreach (['lt.home', 'en.home'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertDontSee('fonts.bunny.net', escape: false);
        }
    }
}
