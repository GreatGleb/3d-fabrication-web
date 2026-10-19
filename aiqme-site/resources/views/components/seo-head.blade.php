{{-- SEO-head (§4.4): canonical и hreflang — только URL из route(); hreflang — только существующие версии, x-default → LT. --}}
@props(['title', 'description', 'canonical' => null, 'alternates' => [], 'image' => null, 'noindex' => false])
<title>{{ $title }} | AIQME</title>
<meta name="description" content="{{ $description }}">
<meta name="robots" content="{{ $noindex ? 'noindex, nofollow' : 'index, follow' }}">
@if ($canonical)
<link rel="canonical" href="{{ $canonical }}">
@endif
@foreach ($alternates as $locale => $url)
<link rel="alternate" hreflang="{{ $locale }}" href="{{ $url }}">
@endforeach
@isset($alternates['lt'])
<link rel="alternate" hreflang="x-default" href="{{ $alternates['lt'] }}">
@endisset
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
@if ($image)
<meta property="og:image" content="{{ $image }}">
@endif
