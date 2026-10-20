<?php

namespace App\Domain\Content;

use App\Domain\Content\Models\ContentTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Поиск по сайту (§1.2, F-02), как searchAll прототипа: опубликованные услуги (название и short), товары (название и
 * категория) и статьи (название и вводный абзац) текущей локали; совпадение подстроки без учёта регистра, до 12 результатов.
 * Черновики и материалы другой локали не находятся: читает только PublishedContent.
 */
final class Search
{
    public const LIMIT = 12;

    public function __construct(private readonly PublishedContent $content) {}

    /** @return Collection<int, array{name: string, description: string, url: string}> */
    public function find(string $query, string $locale): Collection
    {
        $query = Str::squish($query);
        if ($query === '') {
            return collect();
        }

        $categories = $this->content->list(ContentType::Category, $locale)
            ->mapWithKeys(fn (ContentTranslation $category): array => [$category->item->key => $category->title]);

        $entries = $this->content->list(ContentType::Service, $locale)
            ->map(fn (ContentTranslation $service): array => $this->entry($service, $locale, (string) $service->field('short')))
            ->concat($this->content->list(ContentType::Product, $locale)
                ->map(fn (ContentTranslation $product): array => $this->entry($product, $locale, (string) ($categories[$product->item->meta('category')] ?? ''))))
            ->concat($this->content->list(ContentType::Article, $locale)
                ->map(fn (ContentTranslation $article): array => $this->entry($article, $locale, (string) $article->field('intro'))));

        return $entries
            ->filter(fn (array $entry): bool => mb_stripos($entry['name'].' '.$entry['description'], $query) !== false)
            ->take(self::LIMIT)
            ->values();
    }

    /** @return array{name: string, description: string, url: string} */
    private function entry(ContentTranslation $translation, string $locale, string $description): array
    {
        $page = $locale.'.'.$translation->type->detailPage();

        return [
            'name' => $translation->title,
            'description' => $description,
            'url' => route($page.'.show', ['slug' => $translation->slug]),
        ];
    }
}
