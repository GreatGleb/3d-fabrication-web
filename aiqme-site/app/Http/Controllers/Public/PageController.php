<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Domain\Content\Search;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Публичные страницы (§1.2): главная, список услуг, «О нас», контакты, FAQ, политики (W-05), обучение и поиск (W-06).
 * Тексты — опубликованный контент своей локали (§4.2); пока page_text страницы не опубликован — заглушка
 * «Informacija ruošiama» (D-11).
 */
class PageController extends Controller
{
    use PageSeo;

    /** Сколько последних новостей показывает лента главной. */
    private const HOME_NEWS = 6;

    /** Предел текста описания из ссылки предзаполнения контактной формы (§1.2). */
    public const MESSAGE_MAX = 2000;

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

    /**
     * Контакты и форма обращения. Предзаполнение по контракту §1.2 (калькулятор, товар, услуга): GET service — slug
     * опубликованной услуги, product — slug опубликованного товара (выбор «Equipment quotation» и название товара в
     * описании), message — текст описания до 2000 символов (длиннее обрезается). Неизвестный slug игнорируется; значения
     * выводятся с экранированием Blade; файлы так не передаются.
     */
    public function contact(Request $request): View
    {
        $locale = app()->getLocale();
        $contact = $this->content->page('contact', $locale);
        $services = $this->content->list(ContentType::Service, $locale);
        $query = fn (string $name): string => is_string($request->query($name)) ? trim($request->query($name)) : '';
        $product = $query('product') !== '' ? $this->content->find(ContentType::Product, $locale, $query('product')) : null;
        $service = $services->firstWhere('slug', $query('service'))?->slug ?? ($product ? 'equipment' : null);

        return view('public.contact', [
            ...$this->seo('contact', $contact, $contact?->title, __('pages.contact.description')),
            'contact' => $contact,
            'services' => $services,
            'prefill' => [
                'service' => $service,
                'description' => implode("\n", array_filter([$product?->title, mb_substr($query('message'), 0, self::MESSAGE_MAX)])),
            ],
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

    /** Обучение (§1.2): опубликованные темы и даты (training) или заглушка, вводная панель — page_text training. */
    public function training(): View
    {
        $locale = app()->getLocale();
        $training = $this->content->page('training', $locale);

        return view('public.training', [
            ...$this->seo('training', $training, $training?->title, $training?->field('intro_text')),
            'training' => $training,
            'trainings' => $this->content->list(ContentType::Training, $locale),
            'trainingSlug' => $this->content->list(ContentType::Service, $locale)
                ->first(fn (ContentTranslation $service): bool => $service->item->key === 'training')?->slug,
        ]);
    }

    /** Поиск по сайту (§1.2, F-02): серверный, по опубликованному контенту текущей локали; noindex. */
    public function search(Request $request, Search $search): View
    {
        $query = Str::squish((string) $request->query('q', ''));

        return view('public.search', [
            ...$this->seo('search', null, null, null),
            'query' => $query,
            'results' => $search->find($query, app()->getLocale()),
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
}
