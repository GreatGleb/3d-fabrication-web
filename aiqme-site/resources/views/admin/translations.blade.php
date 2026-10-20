{{-- Вкладка «LT / EN turinys» · «LT / EN content» (§1.4 Translations): строки интерфейса (ui_text) фиксированного набора —
     ключи lang/ групп AdminController::UI_TEXT_GROUPS, не конструктор. Группа — ?group=, правка — ?edit=<ключ> под списком.
     Значение в списке — действующее (переопределение из БД или текст файла). --}}
@php($locale = app()->getLocale())
@component('admin.shell', ['tab' => $tab])
    <h2>{{ __('admin.tabs.translations') }}</h2>
    <nav class="buttons gap" aria-label="{{ __('admin.ui_text.groups_label') }}">
        @foreach (\App\Http\Controllers\Admin\AdminController::UI_TEXT_GROUPS as $name)
            <a @class(['btn', 'small', 'secondary' => $name !== $group]) href="{{ route($locale.'.admin.translations', ['group' => $name]) }}" @if ($name === $group) aria-current="true" @endif>{{ __('admin.ui_text.groups.'.$name) }}</a>
        @endforeach
    </nav>
    <x-table class="gap">
        <thead><tr><th>{{ __('admin.ui_text.key') }}</th><th>LT</th><th>EN</th><th></th></tr></thead>
        <tbody>
            @foreach ($keys as $key)
                <tr>
                    <td>{{ $key }}</td>
                    <td>{{ __($key, [], 'lt') }}</td>
                    <td>{{ __($key, [], 'en') }}</td>
                    <td><a class="btn small secondary" href="{{ route($locale.'.admin.translations', ['group' => $group, 'edit' => $key]) }}#ui-text-editor">{{ __('admin.list.edit') }}</a></td>
                </tr>
            @endforeach
        </tbody>
    </x-table>
    @if ($editing)
        <livewire:admin.ui-text-editor :text-key="$editing" :key="'ui-text-'.$editing" />
    @endif
@endcomponent
