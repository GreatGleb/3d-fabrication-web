{{-- Капча ALTCHA (правка 28.10 П3) над кнопкой отправки: задача — наш GET /altcha, решение при фокусе в форме (auto=onfocus),
     скрытое поле altcha добавляет виджет (атрибут name не задаётся — по умолчанию altcha). Подпись — LT или EN (на /ru — EN),
     подвал «ALTCHA» скрыт. Скрипт виджета подгружает app.js, только если виджет есть на странице. --}}
<altcha-widget challenge="{{ route('altcha', absolute: false) }}" auto="onfocus" language="{{ app()->getLocale() === 'lt' ? 'lt' : 'en' }}" configuration='{"hideFooter":true}'></altcha-widget>
@error('altcha')
    <div class="notice error gap" role="alert">{{ $message }}</div>
@enderror
