<?php

namespace Tests\Support;

use DOMDocument;
use DOMNode;
use DOMXPath;

/** Разбор отрендеренной страницы для проверок разметки и текста (W-05). */
final class Html
{
    public static function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    public static function text(?DOMNode $node): string
    {
        return $node === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    /** @return list<string> */
    public static function texts(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        $texts = [];
        foreach ($xpath->query($query, $context) as $node) {
            $texts[] = self::text($node);
        }

        return $texts;
    }

    /** Классы элемента XPath-выражением: contains(concat(' ', @class, ' '), ' name '). */
    public static function hasClass(string $name): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' ".$name." ')";
    }
}
