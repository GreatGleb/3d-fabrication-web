<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\Models\ContentTranslation;
use App\Support\Seo\StaticPages;
use Illuminate\Support\Str;

/** SEO статических страниц (§4.4) — общее для контроллеров публичных страниц. */
trait PageSeo
{
    /**
     * Заголовок, описание и адреса страницы (§4.4): SEO-поля перевода, иначе заголовок и вводный абзац страницы,
     * иначе общее описание сайта. Перенос строки заголовка в <title> — пробел (§7.2).
     *
     * @return array{page: string, heading: string, seoTitle: string, description: string, languages: array<string, string>}
     */
    protected function seo(string $page, ?ContentTranslation $translation, ?string $heading, ?string $intro): array
    {
        $heading ??= __('pages.'.$page.'.title');

        return [
            'page' => $page,
            'heading' => $heading,
            'seoTitle' => Str::squish($translation?->seo_title ?: $heading),
            'description' => Str::squish($translation?->seo_description ?: ($intro ?: __('pages.default_description'))),
            'languages' => StaticPages::alternates($page),
        ];
    }
}
