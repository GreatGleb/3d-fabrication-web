<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Content\PublishedContent;
use App\Http\Controllers\Controller;
use App\Support\Seo\StaticPages;
use Illuminate\Http\Response;

/**
 * Динамический sitemap.xml (§4.4): статические страницы, опубликованные политики и услуги — каждая версия с
 * alternates только опубликованных локалей. Без RU, кабинета, админки, поиска, разделов-заглушек и черновиков.
 */
class SitemapController extends Controller
{
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

        $services = ContentItem::query()->where('type', ContentType::Service)->orderBy('position')->with('translations')->get();
        foreach ($services as $service) {
            $groups[] = ServiceController::alternates($service);
        }

        return response()
            ->view('seo.sitemap', ['groups' => array_values(array_filter($groups))])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
