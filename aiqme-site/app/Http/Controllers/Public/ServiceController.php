<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Http\Controllers\Controller;
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
            'languages' => $this->content->alternates($item),
            'switchUrl' => $this->content->switchUrl($item, $locale === 'lt' ? 'en' : 'lt'),
            'home' => $this->content->page('home', $locale),
            'project' => $this->content->list(ContentType::Project, $locale)->load('item.media')
                ->first(fn (ContentTranslation $project): bool => in_array($key, (array) $project->item->meta('services', []), true)),
            'questions' => $this->content->list(ContentType::FaqItem, $locale, ['group' => $key])
                ->concat($this->content->list(ContentType::FaqItem, $locale, ['group' => 'services'])),
            'training' => $key === 'training' ? $this->content->page('training', $locale) : null,
            'trainings' => $key === 'training' ? $this->content->list(ContentType::Training, $locale) : collect(),
        ]);
    }
}
