{{-- Информационная cookie-плашка (D-11: только необходимые cookie, выбора аналитики нет; правка 28.10 П5: текст называет
     cookie Google Maps встроенной карты «Kontaktai», поведение плашки прежнее). Показ и скрытие — resources/js/app.js:
     скрыта до проверки, нажимал ли посетитель «Tik būtini» (отметка в localStorage). --}}
<div class="cookie" hidden>
    <p>{{ __('shell.cookie.text') }}</p>
    <div class="buttons">
        <button class="btn small" type="button" data-cookie-accept>{{ __('shell.cookie.accept') }}</button>
        <a class="btn small secondary" href="{{ route(app()->getLocale().'.cookies') }}">{{ __('shell.cookie.settings') }} <x-line-icon name="arrow" /></a>
    </div>
</div>
