<?php

namespace App\Domain\Content;

/**
 * Типы контента §4.1. Поля перевода, кроме title/slug/SEO, хранятся в fields; набор полей задан кодом
 * (у page_text — блоками страницы из сидера): администратор меняет содержимое, а не макет.
 */
enum ContentType: string
{
    case PageText = 'page_text';
    case Service = 'service';
    case Project = 'project';
    case Article = 'article';
    case Training = 'training';
    case Product = 'product';
    case Brand = 'brand';
    case Category = 'category';
    case FaqItem = 'faq_item';

    /**
     * Поля перевода; null — у page_text набор задан блоками самого элемента.
     *
     * @return list<string>|null
     */
    public function fields(): ?array
    {
        return match ($this) {
            self::PageText => null,
            self::Service => ['short', 'label', 'benefits', 'description', 'problems', 'provide', 'receive'],
            self::Project => ['task', 'solution', 'result'],
            self::Article => ['intro', 'sections'],
            self::Training => ['description'],
            self::Product => ['description', 'specifications', 'compatibility'],
            self::Brand, self::Category => [],
            self::FaqItem => ['answer'],
        };
    }

    /** У перевода свой slug (адрес карточки или ?category=). */
    public function hasSlug(): bool
    {
        return in_array($this, [self::Service, self::Project, self::Article, self::Product, self::Category], true);
    }

    /** SEO-поля — у типов с собственной страницей и у страниц (§4.1). */
    public function hasSeo(): bool
    {
        return in_array($this, [self::PageText, self::Service, self::Project, self::Article, self::Product], true);
    }

    /** Страница-раздел (StaticPages) с карточкой "<locale>.<page>.show" по slug; null — карточки нет. */
    public function detailPage(): ?string
    {
        return match ($this) {
            self::Service => 'services',
            self::Project => 'projects',
            self::Article => 'library',
            self::Product => 'products',
            default => null,
        };
    }
}
