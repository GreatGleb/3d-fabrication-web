{{-- Статья (§1.2, §4.1): единственный адрес — библиотека, для любого раздела. Разделы — Markdown-подмножество (Markup::html);
     в notice — только первое предложение прототипа, заметка автора о техдокументах не выводится (D-21). --}}
@use('App\Domain\Content\Markup')
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.library.show', ['slug' => $article->slug])"
    :languages="$languages" :switch-url="$switchUrl" :noindex="$locale === 'ru'">
    <x-page-top :title="$article->title" :intro="$article->field('intro')" :eyebrow="__('public.article.eyebrow')" />
    <div class="wrap page-content">
        <article class="article">
            <span class="tag">{{ __('public.article.tag') }}</span>
            @foreach ($article->field('sections', []) as $section)
                <h2>{{ $section['heading'] }}</h2>
                {!! Markup::html($section['text']) !!}
            @endforeach
            <div class="notice gap">{{ __('public.article.limits') }}</div>
            <x-button :href="route($locale.'.contact')" arrow>{{ __('public.article.ask') }}</x-button>
        </article>
    </div>
</x-layout>
