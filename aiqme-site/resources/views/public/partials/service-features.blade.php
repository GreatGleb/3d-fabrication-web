{{-- Блоки услуг главной и списка услуг (serviceFeatureRows прототипа): номер в углу медиа, название, описание, три пункта
     и действия; подпись «● NN / label» над названием убрана по правке 28.10 (П4). Иллюстрация — медиа услуги, иначе фрагмент прототипа по умолчанию (D-43, alt — название услуги), иначе своя заглушка; подписи «Illustrative image» нет (D-21). --}}
@php($locale = app()->getLocale())
<div class="service-features">
    @foreach ($services as $service)
        @php($key = $service->item->key)
        <article class="service-feature" aria-labelledby="service-heading-{{ $key }}">
            <div @class(['service-feature-media', 'service-feature-media--light' => in_array($key, ['engraving', 'training'], true)])>
                <span class="service-feature-number" aria-hidden="true">{{ sprintf('%02d', $loop->iteration) }}</span>
                @include('public.partials.media', ['mediaImage' => $service->item->getFirstMedia('images'), 'mediaDefault' => $service->item->defaultMedia('images'),
                    'mediaAlt' => $service->title, 'mediaSizes' => '(max-width: 760px) 100vw, 50vw'])
            </div>
            <div class="service-feature-copy">
                <h3 id="service-heading-{{ $key }}">{{ $service->title }}</h3>
                <p>{{ $service->field('description') }}</p>
                <ul class="service-feature-benefits">
                    @foreach ($service->field('benefits', []) as $benefit)
                        <li><x-line-icon name="check" /><span>{{ $benefit }}</span></li>
                    @endforeach
                </ul>
                <div class="service-feature-actions">
                    <x-button :href="route($locale.'.services.show', ['slug' => $service->slug])" arrow>{{ __('public.service_features.explore') }}</x-button>
                    @if ($key === 'printing')
                        <a class="text-link" href="{{ route($locale.'.calculator') }}">{{ __('public.service_features.quote') }}</a>
                    @elseif ($key === 'training')
                        <a class="text-link" href="{{ route($locale.'.training') }}">{{ __('public.service_features.training') }}</a>
                    @else
                        <a class="text-link" href="{{ route($locale.'.contact') }}">{{ __('public.service_features.contact') }}</a>
                    @endif
                </div>
            </div>
        </article>
    @endforeach
</div>
