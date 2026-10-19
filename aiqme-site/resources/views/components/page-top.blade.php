{{-- Верх страницы прототипа (pageTop): хлебные крошки, ярлык, заголовок и вводный текст. Перенос строки в заголовке
     хранится как \n (§7.2): в h1 — <br>, в хлебных крошках — пробел. --}}
@props(['title', 'intro' => null, 'eyebrow' => 'AIQME', 'crumbs' => [], 'breadcrumbs' => true])
<section class="page-top">
    <div class="wrap">
        @if ($breadcrumbs)
            <x-breadcrumbs :current="Str::squish($title)" :items="$crumbs" />
        @endif
        <div class="eyebrow">{{ $eyebrow }}</div>
        <h1>{!! nl2br(e($title), false) !!}</h1>
        @if ($intro)
            <p>{{ $intro }}</p>
        @endif
    </div>
</section>
