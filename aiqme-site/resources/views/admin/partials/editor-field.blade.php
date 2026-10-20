{{-- Поле перевода редактора: $name, $kind (line|text|lines|pairs из ContentTypes), $locale. У pairs — поле на каждую часть записи. --}}
@php($label = \App\Livewire\Admin\ContentEditor::label($name, $locale))
@if ($kind === 'pairs')
    @foreach ($form[$locale]['fields'][$name] as $index => $entry)
        @foreach (array_keys($entry) as $part)
            <x-field :name="'form.'.$locale.'.fields.'.$name.'.'.$index.'.'.$part" type="textarea" :label="$name.' '.($index + 1).' · '.$part.' '.strtoupper($locale)"
                :value="$entry[$part]" wire:model="form.{{ $locale }}.fields.{{ $name }}.{{ $index }}.{{ $part }}" />
        @endforeach
    @endforeach
@else
    @php($path = in_array($name, ['title', 'slug', 'seo_title', 'seo_description'], true) ? $locale.'.'.$name : $locale.'.fields.'.$name)
    <x-field :name="'form.'.$path" :type="$kind === 'line' ? 'text' : 'textarea'"
        :label="$kind === 'lines' ? $label.' '.__('admin.editor.one_per_line', [], $locale === 'lt' ? 'lt' : 'en') : $label"
        :value="data_get($form, $path)" wire:model="form.{{ $path }}" />
@endif
