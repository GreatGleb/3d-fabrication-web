{{-- Вкладка контента (services — услуги, content — тексты страниц): список LT / EN с состоянием публикации и общий редактор
     под списком (?edit=<id>, у услуг ещё ?new=1 — «Add a draft +»). Панель «Drafts in this session» прототипа не переносится:
     состояние языков видно в списке и в редакторе. --}}
@php
    $locale = app()->getLocale();
@endphp
@component('admin.shell', ['tab' => $tab])
    <div class="topline">
        <h2>{{ __('admin.tabs.'.$tab) }}</h2>
        @if ($canAdd)
            <a class="btn small" href="{{ route($locale.'.admin.'.$tab, ['new' => 1]) }}#content-editor">{{ __('admin.list.add') }}</a>
        @endif
    </div>
    <x-table>
        <thead><tr><th>{{ __('admin.list.title') }}</th><th>{{ __('admin.list.languages') }}</th><th></th></tr></thead>
        <tbody>
            @foreach ($items as $item)
                @php
                    $translation = $item->translation($locale) ?? $item->translation('lt') ?? $item->translations->first();
                    $published = collect(['lt', 'en'])->filter(fn ($code) => $item->translation($code)?->isPublished());
                @endphp
                <tr>
                    <td>{{ Str::squish($translation?->title ?? $translation?->latestRevision()?->title ?? $item->key) }}</td>
                    <td>
                        @if ($published->count() === 2)
                            <span class="tag">{{ __('admin.list.both') }}</span>
                        @elseif ($published->isNotEmpty())
                            <span class="tag">{{ strtoupper($published->first()) }}</span>
                        @else
                            <span class="tag">{{ __('admin.list.draft') }}</span>
                        @endif
                    </td>
                    <td><a class="btn small secondary" href="{{ route($locale.'.admin.'.$tab, ['edit' => $item->id]) }}#content-editor">{{ __('admin.list.edit') }}</a></td>
                </tr>
            @endforeach
        </tbody>
    </x-table>
    @if ($editing)
        <livewire:admin.content-editor :type="$type->value" :item-id="$editing->id" :key="'editor-'.$editing->id" />
    @elseif ($creating)
        <livewire:admin.content-editor :type="$type->value" key="editor-new" />
    @endif
@endcomponent
