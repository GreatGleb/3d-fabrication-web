<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Http\Controllers\Controller;
use Collator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Каталог (§1.2, F-03–F-06): список товаров с фильтрами категории (?category=<slug категории>) и производителя
 * (?brand=<id бренда>), поиском по названию (?q=) и сортировками «Recommended» (новые публикации перевода первыми) и
 * «Name A–Z» (D-41); карточка товара по slug своей локали; сравнение до 4 опубликованных товаров (?products=<slug,…>);
 * корзина — пустое состояние, продажа выключена (D-12). Товар хранит meta.category (ключ категории) и meta.brand
 * (id бренда). Пока на локали нет опубликованных товаров — заглушка (§4.2). Плановых пометок прототипа нет (D-21, D-36).
 */
class ProductController extends Controller
{
    use PageSeo;

    public const COMPARE_LIMIT = 4;

    public const SORTS = ['recommended', 'name'];

    public function __construct(private readonly PublishedContent $content) {}

    public function index(Request $request): View
    {
        $locale = app()->getLocale();
        $all = $this->content->list(ContentType::Product, $locale)->load('item.media');
        $categories = $this->categories($locale);
        $brands = $this->brands($locale);
        $category = (string) $request->query('category', '');
        $brand = (string) $request->query('brand', '');
        $query = Str::squish((string) $request->query('q', ''));
        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'recommended';
        $categoryKey = $categories->first(fn (ContentTranslation $translation): bool => $translation->slug === $category)?->item->key;

        $products = $all->filter(fn (ContentTranslation $product): bool => ($category === ''
                || ($categoryKey !== null && $product->item->meta('category') === $categoryKey))
            && ($brand === '' || (string) $product->item->meta('brand') === $brand)
            && ($query === '' || mb_stripos($product->title, $query) !== false));

        $products = $sort === 'name'
            ? $products->sort(fn (ContentTranslation $a, ContentTranslation $b): int => (new Collator($locale))->compare($a->title, $b->title))
            : $products->sortByDesc(fn (ContentTranslation $product): int => $product->published_at->getTimestamp());

        return view('public.products', [
            ...$this->seo('products', null, null, __('pages.products.description')),
            'placeholder' => $all->isEmpty(),
            'products' => $products->values(),
            'categories' => $categories,
            'brands' => $brands,
            'category' => $category,
            'brand' => $brand,
            'query' => $query,
            'sort' => $sort,
        ]);
    }

    public function show(string $slug): View
    {
        $locale = app()->getLocale();
        $product = $this->content->find(ContentType::Product, $locale, $slug) ?? abort(404);
        $item = $product->item->load('media', 'translations');
        $images = $item->getMedia('images');
        $image = $images->first();

        return view('public.product', [
            'product' => $product,
            'images' => $images,
            'category' => $this->categories($locale)->get($item->meta('category')),
            'brand' => $this->brands($locale)->get($item->meta('brand')),
            'seoTitle' => Str::squish($product->seo_title ?: $product->title),
            'description' => Str::squish($product->seo_description ?: ($product->field('description') ?: __('pages.default_description'))),
            'ogImage' => $image?->getUrl($image->hasGeneratedConversion('w1600') ? 'w1600' : ''),
            'languages' => $this->content->alternates($item),
            'switchUrl' => $this->content->switchUrl($item, $locale === 'lt' ? 'en' : 'lt'),
        ]);
    }

    /** Сравнение (F-04): опубликованные товары локали из ?products=<slug,…> в порядке выбора, без повторов, не больше 4. */
    public function compare(Request $request): View
    {
        $locale = app()->getLocale();
        $slugs = array_values(array_unique(array_filter(explode(',', (string) $request->query('products', '')))));
        $products = collect($slugs)
            ->map(fn (string $slug): ?ContentTranslation => $this->content->find(ContentType::Product, $locale, $slug))
            ->filter()
            ->take(self::COMPARE_LIMIT)
            ->values();

        return view('public.compare', [
            ...$this->seo('compare', null, null, __('pages.compare.description')),
            'products' => $products,
            'categories' => $this->categories($locale),
            'brands' => $this->brands($locale),
        ]);
    }

    /** Корзина товаров (D-12): продажа выключена — пустое состояние со ссылкой на калькулятор. */
    public function cart(): View
    {
        return view('public.cart', $this->seo('cart', null, null, __('pages.cart.description')));
    }

    /**
     * Опубликованные категории локали: ключ => перевод.
     *
     * @return Collection<string, ContentTranslation>
     */
    private function categories(string $locale): Collection
    {
        return $this->content->list(ContentType::Category, $locale)->toBase()
            ->keyBy(fn (ContentTranslation $category): string => $category->item->key);
    }

    /**
     * Опубликованные бренды локали: id бренда => перевод.
     *
     * @return Collection<int, ContentTranslation>
     */
    private function brands(string $locale): Collection
    {
        return $this->content->list(ContentType::Brand, $locale)->toBase()
            ->keyBy(fn (ContentTranslation $brand): int => $brand->content_item_id);
    }
}
