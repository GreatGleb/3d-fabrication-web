{{-- Калькулятор (§1.2, W-27): 5 шагов прототипа (quote()) на ядре W-10, расчёт — в браузере, resources/js/calculator/.
     Без выбора технологии (FDM, D-37), без VAT, доставки, скидки и цены за единицу (D-38), без демо-надписей, файлов-примеров
     и флажка сценария риска (D-21). Просмотр модели — .viewer, Canvas 2D (W-31). Шаг 5 до W-12 не отправляется.
     data-settings — тарифы, заполнение по умолчанию и габарит из настроек админки (W-11) и лимиты файлов из
     config('aiqme.calculator'); тексты для JS — data-texts. --}}
@php
    $locale = app()->getLocale();
    $infills = collect([20, 40, 60, 100, $settings['default_infill']])->unique()->sort()->values();
    $maxMb = intdiv($settings['max_file_bytes'], 1024 * 1024);
@endphp
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.calculator')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" :intro="__('calculator.intro')" :eyebrow="__('calculator.eyebrow')" />
    <div class="wrap page-content" id="quote" data-locale="{{ $locale }}" data-settings='@json($settings)'
         data-texts='@json(__('calculator.js') + ['mb' => $maxMb])' data-contact-url="{{ $contactUrl }}">
        <div class="steps">
            @foreach (__('calculator.steps') as $index => $step)
                <button type="button" @class(['step', 'active' => $index === 0]) data-step="{{ $index }}" @disabled($index > 0)><b>{{ $index + 1 }}</b>{{ $step }}</button>
            @endforeach
        </div>
        <div class="quote-layout">
            <div>
                <section class="panel" data-step-panel="0">
                    <h2>{{ __('calculator.files.title') }}</h2>
                    <p>{{ __('calculator.files.intro') }}</p>
                    <div class="upload" id="drop-zone">
                        <x-line-icon name="upload" />
                        <strong>{{ __('calculator.files.drop') }}</strong>
                        <input type="file" id="model-upload" multiple accept=".stl,.step,.stp,.obj" aria-label="{{ __('calculator.files.select') }}">
                        <p>{{ __('calculator.files.limit', ['mb' => $maxMb]) }}</p>
                    </div>
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
                    <h2>{{ __('calculator.settings.title') }}</h2>
                    <p>{{ __('calculator.settings.intro') }}</p>
                    <div class="grid two form-grid">
                        <x-field name="material" type="select" :label="__('calculator.settings.material')"
                                 :options="array_combine(array_keys($settings['materials']), array_keys($settings['materials']))" />
                        <x-field name="color" type="select" :label="__('calculator.settings.color')" :options="__('calculator.settings.colors')" />
                        <x-field name="quality" type="select" :label="__('calculator.settings.quality')" :options="__('calculator.settings.qualities')" />
                        <x-field name="finish" type="select" :label="__('calculator.settings.finish')" :options="__('calculator.settings.finishes')" />
                    </div>
                    <div class="inset">
                        <h3>{{ __('calculator.settings.fdm') }}</h3>
                        <div class="grid two form-grid">
                            <x-field name="layer" type="select" :label="__('calculator.settings.layer')" :options="__('calculator.settings.layers')" value="0.2" />
                            <x-field name="infill" type="select" :label="__('calculator.settings.infill')" :value="$settings['default_infill']"
                                     :options="$infills->mapWithKeys(fn ($value) => [$value => __('calculator.settings.infill_option', ['value' => $value])
                                         .($value === $settings['default_infill'] ? __('calculator.settings.recommended') : '')])->all()" />
                        </div>
                        <p>{{ __('calculator.settings.fdm_note') }}</p>
                    </div>
                    <div class="gap">
                        <x-field name="term" type="select" :label="__('calculator.settings.term')" :options="__('calculator.settings.terms')" />
                        <p class="legal-note">{{ __('calculator.settings.preferences_note') }}</p>
                    </div>
                    <h3 class="gap">{{ __('calculator.settings.quantities') }}</h3>
                    <div data-quantities></div>
                </section>

                <section class="panel" data-step-panel="2" hidden>
                    <h2>{{ __('calculator.review.title') }}</h2>
                    <div data-review></div>
                    <div class="check-row">
                        <x-line-icon name="check" />
                        <div>
                            <h3>{{ __('calculator.review.build') }}</h3>
                            <p>{{ __('calculator.review.build_text', ['mm' => $settings['max_dimension_mm']]) }}</p>
                        </div>
                    </div>
                    <div data-warnings hidden>
                        <div class="notice warning gap" data-warning-list></div>
                        <a class="btn gap" href="{{ $contactUrl }}" data-specialist>{{ __('calculator.review.specialist') }} <x-line-icon name="arrow" /></a>
                    </div>
                </section>

                <section class="panel" data-step-panel="3" hidden>
                    <h2>{{ __('calculator.price.title') }}</h2>
                    <div class="data-table">
                        <table>
                            <thead><tr><th>{{ __('calculator.price.part') }}</th><th>{{ __('calculator.js.quantity') }}</th><th>{{ __('calculator.price.estimate') }}</th></tr></thead>
                            <tbody data-price-rows></tbody>
                        </table>
                    </div>
                    <div class="notice gap">{{ __('calculator.price.note') }}</div>
                </section>

                <form class="panel" id="checkout-form" data-step-panel="4" hidden novalidate>
                    <h2>{{ __('calculator.checkout.title') }}</h2>
                    <p>{{ __('calculator.checkout.intro') }}</p>
                    <div class="grid two form-grid">
                        <x-field name="name" :label="__('calculator.checkout.name')" required autocomplete="name" />
                        <x-field name="email" type="email" :label="__('calculator.checkout.email')" required autocomplete="email" />
                        <x-field name="company" :label="__('calculator.checkout.company')" autocomplete="organization" />
                        <x-field name="company_code" :label="__('calculator.checkout.company_code')" />
                        <x-field name="vat_code" :label="__('calculator.checkout.vat_code')" />
                        <x-field name="phone" type="tel" :label="__('calculator.checkout.phone')" required autocomplete="tel" />
                    </div>
                    <x-field name="delivery" type="select" :label="__('calculator.checkout.delivery')" :options="__('calculator.checkout.deliveries')" />
                    <div class="grid two form-grid" data-address>
                        <x-field name="country" type="select" :label="__('calculator.checkout.country')" :options="__('calculator.countries')" required />
                        <x-field name="city" :label="__('calculator.checkout.city')" required autocomplete="address-level2" />
                        <x-field name="address" :label="__('calculator.checkout.address')" required autocomplete="street-address" />
                        <x-field name="postcode" :label="__('calculator.checkout.postcode')" required autocomplete="postal-code" />
                    </div>
                    <div class="quote-actions">
                        <button type="button" class="btn secondary" data-back>← {{ __('calculator.back') }}</button>
                        <button type="submit" class="btn" disabled aria-describedby="quote-unavailable">{{ __('calculator.checkout.submit') }} <x-line-icon name="arrow" /></button>
                    </div>
                    <p class="legal-note gap" id="quote-unavailable">{{ __('calculator.checkout.unavailable') }}</p>
                </form>

                <div class="quote-actions" data-actions>
                    <button type="button" class="btn secondary" data-back disabled>← {{ __('calculator.back') }}</button>
                    <button type="button" class="btn" data-next data-label-last="{{ __('calculator.to_checkout') }}"><span>{{ __('calculator.next') }}</span> <x-line-icon name="arrow" /></button>
                </div>
                <div id="quote-error" role="alert" class="gap"></div>
            </div>

            <aside class="panel quote-summary">
                <div class="eyebrow">{{ __('calculator.summary.eyebrow') }}</div>
                <h3>{{ __('calculator.summary.title') }}</h3>
                <div data-summary-rows></div>
                <div class="summary-row total"><span>{{ __('calculator.summary.total') }}</span><span data-total></span></div>
                <p class="legal-note">{{ __('calculator.summary.note') }}</p>
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
