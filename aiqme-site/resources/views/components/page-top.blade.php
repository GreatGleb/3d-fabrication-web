{{-- Верх страницы прототипа (pageTop): хлебные крошки, заголовок и вводный текст. Перенос строки в заголовке
     хранится как \n (§7.2): в h1 — <br>, в хлебных крошках — пробел. Ярлыка над h1 нет (правка 30.10, Г2). --}}
@props(['title', 'intro' => null, 'crumbs' => [], 'breadcrumbs' => true])
<section class="page-top">
    <div class="wrap">
        @if ($breadcrumbs)
            <x-breadcrumbs :current="Str::squish($title)" :items="$crumbs" />
        @endif
        <h1>{!! nl2br(e($title), false) !!}</h1>
        @if ($intro)
            <p>{{ $intro }}</p>
        @endif
    </div>
</section>
