{{-- Финальная CTA-полоса прототипа: текст — page_text главной (один источник, W-04). --}}
@php($locale = app()->getLocale())
@if ($home)
    <section class="cta-band">
        <div class="wrap">
            <div>
                <h2>{{ $home->field('cta_title') }}</h2>
                <p>{{ $home->field('cta_text') }}</p>
            </div>
            <div class="buttons">
                <x-button :href="route($locale.'.calculator')" variant="white" arrow>{{ __('public.cta.quote') }}</x-button>
                <x-button :href="route($locale.'.contact')" variant="ghost" arrow>{{ __('public.cta.contact') }}</x-button>
            </div>
        </div>
    </section>
@endif
