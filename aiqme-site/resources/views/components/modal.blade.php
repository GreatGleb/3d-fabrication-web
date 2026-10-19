{{-- Модальное окно прототипа (.search-overlay > .search-panel). Открывается кнопкой с data-modal-open="<id>",
     закрывается крестиком, Escape и щелчком по фону; ловушка Tab, inert фона и возврат фокуса — resources/js/modal.js. --}}
@props(['id', 'title'])
<div class="search-overlay" id="{{ $id }}" data-modal hidden>
    <section class="search-panel" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
        <div class="topline">
            <span class="eyebrow" style="margin:0">AIQME</span>
            <button class="icon-btn" type="button" data-modal-close aria-label="{{ __('shell.close') }}"><x-line-icon name="close" /></button>
        </div>
        <h2 id="{{ $id }}-title">{{ $title }}</h2>
        {{ $slot }}
    </section>
</div>
