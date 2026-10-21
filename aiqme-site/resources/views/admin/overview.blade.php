{{-- Overview (§1.4): заголовок прототипа и заявки контактной формы (W-09) — список со статусом письма и заявка целиком
     (?request=<id>, ссылка из письма AIQME): поля, полный текст, вложения (скачивание), ошибка письма. Метрики, последние
     заказы и предупреждение о неудачных фоновых заданиях — Livewire Overview (W-14). Демо-метрики, заказ DEMO-1047 и notice «…simulated in the browser» не
     переносятся (D-21). --}}
@php
    $locale = app()->getLocale();
@endphp
@component('admin.shell', ['tab' => $tab])
    <h2>{{ __('admin.overview.heading') }}</h2>
    <livewire:admin.overview />
    @if ($shown)
        <div class="panel gap" id="request-{{ $shown->id }}">
            <h3>{{ __('admin.requests.contact_item', ['id' => $shown->id]) }}</h3>
            @php
                $rows = [
                    'time' => $shown->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                    'name' => $shown->name,
                    'company' => $shown->company,
                    'email' => $shown->email,
                    'phone' => $shown->phone,
                    'service' => $serviceTitles[$shown->service] ?? $shown->service,
                    'deadline' => $shown->deadline?->format('Y-m-d'),
                    'language' => strtoupper($shown->locale),
                    'description' => $shown->description,
                ];
            @endphp
            <x-table>
                <tbody>
                    @foreach ($rows as $field => $value)
                        <tr><th>{{ __('admin.requests.fields.'.$field) }}</th><td style="white-space:pre-line">{{ $value ?? '—' }}</td></tr>
                    @endforeach
                    <tr><th>{{ __('admin.requests.fields.files') }}</th>
                        <td>
                            @forelse ($shown->attachments as $index => $file)
                                <a class="text-link" href="{{ route($locale.'.admin.request-file', ['contactRequest' => $shown->id, 'index' => $index]) }}">{{ $file['name'] }}</a>
                                ({{ Number::fileSize($file['size']) }})<br>
                            @empty
                                —
                            @endforelse
                        </td>
                    </tr>
                    <tr><th>{{ __('admin.requests.fields.mail') }}</th><td>@include('admin.partials.mail-status', ['event' => $shown->event])</td></tr>
                    @if ($shown->event?->mail_error)
                        <tr><th>{{ __('admin.requests.fields.mail_error') }}</th><td>{{ $shown->event->mail_error }}</td></tr>
                    @endif
                </tbody>
            </x-table>
        </div>
    @endif
    <h3 class="gap">{{ __('admin.requests.contact_heading') }}</h3>
    <x-table>
        <thead><tr><th>{{ __('admin.requests.fields.time') }}</th><th>{{ __('admin.requests.fields.name') }}</th><th>{{ __('admin.requests.fields.service') }}</th><th>{{ __('admin.requests.fields.files') }}</th><th>{{ __('admin.requests.fields.mail') }}</th><th></th></tr></thead>
        <tbody>
            @forelse ($requests as $item)
                <tr>
                    <td>{{ $item->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                    <td>{{ $item->name }}<br><small>{{ $item->email }}</small></td>
                    <td>{{ $serviceTitles[$item->service] ?? $item->service }}</td>
                    <td>{{ count($item->attachments) }}</td>
                    <td>@include('admin.partials.mail-status', ['event' => $item->event])</td>
                    <td><a class="btn small secondary" href="{{ route($locale.'.admin', ['request' => $item->id]) }}#request-{{ $item->id }}">{{ __('admin.requests.open') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="6">{{ __('admin.requests.contact_empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-table>
@endcomponent
