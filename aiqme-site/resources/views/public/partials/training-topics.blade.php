{{-- Блок «Mokymų temos ir datos» · «Training topics and dates» — общий для страницы обучения и услуги обучения (§1.2).
     Опубликованные темы и даты (training) или заглушка; ярлыка расписания нет (D-21). Детальной страницы нет: запись —
     в списке; кнопка ведёт в контакты с предвыбранной услугой обучения, модальную регистрацию подключает W-09.
     Панель — вводный текст обучения (page_text training, один источник). --}}
@php($locale = app()->getLocale())
<div class="gap">
    <div class="section-head">
        <h2>{{ __('public.training.topics') }}</h2>
    </div>
    @if ($trainings->isEmpty())
        <x-placeholder />
    @else
        <div class="grid two">
            @foreach ($trainings as $topic)
                <div class="panel">
                    <span class="tag blue">{{ $topic->item->meta('date') }}</span>
                    <h3 class="gap">{{ $topic->title }}</h3>
                    <p>{{ $topic->field('description') }}</p>
                    <a class="btn" href="{{ route($locale.'.contact', array_filter(['service' => $trainingSlug])) }}" data-training="{{ $topic->item->key }}">{{ __('public.training.register') }}</a>
                </div>
            @endforeach
        </div>
    @endif
    @if ($training)
        <div class="inset gap">
            <h3>{{ $training->field('intro_title') }}</h3>
            <p>{{ $training->field('intro_text') }}</p>
            <x-button :href="route($locale.'.contact')" variant="secondary" arrow>{{ __('public.training.custom') }}</x-button>
        </div>
    @endif
</div>
