<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Информация, новости и библиотека (§1.2, F-36): вкладки, поиск по названию и вводному абзацу, категория (фильтра
 * «Language» нет: материалы другой локали не показываются). Раздел статьи влияет только на списки: «информация» — все
 * статьи, «новости» и «библиотека» — свой раздел; карточка любой статьи — только адрес библиотеки (§4.1).
 */
class ArticleController extends Controller
{
    use PageSeo;

    /** Категории статей (meta.category) — список прототипа. */
    public const CATEGORIES = ['materials', 'guides', 'technology'];

    /** Вкладки раздела в порядке прототипа. */
    public const TABS = ['information', 'news', 'library', 'faq', 'training'];

    public function __construct(private readonly PublishedContent $content) {}

    public function information(Request $request): View
    {
        return $this->index($request, 'information', []);
    }

    public function news(Request $request): View
    {
        return $this->index($request, 'news', ['section' => 'news']);
    }

    public function library(Request $request): View
    {
        return $this->index($request, 'library', ['section' => 'library']);
    }

    public function show(string $slug): View
    {
        $locale = app()->getLocale();
        $article = $this->content->find(ContentType::Article, $locale, $slug) ?? abort(404);
        $item = $article->item->load('translations');

        return view('public.article', [
            'article' => $article,
            'seoTitle' => Str::squish($article->seo_title ?: $article->title),
            'description' => Str::squish($article->seo_description ?: ($article->field('intro') ?: __('pages.default_description'))),
            'languages' => $this->content->alternates($item),
            'switchUrl' => $this->content->switchUrl($item, $locale === 'lt' ? 'en' : 'lt'),
        ]);
    }

    /** @param  array<string, string>  $section */
    private function index(Request $request, string $page, array $section): View
    {
        $all = $this->content->list(ContentType::Article, app()->getLocale(), $section);
        if ($page === 'news') {
            $all = $all->sortByDesc('published_at')->values();  // как лента новостей главной: новые первыми
        }
        $query = Str::squish((string) $request->query('q', ''));
        $category = (string) $request->query('category', '');

        return view('public.articles', [
            ...$this->seo($page, null, null, __('pages.'.$page.'.description')),
            'placeholder' => $all->isEmpty(),
            'articles' => $all->filter(fn (ContentTranslation $article): bool => ($category === '' || $article->item->meta('category') === $category)
                && ($query === '' || mb_stripos($article->title.' '.$article->field('intro'), $query) !== false))->values(),
            'query' => $query,
            'category' => $category,
        ]);
    }
}
