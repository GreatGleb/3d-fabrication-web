{{-- Верх страницы прототипа (pageTop): хлебные крошки, ярлык, заголовок и вводный текст. --}}
@props(['title', 'intro' => null, 'eyebrow' => 'AIQME', 'crumbs' => [], 'breadcrumbs' => true])
<section class="page-top">
    <div class="wrap">
        @if ($breadcrumbs)
            <x-breadcrumbs :current="$title" :items="$crumbs" />
        @endif
        <div class="eyebrow">{{ $eyebrow }}</div>
        <h1>{{ $title }}</h1>
        @if ($intro)
            <p>{{ $intro }}</p>
        @endif
    </div>
</section>
