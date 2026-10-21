{{-- Saved models (account() прототипа): STL из своих заказов (кнопки «сохранить модель» нет, §1.3) — скачать через
     защищённую выдачу, пересчитать в калькуляторе (?models=<id>), скрыть из списка (файл остаётся у заказа, §6.4).
     Пояснение прототипа о метаданных сессии браузера не переносится: модели хранятся на сервере. --}}
<div id="account-models">
    <h2>{{ __('account.models.heading') }}</h2>
    <x-status class="gap" />
    @forelse ($models as $model)
        <div class="file-row" data-model-id="{{ $model->id }}">
            <x-line-icon name="box" />
            <strong>{{ $model->original_name }}</strong>
            <small>{{ __('calculator.order.title', ['number' => $numbers[$model->order_id]]) }}</small>
            <div class="buttons">
                <a class="btn small secondary" href="{{ route('stl.download', $model->id) }}">{{ __('account.models.download') }}</a>
                <a class="btn small secondary" href="{{ route($locale.'.calculator', ['models' => $model->id]) }}" data-recalculate>{{ __('account.models.recalculate') }}</a>
                <form method="post" action="{{ route($locale.'.account.models.hide', $model->id) }}">
                    @csrf
                    <button class="btn small secondary" type="submit">{{ __('account.models.hide') }}</button>
                </form>
            </div>
        </div>
    @empty
        <x-empty class="gap" :title="__('account.models.empty')">
            <x-button :href="route($locale.'.calculator')">{{ __('account.models.upload') }}</x-button>
        </x-empty>
    @endforelse
</div>
