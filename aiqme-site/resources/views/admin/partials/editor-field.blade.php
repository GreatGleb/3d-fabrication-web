{{-- Поле перевода редактора: $name, $kind (line|text|lines|pairs|records из ContentTypes), $locale. У pairs и records — поле на
     каждую часть записи (у records последняя запись пустая — для новой; очищенная запись удаляется). --}}
@php($label = \App\Livewire\Admin\ContentEditor::label($name, $locale))
@if ($kind === 'pairs' || $kind === 'records')
    {{-- Подписи блоков page_text — имена блоков (W-08); у records — названия поля и части. --}}
    @php($caption = fn (string $field): string => $kind === 'records' ? \App\Livewire\Admin\ContentEditor::name($field, $locale) : $field)
    @foreach ($form[$locale]['fields'][$name] as $index => $entry)
        @foreach (array_keys($entry) as $part)
            <x-field :name="'form.'.$locale.'.fields.'.$name.'.'.$index.'.'.$part" type="textarea"
                :label="$caption($name).' '.($index + 1).' · '.$caption($part).' '.strtoupper($locale)"
                :value="$entry[$part]" wire:model="form.{{ $locale }}.fields.{{ $name }}.{{ $index }}.{{ $part }}" />
        @endforeach
    @endforeach
@else
    @php($path = in_array($name, ['title', 'slug', 'seo_title', 'seo_description'], true) ? $locale.'.'.$name : $locale.'.fields.'.$name)
    <x-field :name="'form.'.$path" :type="$kind === 'line' ? 'text' : 'textarea'"
        :label="$kind === 'lines' ? $label.' '.__('admin.editor.one_per_line', [], $locale === 'lt' ? 'lt' : 'en') : $label"
        :value="data_get($form, $path)" wire:model="form.{{ $path }}" />
@endif
