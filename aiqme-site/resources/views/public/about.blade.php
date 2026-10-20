{{-- «О нас» (§1.2): вводный блок и три панели из page_text; фото — медиа, иначе engineering.jpg прототипа (D-43, alt пустой: в прототипе это фон), без подписи прототипа;
     блока команды нет до реальных данных (D-32). --}}
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.about')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="__('pages.about.description')" />
    <div class="wrap page-content">
        @if (! $about)
            <x-placeholder />
        @else
            <div class="split">
                <div>
                    <div class="eyebrow">{{ $about->field('intro_eyebrow') }}</div>
                    <h2>{!! nl2br(e($about->field('intro_title')), false) !!}</h2>
                    @foreach ($about->field('intro_paragraphs', []) as $paragraph)
                        <p class="gap">{{ $paragraph }}</p>
                    @endforeach
                </div>
                <div class="split-image">
                    @include('public.partials.media', ['mediaImage' => $about->item->getFirstMedia('images'), 'mediaDefault' => $about->item->defaultMedia('images'), 'mediaSizes' => '(max-width: 900px) 100vw, 50vw'])
                </div>
            </div>
            <div class="grid three gap">
                @foreach ($about->field('panels', []) as $panel)
                    <div class="panel"><h3>{{ $panel['title'] }}</h3><p>{{ $panel['text'] }}</p></div>
                @endforeach
            </div>
        @endif
    </div>
    @include('public.partials.cta')
</x-layout>
