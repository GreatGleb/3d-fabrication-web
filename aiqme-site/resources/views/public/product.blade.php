{{-- Страница товара (§1.2, productPage прототипа, F-06): галерея фото товара (переключение — resources/js/gallery.js; без фото —
     своя заглушка), бренд, описание, «Individualus pasiūlymas» · «Tailored quotation», «Gauti pasiūlymą» · «Request a quote» —
     контактная форма с ?product=<slug> (контракт §1.2, читает W-09), характеристики, совместимость. Покупки нет (D-12).
     Без notice прототипа, подписи схемы, ярлыка «/ PLANNED», строки «Supply status» и техпанели-заметки (D-21): техдокументы —
     заглушка; notice совместимости остаётся, в LT без слова «Prototipe».
     Правка 05.11, H-07: товар «Pirkti» — цена с PVM, «Kiekis» и «Į krepšelį» (POST в корзину) вместо «Individualus
     pasiūlymas» и «Gauti pasiūlymą»; «Komplektacija ir palaikymas» — текст товара или общая фраза; «Dokumentai» — ссылки
     на PDF товара, без документов блока нет. --}}
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.products.show', ['slug' => $product->slug])"
    :languages="$languages" :switch-url="$switchUrl" :image="$ogImage" :noindex="$locale === 'ru'">
    <x-page-top :title="$product->title" :intro="$category?->title" />
    <div class="wrap page-content product-detail">
        <div class="split" style="align-items:start">
            <div>
                <div class="gallery-main" id="gallery">
                    @forelse ($images as $image)
                        <div class="gallery-view" data-gallery-view @if (! $loop->first) hidden @endif>
                            @include('public.partials.media', ['mediaImage' => $image, 'mediaSizes' => '(max-width: 900px) 100vw, 50vw', 'mediaLazy' => ! $loop->first])
                        </div>
                    @empty
                        <div class="gallery-view">@include('public.partials.art')</div>
                    @endforelse
                </div>
                @if ($images->count() > 1)
                    <div class="gallery-thumbs">
                        @foreach ($images as $image)
                            <button type="button" data-gallery-thumb @class(['active' => $loop->first]) aria-pressed="{{ $loop->first ? 'true' : 'false' }}"
                                aria-label="{{ $loop->first ? __('public.product.main_view') : __('public.product.view', ['number' => $loop->iteration]) }}">
                                @include('public.partials.media', ['mediaImage' => $image, 'mediaSizes' => '60px'])
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
            <div>
                @if ($brand)
                    <span class="tag blue">{{ $brand->title }}</span>
                @endif
                <h2 class="gap">{{ $product->title }}</h2>
                @if ($product->field('description'))
                    <p class="lead">{{ $product->field('description') }}</p>
                @endif
                @if ($price !== null)
                    <div class="price">{{ \Illuminate\Support\Number::currency($price / 100, in: 'EUR', locale: $locale) }}</div>
                    <form method="post" action="{{ route($locale.'.cart.add') }}">
                        @csrf
                        <input type="hidden" name="product" value="{{ $product->slug }}">
                        <x-field name="quantity" type="number" :label="__('public.product.quantity')" value="1" min="1" :max="App\Http\Controllers\Public\ProductController::QUANTITY_MAX" required />
                        <x-button type="submit">{{ __('public.product.add_to_cart') }} <x-line-icon name="cart" /></x-button>
                    </form>
                @else
                    <div class="price">{{ __('public.product.quotation') }}</div>
                    <x-button :href="route($locale.'.contact', ['product' => $product->slug])" arrow>{{ __('public.products.request_quote') }}</x-button>
                    <p class="legal-note">{{ __('public.product.quote_note') }}</p>
                @endif
                <ul class="list-clean">
                    <li>{{ __('public.product.delivery') }}</li>
                    @if ($product->field('compatibility'))
                        <li>{{ $product->field('compatibility') }}</li>
                    @endif
                </ul>
            </div>
        </div>
        <div class="grid two gap">
            <div class="panel">
                <h3>{{ __('public.product.specifications') }}</h3>
                @if ($category)
                    <div class="summary-row"><span>{{ __('public.products.category') }}</span><b>{{ $category->title }}</b></div>
                @endif
                @if ($product->field('specifications'))
                    {{ App\Domain\Content\Markup::html($product->field('specifications')) }}
                @else
                    <x-placeholder />
                @endif
            </div>
            <div class="panel">
                <h3>{{ __('public.product.included') }}</h3>
                @if ($product->field('included'))
                    {{ App\Domain\Content\Markup::html($product->field('included')) }}
                @else
                    <p>{{ __('public.product.included_text') }}</p>
                @endif
                @if ($documents->isNotEmpty())
                    <h3>{{ __('public.product.documents') }}</h3>
                    <ul class="list-clean">
                        @foreach ($documents as $document)
                            <li><a class="text-link" href="{{ $document->getUrl() }}" target="_blank" rel="noopener">{{ $document->name }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
        <h2 class="gap">{{ __('public.product.compatible') }}</h2>
        <div class="notice gap">{{ __('public.product.compatible_notice') }}</div>
        <x-button :href="route($locale.'.products')" variant="secondary" arrow>{{ __('public.product.back') }}</x-button>
    </div>
</x-layout>
