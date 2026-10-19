<?php

namespace Tests\Support;

/** Разбор <head> отрендеренной страницы для проверок SEO (§4.4). */
final class PageHead
{
    public static function canonical(string $html): ?string
    {
        return preg_match('~<link rel="canonical" href="([^"]+)">~', $html, $match) ? html_entity_decode($match[1]) : null;
    }

    /** @return array<string, string> hreflang => href */
    public static function hreflang(string $html): array
    {
        preg_match_all('~<link rel="alternate" hreflang="([^"]+)" href="([^"]+)">~', $html, $matches, PREG_SET_ORDER);

        return array_column(array_map(fn (array $m): array => [$m[1], html_entity_decode($m[2])], $matches), 1, 0);
    }

    public static function meta(string $html, string $attribute, string $name): ?string
    {
        $pattern = '~<meta '.$attribute.'="'.preg_quote($name, '~').'" content="([^"]*)">~';

        return preg_match($pattern, $html, $match) ? html_entity_decode($match[1]) : null;
    }
}
