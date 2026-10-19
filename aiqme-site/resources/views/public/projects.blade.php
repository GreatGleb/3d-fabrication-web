{{-- Проекты (§1.2): фильтры услуги и применения (форма GET, отправка при выборе — resources/js/filters.js), карточки
     опубликованных проектов локали, пустой результат; пока публикаций нет — заглушка (D-11). Без notice и демо-ярлыков (D-21). --}}
@use('App\Http\Controllers\Public\ProjectController')
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.projects')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="__('pages.projects.description')" />
    <div class="wrap page-content">
        @if ($placeholder)
            <x-placeholder />
        @else
            <form class="toolbar" method="get" action="{{ route($locale.'.projects') }}" data-auto-submit>
                <x-field type="select" name="service" :label="__('public.projects.service')" :value="$service"
                    :options="['' => __('public.projects.all_services')] + $services->map->title->all()" />
                <x-field type="select" name="application" :label="__('public.projects.application')" :value="$application"
                    :options="collect(['all', ...ProjectController::APPLICATIONS])->mapWithKeys(fn ($key) => [$key => __('public.projects.applications.'.$key)])->all()" />
            </form>
            <div class="grid three project-grid" id="project-results">
                @forelse ($projects as $project)
                    @include('public.partials.project-card', ['service' => $services->get($project->item->meta('services', [])[0] ?? '')])
                @empty
                    <x-empty :title="__('public.projects.none')" :text="__('public.projects.none_text')" style="grid-column:1/-1" />
                @endforelse
            </div>
        @endif
    </div>
</x-layout>
