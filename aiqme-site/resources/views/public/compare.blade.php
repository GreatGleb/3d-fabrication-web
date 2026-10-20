{{-- Сравнение товаров (§1.2, comparison прототипа, F-04): опубликованные товары локали из ?products=<slug,…> (выбор на странице
     товаров, resources/js/compare.js), не больше 4; таблица при ≥2, иначе пустое состояние. Без демо-notice, строк «Technology»
     (поля технологии нет, D-41), «Delivery» («To be confirmed», D-21) и демо-подписи цены (rules[8]). --}}
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.compare')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="__('pages.compare.description')" />
    <div class="wrap page-content">
        @if ($products->count() < 2)
            <div class="empty">
                <h3>{{ __('public.compare.empty') }}</h3>
                <p>{{ __('public.compare.empty_text') }}</p>
                <x-button :href="route($locale.'.products')" arrow>{{ __('public.compare.open') }}</x-button>
            </div>
        @else
            <x-table>
                <thead>
                    <tr>
                        <th>{{ __('public.compare.feature') }}</th>
                        @foreach ($products as $product)
                            <th>{{ $product->title }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ __('public.products.brand') }}</td>
                        @foreach ($products as $product)
                            <td>{{ $brands->get($product->item->meta('brand'))?->title }}</td>
                        @endforeach
                    </tr>
                    <tr>
                        <td>{{ __('public.products.category') }}</td>
                        @foreach ($products as $product)
                            <td>{{ $categories->get($product->item->meta('category'))?->title }}</td>
                        @endforeach
                    </tr>
                    <tr>
                        <td>{{ __('public.compare.price') }}</td>
                        @foreach ($products as $product)
                            <td>{{ __('public.products.on_request') }}</td>
                        @endforeach
                    </tr>
                    <tr>
                        <td>{{ __('public.compare.compatibility') }}</td>
                        @foreach ($products as $product)
                            <td>{{ $product->field('compatibility') }}</td>
                        @endforeach
                    </tr>
                    <tr>
                        <td></td>
                        @foreach ($products as $product)
                            <td><x-button :href="route($locale.'.products.show', ['slug' => $product->slug])" variant="secondary" small arrow>{{ __('public.compare.view') }}</x-button></td>
                        @endforeach
                    </tr>
                </tbody>
            </x-table>
        @endif
    </div>
</x-layout>
