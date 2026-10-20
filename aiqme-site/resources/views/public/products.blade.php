{{-- Товары (§1.2, catalog прототипа, F-03): фильтры категории и производителя, поиск по названию, сортировки «Recommended» и
     «Name A–Z» (форма GET; выбор в select отправляет форму — resources/js/filters.js), карточки, пустой результат, лента
     опубликованных брендов; выбор для сравнения — resources/js/compare.js. Пока публикаций нет — заглушка (§4.2).
     Без notice списка, brand-note, фильтров технологии и цены и сортировки по цене (D-21, D-36, D-41). --}}
@use('App\Http\Controllers\Public\ProductController')
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.products')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="__('pages.products.description')" />
    <div class="wrap page-content">
        @if ($placeholder)
            <x-placeholder />
        @else
            <form class="catalog" method="get" action="{{ route($locale.'.products') }}" data-auto-submit>
                <details class="filters" open>
                    <summary><x-line-icon name="settings" /> {{ __('public.products.filters') }}</summary>
                    <x-field type="select" name="category" :label="__('public.products.category')" :value="$category"
                        :options="['' => __('public.products.all_categories')] + $categories->mapWithKeys(fn ($item) => [$item->slug => $item->title])->all()" />
                    <x-field type="select" name="brand" :label="__('public.products.brand')" :value="$brand"
                        :options="['' => __('public.products.all_brands')] + $brands->map->title->all()" />
                    <x-button :href="route($locale.'.products')" variant="secondary" small>{{ __('public.products.clear') }}</x-button>
                    <div class="help-box">
                        <h3>{{ __('public.products.help_title') }}</h3>
                        <p>{{ __('public.products.help_text') }}</p>
                        <a class="text-link" href="{{ route($locale.'.contact') }}">{{ __('public.products.help_link') }}</a>
                    </div>
                </details>
                <div>
                    <div class="toolbar">
                        <div class="search-box">
                            <input id="catalog-search" name="q" type="search" value="{{ $query }}" placeholder="{{ __('public.products.search') }}" aria-label="{{ __('public.products.search_label') }}">
                            <x-line-icon name="search" />
                        </div>
                        <x-field type="select" name="sort" :label="__('public.products.sort')" :value="$sort"
                            :options="collect(ProductController::SORTS)->mapWithKeys(fn ($key) => [$key => __('public.products.sorts.'.$key)])->all()" />
                    </div>
                    <p id="result-count" class="legal-note" style="margin:0 0 18px">{{ __('public.products.count', ['count' => $products->count()]) }}</p>
                    <div id="product-results" class="grid product-grid">
                        @forelse ($products as $product)
                            @include('public.partials.product-card')
                        @empty
                            <x-empty :title="__('public.products.none')" :text="__('public.products.none_text')" style="grid-column:1/-1">
                                <x-button :href="route($locale.'.products')" variant="secondary">{{ __('public.products.clear') }}</x-button>
                            </x-empty>
                        @endforelse
                    </div>
                </div>
            </form>
            <div class="compare-bar" data-compare-bar data-compare-key="aiqme-compare-{{ $locale }}" data-compare-limit="{{ ProductController::COMPARE_LIMIT }}" hidden>
                <span>{{ __('public.products.selected') }}: <span data-compare-count>0</span>/{{ ProductController::COMPARE_LIMIT }}</span>
                <span data-compare-message role="status" hidden>{{ __('public.products.compare_limit') }}</span>
                <a class="btn small" href="{{ route($locale.'.compare') }}" data-compare-link>{{ __('public.products.compare') }}</a>
                <button class="icon-btn" type="button" data-compare-clear aria-label="{{ __('public.products.clear_compare') }}"><x-line-icon name="close" /></button>
            </div>
        @endif
        @if ($brands->isNotEmpty())
            <div class="brand-row">
                @foreach ($brands as $item)
                    <span>{{ $item->title }}</span>
                @endforeach
            </div>
        @endif
    </div>
</x-layout>
