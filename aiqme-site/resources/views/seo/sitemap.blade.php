{{-- $groups — версии одной страницы: locale => URL (только опубликованные); x-default → LT, если LT-версия есть. --}}
{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($groups as $alternates)
@foreach ($alternates as $url)
<url>
<loc>{{ $url }}</loc>
@foreach ($alternates as $locale => $alternate)
<xhtml:link rel="alternate" hreflang="{{ $locale }}" href="{{ $alternate }}"/>
@endforeach
@isset($alternates['lt'])
<xhtml:link rel="alternate" hreflang="x-default" href="{{ $alternates['lt'] }}"/>
@endisset
</url>
@endforeach
@endforeach
</urlset>
