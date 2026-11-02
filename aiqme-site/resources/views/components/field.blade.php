{{-- Поле формы прототипа (label.field): input, textarea или select ($options — значение => подпись). Ошибка валидации
     выводится под полем и связана с ним (aria-invalid, aria-describedby); значение после ошибки — old(). $id — когда
     одинаковые поля есть в нескольких формах страницы (окна регистрации на обучение). У поля name[] (файлы) — ошибки
     name и name.*. $picker (файл, правка 02.11, Г3) — своя кнопка «Pasirinkti failus» и строка выбранных имён вместо системных
     «Choose Files / No file chosen»: resources/js/file-field.js скрывает поле (sr-only, фокус остаётся на нём) и показывает
     кнопку; без JS — обычное поле. --}}
@props(['name', 'label', 'type' => 'text', 'value' => null, 'options' => [], 'id' => null, 'picker' => false])
@php
    $id ??= 'field-'.str_replace(['[', ']', '.'], '-', $name);
    $key = str_ends_with($name, '[]') ? substr($name, 0, -2) : $name;
    $error = $errors->first($key) ?: $errors->first($key.'.*');
    $current = old($name, $value);
    $aria = $error ? ['aria-invalid' => 'true', 'aria-describedby' => $id.'-error'] : [];
@endphp
<label class="field" for="{{ $id }}">{{ $label }}
    @if ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" {{ $attributes->merge($aria) }}>{{ $current }}</textarea>
    @elseif ($type === 'select')
        <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->merge($aria) }}>
            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) $optionValue === (string) $current)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @if ($type !== 'password' && $type !== 'file') value="{{ $current }}" @endif {{ $attributes->merge($aria) }}>
        @if ($picker)
            <span class="file-pick" data-file-pick hidden><span class="btn secondary small" aria-hidden="true">{{ __('public.forms.choose_files') }}</span>
                <span class="file-names" data-file-names data-empty="{{ __('public.forms.no_files') }}">{{ __('public.forms.no_files') }}</span></span>
        @endif
    @endif
    @if ($error)
        <small id="{{ $id }}-error">{{ $error }}</small>
    @endif
</label>
