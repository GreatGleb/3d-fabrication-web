<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_home_page_renders_without_external_font_links(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('fonts.bunny.net', escape: false);
    }
}
