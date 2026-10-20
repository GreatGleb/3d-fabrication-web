{{-- Журнал (§1.4 Audit, W-08): последние 100 записей журнала audit — время, канал, действие, объект и данные события
     (публикации контента W-04; события заказов и оплаты добавляют W-13, W-16, W-28). Колонка «Role» прототипа заменена
     каналом (один администратор); «Try the administration demo.» не переносится (D-21). --}}
@component('admin.shell', ['tab' => $tab])
    <h2>{{ __('admin.audit.heading') }}</h2>
    <x-table class="gap">
        <thead><tr><th>{{ __('admin.audit.time') }}</th><th>{{ __('admin.audit.channel') }}</th><th>{{ __('admin.audit.action') }}</th><th>{{ __('admin.audit.object') }}</th><th>{{ __('admin.audit.details') }}</th></tr></thead>
        <tbody>
            @forelse ($entries as $entry)
                @php
                    $channel = $entry->properties['channel'] ?? null;
                    $subject = $entry->subject;
                @endphp
                <tr>
                    <td>{{ $entry->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                    <td>{{ $channel ? __('admin.audit.channels.'.$channel) : '' }}</td>
                    <td>{{ trans()->has('admin.audit.events.'.$entry->event) ? __('admin.audit.events.'.$entry->event) : $entry->event }}</td>
                    <td>{{ $subject instanceof \App\Domain\Content\Models\ContentTranslation ? Str::squish($subject->title ?? $subject->latestRevision()?->title).' ('.$subject->type->value.')' : class_basename($entry->subject_type).' #'.$entry->subject_id }}</td>
                    <td>{{ collect($entry->properties)->except('channel')->map(fn ($value, $name) => $name.': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)))->join(' · ') }}</td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('admin.audit.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-table>
@endcomponent
