<?php

namespace App\Support\Seo;

/**
 * Статические публичные страницы и их сегменты (§1.2, адреса прототипа). Маршруты — "<locale>.<page>":
 * LT — свои сегменты, EN — английские, RU (только администратору, §4.3) — английские под /ru.
 * Адреса сущностей (услуги, статьи, проекты, товары) добавляют W-05, W-06, W-25.
 */
final class StaticPages
{
    public const PUBLIC_LOCALES = ['lt', 'en'];

    public const LOCALES = ['lt', 'en', 'ru'];

    /**
     * page => [сегмент LT, сегмент EN, в sitemap]. Не в sitemap: поиск (noindex, §4.4) и разделы, которые
     * до наполнения — заглушки или пустое состояние (§4.4): товары, проекты, новости, корзина, сравнение, политики.
     *
     * @var array<string, array{0: string, 1: string, 2: bool}>
     */
    public const PAGES = [
        'home' => ['', '', true],
        'services' => ['paslaugos', 'services', true],
        'products' => ['produktai', 'products', false],
        'projects' => ['projektai', 'projects', false],
        'information' => ['informacija', 'information', true],
        'library' => ['biblioteka', 'library', true],
        'news' => ['naujienos', 'news', false],
        'about' => ['apie-mus', 'about', true],
        'contact' => ['kontaktai', 'contact', true],
        'calculator' => ['kalkuliatorius', 'quote', true],
        'cart' => ['krepselis', 'cart', false],
        'compare' => ['palyginimas', 'compare', false],
        'faq' => ['duk', 'faq', true],
        'training' => ['mokymai', 'training', true],
        'search' => ['paieska', 'search', false],
        'privacy' => ['privatumas', 'privacy', false],
        'cookies' => ['slapukai', 'cookies', false],
        'delivery' => ['pristatymas', 'delivery', false],
        'returns' => ['grazinimas', 'returns', false],
    ];

    /** Страницы с noindex (§4.4). */
    public const NOINDEX = ['search'];

    public static function segment(string $page, string $locale): string
    {
        return self::PAGES[$page][$locale === 'lt' ? 0 : 1];
    }

    /** @return list<string> */
    public static function inSitemap(): array
    {
        return array_keys(array_filter(self::PAGES, fn (array $page): bool => $page[2]));
    }

    /**
     * Публичные версии страницы: locale => URL из route().
     *
     * @return array<string, string>
     */
    public static function alternates(string $page): array
    {
        $urls = [];
        foreach (self::PUBLIC_LOCALES as $locale) {
            $urls[$locale] = route($locale.'.'.$page);
        }

        return $urls;
    }
}
