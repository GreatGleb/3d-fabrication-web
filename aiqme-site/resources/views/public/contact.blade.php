{{-- Контакты (§1.2): реквизиты из page_text, ссылка на карту (без встроенной карты Google: внешний сервис, CSP и D-20),
     форма проектного обращения. Серверную обработку формы добавляет W-09; демо-надписи формы не переносятся (D-21). --}}
@php
    $locale = app()->getLocale();
    $serviceOptions = ['' => __('public.contact.choose_service')]
        + $services->mapWithKeys(fn ($service) => [$service->slug => $service->title])->all()
        + ['equipment' => __('public.contact.equipment')];
@endphp
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.contact')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="__('pages.contact.description')" />
    <div class="wrap page-content">
        @if (! $contact)
            <x-placeholder />
        @else
            <div class="split" style="align-items:start">
                <div>
                    <h2>{{ $contact->field('heading') }}</h2>
                    <ul class="list-clean contact-details">
                        @foreach ($contact->field('details', []) as $detail)
                            <li><strong>{{ $detail['label'] }}</strong>
                                @if (filter_var($detail['value'], FILTER_VALIDATE_EMAIL))
                                    <a href="mailto:{{ $detail['value'] }}">{{ $detail['value'] }}</a>
                                @elseif (preg_match('/^\+[\d\s]{7,}$/', $detail['value']))
                                    <a href="tel:{{ preg_replace('/\s+/', '', $detail['value']) }}">{{ $detail['value'] }}</a>
                                @else
                                    {{ $detail['value'] }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <div class="contact-map">
                        <a class="text-link" href="{{ __('public.contact.map_url') }}" target="_blank" rel="noopener noreferrer">{{ __('public.contact.map') }}</a>
                    </div>
                    <p class="legal-note">{{ $contact->field('visit_note') }}</p>
                </div>
                <form class="panel" id="contact-form" method="post" action="{{ route($locale.'.contact') }}" enctype="multipart/form-data">
                    @csrf
                    <h2>{{ $contact->field('form_title') }}</h2>
                    <p>{{ $contact->field('form_note') }}</p>
                    <div class="grid two form-grid">
                        <x-field name="name" :label="__('public.contact.name')" required autocomplete="name" />
                        <x-field name="company" :label="__('public.contact.company')" autocomplete="organization" />
                    </div>
                    <div class="grid two form-grid">
                        <x-field name="email" type="email" :label="__('public.contact.email')" required autocomplete="email" />
                        <x-field name="phone" type="tel" :label="__('public.contact.phone')" autocomplete="tel" />
                    </div>
                    <x-field name="service" type="select" :label="__('public.contact.service')" :options="$serviceOptions" required />
                    <x-field name="description" type="textarea" :label="__('public.contact.description')" required minlength="10" />
                    <x-field name="attachments[]" type="file" :label="__('public.contact.files')" multiple accept=".stl,.step,.stp,.obj,.pdf,.jpg,.jpeg,.png" />
                    <x-field name="deadline" type="date" :label="__('public.contact.deadline')" />
                    <x-button type="submit" arrow>{{ $contact->field('form_submit') }}</x-button>
                </form>
            </div>
        @endif
    </div>
</x-layout>
