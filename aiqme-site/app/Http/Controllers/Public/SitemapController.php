<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Content\PublishedContent;
use App\Http\Controllers\Controller;
use App\Support\Seo\StaticPages;
use Illuminate\Http\Response;

/**
 * Динамический sitemap.xml (§4.4): статические страницы, опубликованные политики, списки проектов, новостей и обучения —
 * на локалях, где в них есть публикации, и карточки услуг, проектов и статей (адрес библиотеки) — каждая версия с
 * alternates только опубликованных локалей. Без RU, кабинета, админки, поиска, разделов-заглушек и черновиков.
 */
class SitemapController extends Controller
{
    /** Списки-заглушки (§4.2): в sitemap — только на локали с хотя бы одной публикацией (тип, отбор по meta). */
    private const SECTIONS = [
        'projects' => [ContentType::Project, []],
        'news' => [ContentType::Article, ['section' => 'news']],
        'training' => [ContentType::Training, []],
    ];

    public function __invoke(PublishedContent $content): Response
    {
        $groups = array_map(StaticPages::alternates(...), StaticPages::inSitemap());

        foreach (StaticPages::POLICIES as $page) {
            $groups[] = array_filter(
                StaticPages::alternates($page),
                fn (string $locale): bool => $content->page($page, $locale) !== null,
                ARRAY_FILTER_USE_KEY,
            );
        }

        foreach (self::SECTIONS as $page => [$type, $meta]) {
            $groups[] = array_filter(
                StaticPages::alternates($page),
                fn (string $locale): bool => ! $content->showsPlaceholder($type, $locale, $meta),
                ARRAY_FILTER_USE_KEY,
            );
        }

        $items = ContentItem::query()
            ->whereIn('type', [ContentType::Service, ContentType::Project, ContentType::Article])
            ->orderByRaw("case type when 'service' then 1 when 'project' then 2 else 3 end")
            ->orderBy('position')
            ->orderBy('id')
            ->with('translations')
            ->get();
        foreach ($items as $item) {
            $groups[] = $content->alternates($item);
        }

        return response()
            ->view('seo.sitemap', ['groups' => array_values(array_filter($groups))])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
