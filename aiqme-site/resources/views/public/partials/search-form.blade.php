{{-- Панель поиска (searchPanel прототипа) — на странице поиска и в модальном окне шапки. Форма GET ?q= на страницу поиска;
     resources/js/search.js при вводе подставляет результаты с этой страницы, как живой поиск прототипа. --}}
@php($locale = app()->getLocale())
<form class="search-box" method="get" action="{{ route($locale.'.search') }}" role="search" data-live-search>
    <input id="{{ $inputId }}" name="q" type="search" value="{{ $query }}" placeholder="{{ __('public.search.placeholder') }}" aria-label="{{ __('public.search.label') }}" data-modal-focus>
    <x-line-icon name="search" />
</form>
<div id="{{ $resultsId }}" data-search-results aria-live="polite">
    @if ($query === '')
        <p>{{ __('public.search.hint') }}</p>
    @else
        @forelse ($results as $result)
            <a class="search-result" href="{{ $result['url'] }}"><strong>{{ $result['name'] }} ↗</strong><small>{{ $result['description'] }}</small></a>
        @empty
            <x-empty :title="__('public.search.none')" :text="__('public.search.none_text')" icon="search" />
        @endforelse
    @endif
</div>
