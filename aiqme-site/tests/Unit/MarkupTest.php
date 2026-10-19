<?php

namespace Tests\Unit;

use App\Domain\Content\Markup;
use PHPUnit\Framework\TestCase;

/** Санитизация разметки контента (W-04, §4.2): HTML/JS из текста не исполняется. */
class MarkupTest extends TestCase
{
    public function test_markdown_subset_is_rendered(): void
    {
        $html = (string) Markup::html("Pirma **svarbu** ir *pastaba*.\n\n- vienas\n- du\n\n[Kontaktai](https://aiqme.com/lt/kontaktai)");

        $this->assertStringContainsString('<p>Pirma <strong>svarbu</strong> ir <em>pastaba</em>.</p>', $html);
        $this->assertStringContainsString("<ul>\n<li>vienas</li>\n<li>du</li>\n</ul>", $html);
        $this->assertStringContainsString('<a href="https://aiqme.com/lt/kontaktai">Kontaktai</a>', $html);
    }

    public function test_raw_html_and_scripts_are_escaped(): void
    {
        $html = (string) Markup::html('<script>alert(1)</script><img src=x onerror=alert(1)><b onclick="x()">b</b>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b ', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_unsafe_links_images_and_other_tags_are_removed(): void
    {
        $html = (string) Markup::html("# Antraštė\n\n[spausk](javascript:alert(1)) [d](data:text/html;base64,PHNjcmlwdD4=) ![x](https://evil.example/x.png)\n\n`kodas`");

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('<code', $html);
        $this->assertStringContainsString('Antraštė', $html);
        $this->assertSame('', (string) Markup::html(null));
    }
}
