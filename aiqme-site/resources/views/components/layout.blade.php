{{-- Общая страница: SEO-head (W-02), шапка, подвал и cookie-плашка оболочки (W-03). $languages — LT/EN-версии
     этой страницы для переключателя; у noindex-страниц (поиск, /ru) hreflang не выводится. $switchUrl — адрес
     переключателя, если версии другой локали нет (сущность ведёт на список раздела, §1.1). Логотип шапки и подвала и
     favicon — свои файлы page_text главной, иначе файлы прототипа по умолчанию (D-43); без БД (страница 500) — умолчания.
     Шапка-шторка (правка 28.10, П2): белый логотип тёмной шапки — свой logo_white, иначе белое умолчание AIQME, но только
     пока нет своего logo (иначе null — обычный логотип на белой подложке); $headerOverlay — полупрозрачная шапка поверх
     hero главной; без JS она сплошная тёмная (noscript-стиль с nonce CSP). --}}
@props(['title', 'description', 'canonical' => null, 'languages' => [], 'image' => null, 'noindex' => false, 'switchUrl' => null, 'headerOverlay' => false])
@use('App\Domain\Content\ContentType')
@use('App\Domain\Content\Models\ContentItem')
@use('Illuminate\Support\Facades\Vite')
@php
    $siteMedia = rescue(fn () => ContentItem::where('type', ContentType::PageText)->where('key', 'home')->with('media')->first(), null, false);
    $ownLogo = $siteMedia?->getFirstMedia('logo');
    $logo = $ownLogo?->getUrl() ?? asset(ContentItem::mediaDefault(ContentType::PageText, 'home', 'logo'));
    $logoWhite = $siteMedia?->getFirstMedia('logo_white')?->getUrl()
        ?? ($ownLogo ? null : asset(ContentItem::mediaDefault(ContentType::PageText, 'home', 'logo_white')));
    $favicon = $siteMedia?->getFirstMedia('favicon');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0a1119">
@if ($favicon)
<link rel="icon" href="{{ $favicon->getUrl() }}" type="image/png">
@else
<link rel="icon" href="{{ asset(ContentItem::mediaDefault(ContentType::PageText, 'home', 'favicon')) }}" type="image/svg+xml">
@endif
<x-seo-head :title="$title" :description="$description" :canonical="$canonical"
    :alternates="$noindex ? [] : $languages" :image="$image" :noindex="$noindex" />
@vite(['resources/css/app.css', 'resources/js/app.js'])
<noscript><style nonce="{{ Vite::cspNonce() }}">.header--overlay{background:var(--dark)}</style></noscript>
</head>
<body>
<x-header :languages="$languages" :switch-url="$switchUrl" :logo="$logo" :logo-white="$logoWhite" :overlay="$headerOverlay" />
<main id="main" tabindex="-1">
{{ $slot }}
</main>
<x-footer :logo="$logo" :logo-white="$logoWhite" :overlay="$headerOverlay" />
<x-cookie-notice />
</body>
</html>
