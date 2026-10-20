{{-- Карточка опубликованного проекта (projectCard прототипа) без демо-ярлыков (D-21): услуга ($service — перевод или null),
     название, задача. --}}
<a class="card project-card" href="{{ route(app()->getLocale().'.projects.show', ['slug' => $project->slug]) }}">
    <div class="project-image">
        @include('public.partials.media', ['mediaImage' => $project->item->getFirstMedia('images'), 'mediaDefault' => $project->item->defaultMedia('images'),
            'mediaAlt' => $project->title, 'mediaSizes' => '(max-width: 760px) 100vw, 50vw'])
    </div>
    <div class="card-body">
        @if ($service)
            <p>{{ $service->title }}</p>
        @endif
        <h3>{{ $project->title }}</h3>
        <p class="project-summary">{{ $project->field('task') }}</p>
        <span class="text-link">{{ __('public.service.view_project') }}</span>
    </div>
</a>
