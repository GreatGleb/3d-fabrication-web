<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Http\Controllers\Controller;
use App\Support\Seo\StaticPages;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Публичные страницы W-05 (§1.2): главная, список услуг, «О нас», контакты, FAQ и политики. Тексты — опубликованный
 * контент своей локали (§4.2); пока page_text страницы не опубликован — заглушка «Informacija ruošiama» (D-11).
 */
class PageController extends Controller
{
    /** Сколько последних новостей показывает лента главной. */
    private const HOME_NEWS = 6;

    public function __construct(private readonly PublishedContent $content) {}

    public function home(): View
    {
        $locale = app()->getLocale();
        $home = $this->content->page('home', $locale);
        $services = $this->content->list(ContentType::Service, $locale);

        return view('public.home', [
            ...$this->seo('home', $home, $home?->title, $home?->field('hero_text')),
            'home' => $home,
            'services' => $services,
            'modelling' => $services->first(fn (ContentTranslation $service): bool => $service->item->key === 'modelling'),
            'brands' => $this->content->list(ContentType::Brand, $locale)->load('item.media'),
            'categories' => $this->content->list(ContentType::Category, $locale),
            'news' => $this->content->list(ContentType::Article, $locale, ['section' => 'news'])
                ->sortByDesc('published_at')->take(self::HOME_NEWS)->values(),
            'heroMedia' => $home?->item->load('media'),
        ]);
    }

    public function services(): View
    {
        $locale = app()->getLocale();

        return view('public.services', [
            ...$this->seo('services', null, null, __('pages.services.description')),
            'home' => $this->content->page('home', $locale),
            'services' => $this->content->list(ContentType::Service, $locale)->load('item.media'),
        ]);
    }

    public function about(): View
    {
        $about = $this->content->page('about', app()->getLocale());

        return view('public.about', [
            // Вводный абзац «О нас» — первый абзац блока, а не слоган подвала в page-top (§7.2).
            ...$this->seo('about', $about, $about?->title, $about?->field('intro_paragraphs')[0] ?? null),
            'about' => $about,
            'home' => $this->content->page('home', app()->getLocale()),
        ]);
    }

    public function contact(): View
    {
        $locale = app()->getLocale();
        $contact = $this->content->page('contact', $locale);

        return view('public.contact', [
            ...$this->seo('contact', $contact, $contact?->title, __('pages.contact.description')),
            'contact' => $contact,
            'services' => $this->content->list(ContentType::Service, $locale),
        ]);
    }

    /** FAQ: группы услуг в порядке услуг, затем general; группа services — только на страницах услуг (§4.1). */
    public function faq(): View
    {
        $locale = app()->getLocale();
        $groups = $this->content->list(ContentType::Service, $locale)
            ->map(fn (ContentTranslation $service): string => $service->item->key)
            ->push('general');

        return view('public.faq', [
            ...$this->seo('faq', null, null, null),
            'questions' => $groups->flatMap(fn (string $group): Collection => $this->content->list(ContentType::FaqItem, $locale, ['group' => $group])),
        ]);
    }

    /** Политики — черновики до публикации реального текста (D-11): до этого заглушка без демо-текста. */
    public function policy(string $page): View
    {
        $policy = $this->content->page($page, app()->getLocale());

        return view('public.policy', [
            ...$this->seo($page, $policy, $policy?->title, $policy?->field('intro')),
            'policy' => $policy,
        ]);
    }

    /**
     * Заголовок, описание и адреса страницы (§4.4): SEO-поля перевода, иначе заголовок и вводный абзац страницы,
     * иначе общее описание сайта. Перенос строки заголовка в <title> — пробел (§7.2).
     *
     * @return array{page: string, heading: string, seoTitle: string, description: string, languages: array<string, string>}
     */
    private function seo(string $page, ?ContentTranslation $translation, ?string $heading, ?string $intro): array
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
