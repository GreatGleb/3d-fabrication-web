{{-- Вкладка контента: списки типов вкладки (AdminController::SECTIONS) — LT / EN с состоянием публикации — и общий редактор
     под списками (?edit=<id>, ?new=<тип> — «Add a draft +»). Первый список — под заголовком вкладки, остальные — под своим
     заголовком h3. Во вкладке товаров — панель категорий прототипа со ссылками на редактор категории. Панель «Drafts in this
     session» прототипа не переносится: состояние языков видно в списке и в редакторе. --}}
@php
    $locale = app()->getLocale();
@endphp
@component('admin.shell', ['tab' => $tab])
    @foreach ($sections as $type => $items)
        <div @class(['topline', 'gap' => ! $loop->first])>
            @if ($loop->first)
                <h2>{{ __('admin.tabs.'.$tab) }}</h2>
            @else
                <h3>{{ __('admin.sections.'.$type) }}</h3>
            @endif
            @if (\App\Http\Controllers\Admin\AdminController::SECTIONS[$tab][$type])
                <a class="btn small" href="{{ route($locale.'.admin.'.$tab, ['new' => $type]) }}#content-editor">{{ trans()->has('admin.list.add_'.$type) ? __('admin.list.add_'.$type) : __('admin.list.add') }}</a>
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
    @endforeach
    @if ($editing)
        <livewire:admin.content-editor :type="$editing->type->value" :item-id="$editing->id" :key="'editor-'.$editing->id" />
    @elseif ($creating)
        <livewire:admin.content-editor :type="$creating->value" :key="'editor-new-'.$creating->value" />
    @endif
    @isset ($categories)
        <div class="panel gap" id="category-list">
            <h3>{{ __('admin.sections.category') }}</h3>
            @foreach ($categories as $category)
                @php($translation = $category->translation($locale) ?? $category->translation('lt'))
                <div class="summary-row">
                    <span>{{ $translation?->title ?? $translation?->latestRevision()?->title ?? $category->key }}</span>
                    <a class="text-link" href="{{ route($locale.'.admin.categories', ['edit' => $category->id]) }}#content-editor">{{ __('admin.list.edit') }} ↗</a>
                </div>
            @endforeach
        </div>
    @endisset
@endcomponent
