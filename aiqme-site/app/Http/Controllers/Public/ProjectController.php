<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Проекты (§1.2): список с фильтрами услуги и применения (?service=<ключ услуги>&application=<ключ>, как значения
 * select прототипа) и карточка по slug своей локали. Только опубликованные переводы локали: без EN-перевода проекта
 * нет под /en/ (F-38). Пока на локали нет опубликованных проектов — заглушка (D-11).
 */
class ProjectController extends Controller
{
    use PageSeo;

    /** Применения проекта (meta.application) — список прототипа. */
    public const APPLICATIONS = ['maintenance', 'development', 'manufacturing'];

    public function __construct(private readonly PublishedContent $content) {}

    public function index(Request $request): View
    {
        $locale = app()->getLocale();
        $all = $this->content->list(ContentType::Project, $locale)->load('item.media');
        $service = (string) $request->query('service', '');
        $application = (string) $request->query('application', 'all');

        return view('public.projects', [
            ...$this->seo('projects', null, null, __('pages.projects.description')),
            'placeholder' => $all->isEmpty(),
            'projects' => $all->filter(fn (ContentTranslation $project): bool => ($service === ''
                    || in_array($service, (array) $project->item->meta('services', []), true))
                && ($application === 'all' || $project->item->meta('application') === $application))->values(),
            'services' => $this->services($locale),
            'service' => $service,
            'application' => $application,
        ]);
    }

    public function show(string $slug): View
    {
        $locale = app()->getLocale();
        $project = $this->content->find(ContentType::Project, $locale, $slug) ?? abort(404);
        $item = $project->item->load('media', 'translations');
        $image = $item->getFirstMedia('images');
        $related = (array) $item->meta('services', []);
        $youtube = $item->meta('youtube');

        return view('public.project', [
            'project' => $project,
            'image' => $image,
            'seoTitle' => Str::squish($project->seo_title ?: $project->title),
            'description' => Str::squish($project->seo_description ?: ($project->field('task') ?: __('pages.default_description'))),
            'ogImage' => $image?->getUrl($image->hasGeneratedConversion('w1600') ? 'w1600' : ''),
            'languages' => $this->content->alternates($item),
            'switchUrl' => $this->content->switchUrl($item, $locale === 'lt' ? 'en' : 'lt'),
            'services' => $this->services($locale)->only($related)->values(),
            'youtube' => is_string($youtube) && str_starts_with($youtube, 'https://') ? $youtube : null,
        ]);
    }

    /**
     * Опубликованные услуги локали в порядке услуг: ключ => перевод.
     *
     * @return Collection<string, ContentTranslation>
     */
    private function services(string $locale): Collection
    {
        return $this->content->list(ContentType::Service, $locale)->toBase()
            ->keyBy(fn (ContentTranslation $service): string => $service->item->key);
    }
}
