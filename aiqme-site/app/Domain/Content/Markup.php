<?php

namespace App\Domain\Content;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Вывод длинного текста контента (§4.2: произвольный HTML/JS запрещён). Markdown-подмножество: абзацы, переносы,
 * выделение, списки и ссылки. HTML из текста экранируется, опасные ссылки (javascript:, data: и т. п.) не создаются,
 * прочие теги Markdown (изображения, заголовки, код) снимаются белым списком.
 */
final class Markup
{
    private const TAGS = '<p><br><strong><em><ul><ol><li><a>';

    public static function html(?string $text): HtmlString
    {
        if ($text === null || trim($text) === '') {
            return new HtmlString('');
        }

        $html = Str::markdown($text, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]);

        return new HtmlString(trim(strip_tags($html, self::TAGS)));
    }
}
