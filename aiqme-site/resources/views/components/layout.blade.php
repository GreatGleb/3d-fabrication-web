{{-- Общая страница: SEO-head (W-02), шапка, подвал и cookie-плашка оболочки (W-03). $languages — LT/EN-версии
     этой страницы для переключателя; у noindex-страниц (поиск, /ru) hreflang не выводится. --}}
@props(['title', 'description', 'canonical' => null, 'languages' => [], 'image' => null, 'noindex' => false])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0a1119">
<x-seo-head :title="$title" :description="$description" :canonical="$canonical"
    :alternates="$noindex ? [] : $languages" :image="$image" :noindex="$noindex" />
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<x-header :languages="$languages" />
<main id="main" tabindex="-1">
{{ $slot }}
</main>
<x-footer />
<x-cookie-notice />
</body>
</html>
