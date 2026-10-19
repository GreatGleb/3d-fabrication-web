<?php

namespace App\Domain\Content;

use App\Domain\Content\Models\ContentItem;
use App\Domain\Content\Models\ContentTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Чтение опубликованного контента для сайта (§4.2): только опубликованные переводы своей локали, LT под EN-адресом
 * не подставляется; заглушка раздела, пока на локали нет ни одной публикации; переключатель языка для сущностей.
 */
final class PublishedContent
{
    /**
     * Опубликованные переводы типа на локали в порядке элементов; $meta — отбор по атрибутам (группа FAQ, раздел статьи).
     *
     * @param  array<string, string>  $meta
     * @return Collection<int, ContentTranslation>
     */
    public function list(ContentType $type, string $locale, array $meta = []): Collection
    {
        return $this->query($type, $locale, $meta)
            ->orderBy('content_items.position')
            ->orderBy('content_items.id')
            ->get();
    }

    public function find(ContentType $type, string $locale, string $slug): ?ContentTranslation
    {
        return $this->query($type, $locale)->where('content_translations.slug', $slug)->first();
    }

    /** Опубликованный page_text страницы; на тестовом /ru без RU-перевода — EN (§4.3). */
    public function page(string $key, string $locale): ?ContentTranslation
    {
        foreach ($locale === 'ru' ? ['ru', 'en'] : [$locale] as $candidate) {
            $page = $this->query(ContentType::PageText, $candidate)->where('content_items.key', $key)->first();
            if ($page !== null) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Заглушка «Informacija ruošiama» · «Information is being prepared» (§4.2): пока на локали нет публикаций.
     *
     * @param  array<string, string>  $meta
     */
    public function showsPlaceholder(ContentType $type, string $locale, array $meta = []): bool
    {
        return ! $this->query($type, $locale, $meta)->exists();
    }

    /**
     * Адрес переключателя языка для сущности: опубликованная альтернатива на $locale, иначе список раздела,
     * иначе главная этой локали.
     */
    public function switchUrl(ContentItem $item, string $locale): string
    {
        $type = $item->type;
        $alternative = $item->translations()->where('locale', $locale)->published()->first();
        $detail = $type->detailPage();
        if ($alternative !== null && $detail !== null && $alternative->slug !== null && Route::has($locale.'.'.$detail.'.show')) {
            return route($locale.'.'.$detail.'.show', ['slug' => $alternative->slug]);
        }

        $section = match ($type) {
            ContentType::Article => $item->meta('section') === 'news' ? 'news' : 'library',
            ContentType::Service => 'services',
            ContentType::Project => 'projects',
            ContentType::Product, ContentType::Brand, ContentType::Category => 'products',
            ContentType::Training => 'training',
            ContentType::FaqItem => 'faq',
            ContentType::PageText => (string) $item->key,
        };

        return Route::has($locale.'.'.$section) ? route($locale.'.'.$section) : route($locale.'.home');
    }

    /**
     * @param  array<string, string>  $meta
     * @return Builder<ContentTranslation>
     */
    private function query(ContentType $type, string $locale, array $meta = []): Builder
    {
        $query = ContentTranslation::query()
            ->select('content_translations.*')
            ->join('content_items', 'content_items.id', '=', 'content_translations.content_item_id')
            ->where('content_translations.type', $type)
            ->where('content_translations.locale', $locale)
            ->whereNotNull('content_translations.published_at');
        foreach ($meta as $name => $value) {
            $query->where('content_items.meta->'.$name, $value);
        }

        return $query;
    }
}
