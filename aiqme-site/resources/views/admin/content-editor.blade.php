{{-- Общий редактор контента (W-08, W-26; content-edit-form прототипа): атрибуты элемента, поля перевода LT и EN рядом, RU —
     ниже; публикация и снятие по каждому языку; медиа элемента. «Save as a draft» прототипа нет: сохранение всегда черновик, публикация — отдельной кнопкой. --}}
@php
    $locales = \App\Domain\Content\ContentService::LOCALES;
    $rows = array_merge(['title' => 'line'], $item->type->hasSlug() ? ['slug' => 'line'] : [],
        array_map(fn (array $field): string => $field['kind'], $fields),
        $item->type->hasSeo() ? ['seo_title' => 'line', 'seo_description' => 'text'] : []);
@endphp
<div id="content-editor" class="gap">
    <form class="panel admin-editor" id="content-edit-form" data-type="{{ $type }}" wire:submit="save">
        <h2>{{ __('admin.editor.heading') }}</h2>
        <x-status :message="$conflict" type="error" />
        <x-status :message="$status" />
        @if ($item->exists)
            <div class="admin-publication">
                @foreach ($locales as $locale)
                    <div class="buttons" data-locale="{{ $locale }}">
                        <strong>{{ strtoupper($locale) }}</strong>
                        <span @class(['tag', 'blue' => $states[$locale]['key'] === 'published', 'orange' => $states[$locale]['key'] === 'changes'])>{{ __('admin.editor.state.'.$states[$locale]['key']) }}</span>
                        @if ($states[$locale]['key'] !== 'none')
                            <button type="button" class="btn small" wire:click="publish('{{ $locale }}')">{{ __('admin.editor.publish', ['locale' => strtoupper($locale)]) }}</button>
                        @endif
                        @if ($states[$locale]['published'])
                            <button type="button" class="btn small secondary" wire:click="unpublish('{{ $locale }}')">{{ __('admin.editor.unpublish', ['locale' => strtoupper($locale)]) }}</button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
        @if ($metaFields)
            {{-- Атрибуты элемента (meta) — общие для языков, применяются при сохранении. --}}
            <div class="grid two form-grid" id="content-meta">
                @foreach ($metaFields as $name => $field)
                    @if ($field['kind'] === 'choices')
                        <div class="field" role="group" aria-labelledby="meta-{{ $name }}-label">
                            <span id="meta-{{ $name }}-label">{{ __('admin.meta.'.$name) }}</span>
                            @foreach ($field['options'] as $value => $optionLabel)
                                <label class="checks"><input type="checkbox" value="{{ $value }}" wire:model="meta.{{ $name }}"> {{ $optionLabel }}</label>
                            @endforeach
                            @error('meta.'.$name.'.*') <small>{{ $message }}</small> @enderror
                        </div>
                    @elseif ($field['kind'] === 'select')
                        <x-field :name="'meta.'.$name" type="select" :label="__('admin.meta.'.$name)" :value="$meta[$name]" wire:model="meta.{{ $name }}"
                            :options="['' => '—'] + $field['options']" />
                    @else
                        <x-field :name="'meta.'.$name" :type="$field['kind']" :label="__('admin.meta.'.$name)" :value="$meta[$name]" wire:model="meta.{{ $name }}" />
                    @endif
                @endforeach
            </div>
        @endif
        @foreach ($rows as $name => $kind)
            <div class="grid two form-grid">
                @foreach (['lt', 'en'] as $locale)
                    @include('admin.partials.editor-field')
                @endforeach
            </div>
        @endforeach
        <h3>RU</h3>
        @foreach ($rows as $name => $kind)
            @include('admin.partials.editor-field', ['locale' => 'ru'])
        @endforeach
        <x-button type="submit">{{ __('admin.editor.save') }}</x-button>
    </form>

    @if ($item->exists && $media)
        <section class="panel gap" id="content-media">
            <h3>{{ __('admin.media.heading') }}</h3>
            @forelse ($files as $file)
                <p class="admin-media-file">
                    <span class="tag">{{ __('admin.media.collections.'.$file->collection_name) }}</span>
                    <a class="text-link" href="{{ $file->getUrl() }}" target="_blank" rel="noopener">{{ $file->file_name }} ↗</a>
                    {{ collect($file->getCustomProperty('alt', []))->map(fn ($text, $locale) => strtoupper($locale).': '.$text)->join(' · ') }}
                    <button type="button" class="btn small secondary" wire:click="removeMedia({{ $file->id }})">{{ __('admin.media.remove') }}</button>
                </p>
            @empty
                <p>{{ __('admin.media.none') }}</p>
            @endforelse
            <form id="media-upload-form" wire:submit="uploadMedia">
                <div class="grid two form-grid">
                    <x-field name="collection" type="select" :label="__('admin.media.collection')" :value="$collection" wire:model="collection"
                        :options="collect($media)->keys()->mapWithKeys(fn ($name) => [$name => __('admin.media.collections.'.$name)])->all()" />
                    <x-field name="upload" type="file" :label="__('admin.media.file', ['max' => intdiv(\App\Domain\Content\MediaFile::maxKilobytes(), 1024)])"
                        wire:model="upload" accept="image/jpeg,image/png,image/webp,video/mp4" />
                    <x-field name="alt.lt" :label="\App\Livewire\Admin\ContentEditor::label('alt', 'lt')" wire:model="alt.lt" maxlength="500" />
                    <x-field name="alt.en" :label="\App\Livewire\Admin\ContentEditor::label('alt', 'en')" wire:model="alt.en" maxlength="500" />
                    <x-field name="sourceText" :label="__('admin.media.source')" wire:model="sourceText" maxlength="255" />
                    <x-field name="sourceUrl" type="url" :label="__('admin.media.source_url')" wire:model="sourceUrl" maxlength="500" />
                </div>
                <x-button type="submit" wire:loading.attr="disabled" wire:target="upload,uploadMedia">{{ __('admin.media.upload') }}</x-button>
            </form>
        </section>
    @endif
</div>
