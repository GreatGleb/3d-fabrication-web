{{-- Карточка товара каталога (productCard прототипа): фото или своя заглушка, бренд, категория, название, «Pagal užklausą» ·
     «On request», «Gauti pasiūlymą» · «Request a quote» (страница товара) и отметка сравнения (resources/js/compare.js).
     Без ярлыков «PLANUOJAMA» · «PLANNED RANGE» и «Tiekimas tikslinamas» · «Supply to be confirmed» (D-21); покупки нет (D-12). --}}
@php($url = route(app()->getLocale().'.products.show', ['slug' => $product->slug]))
<div class="card product-card">
    <a href="{{ $url }}" class="visual">
        @if ($brand = $brands->get($product->item->meta('brand')))
            <span class="tag">{{ $brand->title }}</span>
        @endif
        @include('public.partials.media', ['mediaImage' => $product->item->getFirstMedia('images'), 'mediaSizes' => '(max-width: 700px) 50vw, 300px'])
    </a>
    <div class="card-body">
        @if ($category = $categories->get($product->item->meta('category')))
            <small class="muted">{{ $category->title }}</small>
        @endif
        <a href="{{ $url }}"><h3>{{ $product->title }}</h3></a>
        <div class="price">{{ __('public.products.on_request') }}</div>
        <x-button :href="$url" variant="secondary" small arrow>{{ __('public.products.request_quote') }}</x-button>
        <label class="compare-check"><input type="checkbox" data-compare="{{ $product->slug }}">{{ __('public.products.compare') }}</label>
    </div>
</div>
