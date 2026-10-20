{{-- Страница услуги (§1.2, §4.1). Иллюстрация — медиа услуги, иначе её фрагмент прототипа по умолчанию (D-43), иначе своя заглушка, без подписи прототипа (D-21, F-47). --}}
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.services.show', ['slug' => $service->slug])"
    :languages="$languages" :switch-url="$switchUrl" :image="$image" :noindex="$locale === 'ru'">
    <x-page-top :title="$service->title" :intro="$service->field('short')" :eyebrow="__('public.service.eyebrow')" />
    <div class="wrap page-content">
        <div class="split">
            <div>
                <div class="eyebrow">{{ __('public.service.when') }}</div>
                <h2>{!! nl2br(e(__('public.service.need')), false) !!}</h2>
                <p class="gap">{{ $service->field('description') }}</p>
                <ul class="list-clean">
                    @foreach ($service->field('problems', []) as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
                {{-- CTA печати — калькулятор, остальных — контакты с предвыбранной услугой (§1.2, контракт ?service=). --}}
                <x-button :href="$key === 'printing' ? route($locale.'.calculator') : route($locale.'.contact', ['service' => $service->slug])" arrow>{{ __('public.service.enquiry') }}</x-button>
            </div>
            <div>
                <div class="media-frame">
                    @include('public.partials.media', ['mediaImage' => $illustration, 'mediaDefault' => $service->item->defaultMedia('images'), 'mediaAlt' => $service->title, 'mediaSizes' => '(max-width: 900px) 100vw, 50vw', 'mediaLazy' => false])
                </div>
            </div>
        </div>
        <div class="grid two gap">
            <div class="panel">
                <div class="eyebrow">{{ __('public.service.input') }}</div>
                <h3>{{ __('public.service.provide') }}</h3>
                <p>{{ $service->field('provide') }}</p>
            </div>
            <div class="panel">
                <div class="eyebrow">{{ __('public.service.output') }}</div>
                <h3>{{ __('public.service.receive') }}</h3>
                <p>{{ $service->field('receive') }}</p>
            </div>
        </div>
        @if ($key === 'training')
            @include('public.partials.training-topics', ['trainingSlug' => $service->slug])
        @endif
        @if ($home)
            <section class="section">
                <div class="section-head"><h2>{{ __('public.service.workflow') }}</h2></div>
                <div class="section dark panel">@include('public.partials.process')</div>
            </section>
        @endif
        @if ($project)
            <div class="section-head"><h2>{{ __('public.service.example') }}</h2></div>
        @endif
        <div class="grid two">
            @if ($project)
                @include('public.partials.project-card')
            @endif
            <div class="panel">
                <h3>{{ __('public.service.one_contact') }}</h3>
                <p>{{ __('public.service.one_contact_text') }}</p>
                <x-button :href="route($locale.'.contact')" arrow>{{ __('public.service.similar') }}</x-button>
            </div>
        </div>
        @if ($questions->isNotEmpty())
            <h2 class="gap">{{ __('public.service.faq') }}</h2>
            @foreach ($questions as $question)
                <details class="faq"><summary>{{ $question->title }}</summary><p>{{ $question->field('answer') }}</p></details>
            @endforeach
        @endif
    </div>
    @include('public.partials.cta')
</x-layout>
