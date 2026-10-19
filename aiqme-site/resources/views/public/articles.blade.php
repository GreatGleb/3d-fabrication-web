{{-- Информация, новости, библиотека (§1.2, infoPage прототипа): вкладки, поиск и категория (форма GET; выбор категории
     отправляет форму — resources/js/filters.js), карточки статей (адрес библиотеки), пустой результат; пока в разделе нет
     публикаций — заглушка. Фильтра «Language» нет; панель техдокументов — заглушка вместо заметки автора (D-21). --}}
@use('App\Http\Controllers\Public\ArticleController')
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.'.$page)" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="__('pages.'.$page.'.description')" />
    <div class="wrap page-content">
        <div class="tabs">
            @foreach (ArticleController::TABS as $tab)
                <a href="{{ route($locale.'.'.$tab) }}" @class(['active' => $tab === $page])>{{ __('public.resources.tabs.'.$tab) }}</a>
            @endforeach
        </div>
        @if ($placeholder)
            <x-placeholder />
        @else
            <form class="toolbar" method="get" action="{{ route($locale.'.'.$page) }}" data-auto-submit>
                <div class="search-box">
                    <input id="resource-search" name="q" type="search" value="{{ $query }}" placeholder="{{ __('public.resources.search') }}" aria-label="{{ __('public.resources.search_label') }}">
                    <x-line-icon name="search" />
                </div>
                <x-field type="select" name="category" :label="__('public.resources.category')" :value="$category"
                    :options="['' => __('public.resources.all_topics')] + collect(ArticleController::CATEGORIES)->mapWithKeys(fn ($key) => [$key => __('public.resources.categories.'.$key)])->all()" />
            </form>
            <div class="grid three" id="resource-results">
                @forelse ($articles as $article)
                    @php($section = $article->item->meta('section') === 'news' ? __('public.ticker.article') : __('public.resources.library'))
                    @php($topic = in_array($article->item->meta('category'), ArticleController::CATEGORIES, true) ? __('public.resources.card_categories.'.$article->item->meta('category')) : null)
                    <a class="card service-card resource-card" href="{{ route($locale.'.library.show', ['slug' => $article->slug]) }}">
                        <div class="card-body">
                            <span class="eyebrow">{{ $section }}{{ $topic ? ' / '.$topic : '' }}</span>
                            <h3 class="gap">{{ $article->title }}</h3>
                            <p>{{ $article->field('intro') }}</p>
                            <span class="text-link">{{ __('public.resources.read') }}</span>
                        </div>
                    </a>
                @empty
                    <x-empty :title="__('public.resources.none')" :text="__('public.resources.none_text')" style="grid-column:1/-1" />
                @endforelse
            </div>
        @endif
        <div class="panel gap">
            <h3>{{ __('public.resources.documents') }}</h3>
            <x-placeholder />
            <span class="tag">{{ __('public.resources.documents_count') }}</span>
        </div>
    </div>
</x-layout>
