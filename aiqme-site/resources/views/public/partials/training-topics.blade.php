{{-- Блок «Mokymų temos ir datos» · «Training topics and dates» — общий для страницы обучения и услуги обучения (§1.2).
     Темы и даты (training) — черновики: в W-05 заглушка, список подключает W-06; ярлыка расписания нет (D-21).
     Панель — вводный текст обучения (page_text training, один источник). --}}
<div class="gap">
    <div class="section-head">
        <h2>{{ __('public.training.topics') }}</h2>
    </div>
    <x-placeholder />
    @if ($training)
        <div class="inset gap">
            <h3>{{ $training->field('intro_title') }}</h3>
            <p>{{ $training->field('intro_text') }}</p>
            <x-button :href="route(app()->getLocale().'.contact')" variant="secondary" arrow>{{ __('public.training.custom') }}</x-button>
        </div>
    @endif
</div>
