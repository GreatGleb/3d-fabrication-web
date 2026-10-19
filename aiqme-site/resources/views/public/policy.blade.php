{{-- Политика (доставка, возвраты, приватность, cookies; §1.2, D-11): пока текст — черновик, только заголовок и заглушка;
     опубликованный текст — вводный абзац, предупреждение и разделы (Markdown-подмножество, Markup::html). --}}
@use('App\Domain\Content\Markup')
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.'.$page)" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="$policy?->field('intro')" />
    <div class="wrap page-content article">
        @if (! $policy)
            <x-placeholder />
        @else
            @if (filled($policy->field('notice')))
                <div class="notice warning">{{ $policy->field('notice') }}</div>
            @endif
            @foreach ($policy->field('sections', []) as $section)
                <h2>{{ $section['heading'] }}</h2>
                {!! Markup::html($section['text']) !!}
            @endforeach
        @endif
    </div>
</x-layout>
