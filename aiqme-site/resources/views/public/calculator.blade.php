{{-- Калькулятор (§1.2, W-27) на ядре W-10, resources/js/calculator/.
     Правка 05.11, H-04 (поверх Г8 ниже): три шага — «Failai», «Gamybos parametrai» (карточки и в каждой проверка файла: размеры,
     масштаб, предел оборудования, «Kainą įvertins specialistas»), «Užklausos pateikimas» (форма); шагов «Patikra» и «Kaina»
     нет. Сумм покупатель не видит: панель — «имя × количество» и заметка; тарифов в data-settings нет (оценку считает сервер).
     Заголовка «01. Įkelkite savo modelius» нет (макет).
     Без VAT, доставки, скидки и цены за единицу (D-38), без демо-надписей, файлов-примеров и флажка сценария риска (D-21).
     Просмотр модели — .viewer, Canvas 2D (W-31). Последний шаг отправляет заявку fetch-запросом multipart (W-12): токен
     отправки — UUID формы, профиль вошедшего клиента ($buyer) подставлен в поля.
     Правка 02.11, Г8 (макет 17.09): шаг 1 принимает STL/STEP/OBJ/DXF/DWG; шаг 2 — карточка на каждый файл (её строит JS
     в [data-cards]: способ, параметры, количество, приоритет, дата, заметка).
     data-settings — названия материалов печати, заполнение по умолчанию, габарит, списки фрезеровки и гравировки и их предел из настроек админки
     (W-11, Г6) и лимиты файлов из config('aiqme.calculator'); тексты для JS — data-texts, значки icons/ui — data-icons. --}}
@php
    $locale = app()->getLocale();
    $maxMb = intdiv($settings['max_file_bytes'], 1024 * 1024);
    $texts = __('calculator.js') + ['mb' => $maxMb, 'scale' => __('calculator.files.scale')] + collect(__('calculator'))->only(['settings', 'methods', 'part', 'review', 'price'])->all();
@endphp
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.calculator')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="__('calculator.intro')" />
    <div class="wrap page-content" id="quote" data-locale="{{ $locale }}" data-settings='@json($settings)'
         data-texts='@json($texts)'
         data-icons="{{ asset('icons/ui') }}" data-contact-url="{{ $contactUrl }}">
        <div class="steps">
            @foreach (__('calculator.steps') as $index => $step)
                <button type="button" @class(['step', 'active' => $index === 0]) data-step="{{ $index }}" @disabled($index > 0)><b>{{ $index + 1 }}</b>{{ $step }}</button>
            @endforeach
        </div>
        <div class="quote-layout">
            <div>
                <section class="panel" data-step-panel="0">
                    <p>{{ __('calculator.files.intro') }}</p>
                    {{-- Вся зона — label поля выбора: системная кнопка «Browse… No files selected» скрыта, вместо неё .btn сайта. --}}
                    <label class="upload" id="drop-zone">
                        <x-line-icon name="upload" />
                        <strong>{{ __('calculator.files.drop') }}</strong>
                        <input type="file" id="model-upload" class="sr-only" multiple accept=".stl,.step,.stp,.obj,.dxf,.dwg" aria-label="{{ __('calculator.files.select') }}">
                        <span class="btn secondary small" aria-hidden="true">{{ __('calculator.files.select') }}</span>
                        <p>{{ __('calculator.files.limit', ['mb' => $maxMb]) }}</p>
                    </label>
                    <div id="upload-progress" role="status"></div>
                    <div data-files></div>
                    <div data-model hidden>
                        <div class="viewer" data-viewer><span class="viewer-label">{{ __('calculator.viewer.label') }}</span>
                            <canvas id="model-canvas" tabindex="0" role="img" aria-label="{{ __('calculator.viewer.aria') }}"></canvas>
                            <span class="viewer-dim" data-dimensions></span>
                            <div class="viewer-controls"><button type="button" data-zoom-in aria-label="{{ __('calculator.viewer.zoom_in') }}">+</button><button type="button" data-zoom-out aria-label="{{ __('calculator.viewer.zoom_out') }}">−</button><button type="button" data-reset-view aria-label="{{ __('calculator.viewer.reset') }}">↺</button></div></div>
                        <div class="grid two form-grid">
                            <x-field name="unit" type="select" id="model-unit" :label="__('calculator.files.units')"
                                     :options="['mm' => 'mm', 'cm' => 'cm', 'in' => __('calculator.files.inches')]" />
                            <x-field name="scale" type="number" id="model-scale" :label="__('calculator.files.scale')" value="100" min="1" max="1000" />
                        </div>
                        <button type="button" class="btn secondary small" data-apply-scale data-modal-open="scale-confirm">{{ __('calculator.files.apply_scale') }}</button>
                        <p class="legal-note">{{ __('calculator.files.scale_note') }}</p>
                    </div>
                </section>

                <section class="panel" data-step-panel="1" hidden>
                    {{-- Доработка 09.11, I-01: заголовка «02. …» шага 2 нет, как и у шага 1 (макет рев.4, п.11). --}}
                    <p>{{ __('calculator.part.intro') }}</p>
                    <div data-cards></div>
                    <p class="part-note gap"><img class="ui-icon" src="{{ asset('icons/ui/info.svg') }}" alt="" width="20" height="20">{{ __('calculator.part.hint') }}</p>
                    <div data-warnings hidden>
                        <div class="notice warning gap" data-warning-list></div>
                        <a class="btn gap" href="{{ $contactUrl }}" data-specialist>{{ __('calculator.review.specialist') }} <x-line-icon name="arrow" /></a>
                    </div>
                </section>

                <form class="panel" id="checkout-form" data-step-panel="2" hidden novalidate method="post" action="{{ Route::has($locale.'.calculator.store') ? route($locale.'.calculator.store') : '' }}">
                    @csrf
                    <input type="hidden" name="submission_token" value="{{ \Illuminate\Support\Str::uuid() }}">
                    @include('public.partials.honeypot')
                    <h2>{{ __('calculator.checkout.title') }}</h2>
                    <p>{{ __('calculator.checkout.intro') }}</p>
                    <div class="grid two form-grid">
                        <x-field name="name" :label="__('calculator.checkout.name')" :value="$buyer['name'] ?? null" required autocomplete="name" />
                        <x-field name="email" type="email" :label="__('calculator.checkout.email')" :value="$buyer['email'] ?? null" required autocomplete="email" />
                        <x-field name="company" :label="__('calculator.checkout.company')" :value="$buyer['company'] ?? null" autocomplete="organization" />
                        <x-field name="company_code" :label="__('calculator.checkout.company_code')" :value="$buyer['company_code'] ?? null" />
                        <x-field name="vat_code" :label="__('calculator.checkout.vat_code')" :value="$buyer['vat_code'] ?? null" />
                        <x-field name="phone" type="tel" :label="__('calculator.checkout.phone')" required autocomplete="tel" />
                    </div>
                    <x-field name="delivery" type="select" :label="__('calculator.checkout.delivery')" :options="__('calculator.checkout.deliveries')" />
                    <div class="grid two form-grid" data-address>
                        <x-field name="country" type="select" :label="__('calculator.checkout.country')" :options="__('calculator.countries')" required />
                        <x-field name="city" :label="__('calculator.checkout.city')" :value="$buyer['city'] ?? null" required autocomplete="address-level2" />
                        <x-field name="address" :label="__('calculator.checkout.address')" :value="$buyer['address'] ?? null" required autocomplete="street-address" />
                        <x-field name="postcode" :label="__('calculator.checkout.postcode')" :value="$buyer['postcode'] ?? null" required autocomplete="postal-code" />
                    </div>
                    <div class="quote-actions">
                        <button type="button" class="btn secondary" data-back>← {{ __('calculator.back') }}</button>
                        <button type="submit" class="btn" @disabled(! Route::has($locale.'.calculator.store'))>{{ __('calculator.checkout.submit') }} <x-line-icon name="arrow" /></button>
                    </div>
                </form>

                <div class="quote-actions" data-actions>
                    <button type="button" class="btn secondary" data-back disabled>← {{ __('calculator.back') }}</button>
                    <button type="button" class="btn" data-next data-label-last="{{ __('calculator.to_checkout') }}"><span>{{ __('calculator.next') }}</span> <x-line-icon name="arrow" /></button>
                </div>
                <div id="quote-error" role="alert" class="gap"></div>
            </div>

            <aside class="panel quote-summary">
                <h3>{{ __('calculator.summary.title') }}</h3>
                <div data-summary-rows></div>
                <div class="notice summary-note">{{ __('calculator.summary.note') }}</div>
                <div class="help-box">
                    <h3>{{ __('calculator.help.title') }}</h3>
                    <p>{{ __('calculator.help.text') }}</p>
                    <a class="text-link icon-btn" href="{{ $contactUrl }}" data-specialist>{{ __('calculator.help.link') }}</a>
                </div>
            </aside>
        </div>
    </div>

    <x-modal id="scale-confirm" :title="__('calculator.scale_modal.title')">
        <p class="gap" data-scale-text></p>
        <p class="gap">{{ __('calculator.scale_modal.note') }}</p>
        <button type="button" class="btn gap" data-scale-confirm data-modal-close>{{ __('calculator.scale_modal.confirm') }}</button>
    </x-modal>
</x-layout>
