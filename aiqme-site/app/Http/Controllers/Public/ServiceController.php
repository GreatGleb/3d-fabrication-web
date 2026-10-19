<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Http\Controllers\Controller;
use App\Support\Seo\StaticPages;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Страница услуги (§1.2, §4.1): slug своей локали, иначе 404. Процесс — общий блок главной; пример — опубликованный
 * проект этой услуги (без него примера нет); FAQ — группа услуги, затем services; у обучения — блок тем и дат.
 */
class ServiceController extends Controller
{
    public function __construct(private readonly PublishedContent $content) {}

    public function show(string $slug): View
    {
        $locale = app()->getLocale();
        $service = $this->content->find(ContentType::Service, $locale, $slug) ?? abort(404);
        $item = $service->item->load('media', 'translations');
        $key = $item->key;
        $illustration = $item->getFirstMedia('images');

        return view('public.service', [
            'service' => $service,
            'key' => $key,
            'illustration' => $illustration,
            'seoTitle' => Str::squish($service->seo_title ?: $service->title),
            'description' => Str::squish($service->seo_description ?: ($service->field('short') ?: __('pages.default_description'))),
            'image' => $illustration?->getUrl($illustration->hasGeneratedConversion('w1600') ? 'w1600' : ''),
            'languages' => self::alternates($item),
            'switchUrl' => $this->content->switchUrl($item, $locale === 'lt' ? 'en' : 'lt'),
            'home' => $this->content->page('home', $locale),
            'project' => $this->content->list(ContentType::Project, $locale)->load('item.media')
                ->first(fn (ContentTranslation $project): bool => in_array($key, (array) $project->item->meta('services', []), true)),
            'questions' => $this->content->list(ContentType::FaqItem, $locale, ['group' => $key])
                ->concat($this->content->list(ContentType::FaqItem, $locale, ['group' => 'services'])),
            'training' => $key === 'training' ? $this->content->page('training', $locale) : null,
        ]);
    }

    /**
     * Опубликованные LT/EN-версии услуги для hreflang и переключателя (§4.4).
     *
     * @return array<string, string>
     */
    public static function alternates(ContentItem $item): array
    {
        $urls = [];
        foreach (StaticPages::PUBLIC_LOCALES as $locale) {
            $translation = $item->translation($locale);
            if ($translation?->isPublished() && $translation->slug !== null) {
                $urls[$locale] = route($locale.'.services.show', ['slug' => $translation->slug]);
            }
        }

        return $urls;
    }
}
