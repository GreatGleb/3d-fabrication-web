{{-- Блок «Mokymų temos ir datos» · «Training topics and dates» — общий для страницы обучения и услуги обучения (§1.2).
     Опубликованные темы и даты (training) или заглушка; ярлыка расписания нет (D-21). Детальной страницы нет: запись —
     в списке; кнопка открывает модальную регистрацию (W-09, поля training-form прототипа; без JS — ссылка в контакты
     с предвыбранной услугой обучения). После ошибки проверки окно этой темы открывается снова, после отправки —
     окно подтверждения (data-modal-autoopen). Панель — вводный текст обучения (page_text training, один источник). --}}
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
                    <a class="btn" href="{{ route($locale.'.contact', array_filter(['service' => $trainingSlug])) }}" data-training="{{ $topic->item->key }}" data-modal-open="training-modal-{{ $topic->item->id }}">{{ __('public.training.register') }}</a>
                </div>
            @endforeach
        </div>
        @foreach ($trainings as $topic)
            @php($modal = 'training-modal-'.$topic->item->id)
            <x-modal :id="$modal" :title="__('public.training.modal_title')" :data-modal-autoopen="(int) old('training') === $topic->item->id ? '' : null">
                <form id="training-form-{{ $topic->item->id }}" class="training-form" method="post" action="{{ route($locale.'.training.store') }}">
                    @csrf
                    <input type="hidden" name="training" value="{{ $topic->item->id }}">
                    <p class="gap">{{ $topic->title }} · {{ $topic->item->meta('date') }}</p>
                    <x-field :id="$modal.'-name'" name="name" :label="__('public.training.name')" required autocomplete="name" />
                    <x-field :id="$modal.'-email'" name="email" type="email" :label="__('public.training.email')" required autocomplete="email" />
                    <x-field :id="$modal.'-format'" name="format" type="select" :label="__('public.training.format')" :options="__('public.training.formats')" value="online" />
                    <x-field :id="$modal.'-participants'" name="participants" type="number" :label="__('public.training.participants')" value="1" min="1" max="20" required />
                    @include('public.partials.honeypot')
                    @error('form')
                        <div class="notice error gap" role="alert">{{ $message }}</div>
                    @enderror
                    <x-button type="submit">{{ __('public.training.submit') }}</x-button>
                </form>
            </x-modal>
        @endforeach
        @if (session()->has('training_registered'))
            <x-modal id="training-confirmation" :title="__('public.training.confirmed')" data-modal-autoopen :data-modal-return="'training-modal-'.session('training_registered')">
                <div class="notice success gap" role="status">{{ __('public.training.confirmed_text') }}</div>
            </x-modal>
        @endif
    @endif
    @if ($training)
        <div class="inset gap">
            <h3>{{ $training->field('intro_title') }}</h3>
            <p>{{ $training->field('intro_text') }}</p>
            <x-button :href="route($locale.'.contact')" variant="secondary" arrow>{{ __('public.training.custom') }}</x-button>
        </div>
    @endif
</div>
