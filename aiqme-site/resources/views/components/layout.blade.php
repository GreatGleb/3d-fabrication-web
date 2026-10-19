{{-- Заготовка общей страницы до оболочки W-03. $languages — LT/EN-версии этой страницы для переключателя;
     у noindex-страниц (поиск, /ru) hreflang не выводится. --}}
@props(['title', 'description', 'canonical' => null, 'languages' => [], 'image' => null, 'noindex' => false])
@php($switchTo = app()->getLocale() === 'lt' ? 'en' : 'lt')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<x-seo-head :title="$title" :description="$description" :canonical="$canonical"
    :alternates="$noindex ? [] : $languages" :image="$image" :noindex="$noindex" />
</head>
<body>
<header>
    <a href="{{ $languages[$switchTo] ?? route($switchTo.'.home') }}" hreflang="{{ $switchTo }}" aria-label="{{ __('pages.switch_language') }}">@if ($switchTo === 'en') LT / <span>EN</span> @else <span>LT</span> / EN @endif</a>
</header>
<main id="main">
{{ $slot }}
</main>
</body>
</html>
