@use('App\Support\Seo\StaticPages')
{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($pages as $page)
@php($alternates = StaticPages::alternates($page))
@foreach ($alternates as $url)
<url>
<loc>{{ $url }}</loc>
@foreach ($alternates as $locale => $alternate)
<xhtml:link rel="alternate" hreflang="{{ $locale }}" href="{{ $alternate }}"/>
@endforeach
<xhtml:link rel="alternate" hreflang="x-default" href="{{ $alternates['lt'] }}"/>
</url>
@endforeach
@endforeach
</urlset>
