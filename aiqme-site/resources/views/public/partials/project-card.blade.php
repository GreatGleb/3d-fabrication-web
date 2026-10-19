{{-- Карточка опубликованного проекта (projectCard прототипа) без демо-ярлыков (D-21). Карточку проекта создаёт W-06:
     до неё ссылка ведёт на список проектов. --}}
@use('Illuminate\Support\Facades\Route')
@php
    $locale = app()->getLocale();
    $url = Route::has($locale.'.projects.show') ? route($locale.'.projects.show', ['slug' => $project->slug]) : route($locale.'.projects');
@endphp
<a class="card project-card" href="{{ $url }}">
    <div class="project-image">
        @include('public.partials.media', ['mediaImage' => $project->item->getFirstMedia('images'), 'mediaSizes' => '(max-width: 760px) 100vw, 50vw'])
    </div>
    <div class="card-body">
        <p>{{ $service->title }}</p>
        <h3>{{ $project->title }}</h3>
        <p class="project-summary">{{ $project->field('task') }}</p>
        <span class="text-link">{{ __('public.service.view_project') }}</span>
    </div>
</a>
