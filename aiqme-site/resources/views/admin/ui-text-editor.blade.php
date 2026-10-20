{{-- Редактор строки интерфейса (W-26): LT и EN рядом, RU ниже; сохранение применяется сразу, пустое поле — текст файла. --}}
<div id="ui-text-editor" class="gap">
    <form class="panel admin-editor" id="ui-text-form" wire:submit="save">
        <h2>{{ __('admin.editor.heading') }}</h2>
        <p><code>{{ $textKey }}</code></p>
        <x-status :message="$conflict" type="error" />
        <x-status :message="$status" />
        <div class="grid two form-grid">
            @foreach (['lt', 'en'] as $locale)
                <x-field :name="'values.'.$locale" type="textarea" :label="\App\Livewire\Admin\ContentEditor::label('value', $locale)"
                    :value="$values[$locale]" wire:model="values.{{ $locale }}" />
            @endforeach
        </div>
        <x-field name="values.ru" type="textarea" :label="\App\Livewire\Admin\ContentEditor::label('value', 'ru')" :value="$values['ru']" wire:model="values.ru" />
        <p>{{ __('admin.ui_text.hint') }}</p>
        <x-button type="submit">{{ __('admin.editor.save') }}</x-button>
    </form>
</div>
