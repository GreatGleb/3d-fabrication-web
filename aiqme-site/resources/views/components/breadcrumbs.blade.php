{{-- Хлебные крошки прототипа: «Pagrindinis / … / текущая». $items — [подпись => URL] промежуточных страниц. --}}
@props(['current', 'items' => []])
<div class="breadcrumbs">
    <a href="{{ route(app()->getLocale().'.home') }}">{{ __('shell.nav.home') }}</a>
    @foreach ($items as $label => $url)
        <span>/</span><a href="{{ $url }}">{{ $label }}</a>
    @endforeach
    <span>/</span><span>{{ $current }}</span>
</div>
