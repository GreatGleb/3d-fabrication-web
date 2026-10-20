{{-- Карточка проекта (§1.2): фото (медиа, иначе фото прототипа по умолчанию для ключа D-43, иначе своя заглушка), задача → решение → результат, связанные услуги, контакт,
     YouTube-ссылка при наличии (meta.youtube, только https; поле редактора прототипа «YouTube URL (optional)»).
     Без notice и демо-подписи вводного текста прототипа (D-21). --}}
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.projects.show', ['slug' => $project->slug])"
    :languages="$languages" :switch-url="$switchUrl" :image="$ogImage" :noindex="$locale === 'ru'">
    <x-page-top :title="$project->title" />
    <div class="wrap page-content">
        <div class="project-image">
            @include('public.partials.media', ['mediaImage' => $image, 'mediaDefault' => $project->item->defaultMedia('images'), 'mediaAlt' => $project->title,
                'mediaSizes' => '100vw', 'mediaLazy' => false])
        </div>
        <div class="project-story">
            @foreach (['task', 'solution', 'result'] as $part)
                <article>
                    <span class="eyebrow">{{ __('public.projects.'.$part) }}</span>
                    <p>{{ $project->field($part) }}</p>
                </article>
            @endforeach
        </div>
        <div class="panel gap">
            <h3>{{ __('public.projects.related') }}</h3>
            <div class="buttons">
                @foreach ($services as $service)
                    <a class="btn secondary" href="{{ route($locale.'.services.show', ['slug' => $service->slug]) }}">{{ $service->title }} ↗</a>
                @endforeach
                @if ($youtube)
                    <a class="btn secondary" href="{{ $youtube }}" target="_blank" rel="noopener noreferrer">YouTube ↗</a>
                @endif
                <x-button :href="route($locale.'.contact')" arrow>{{ __('public.projects.similar') }}</x-button>
            </div>
        </div>
    </div>
</x-layout>
