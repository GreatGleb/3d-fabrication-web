{{-- Главная (§1.2): тёмный hero с видео (медиа заказчика, иначе набор прототипа по умолчанию D-43; сцены «СКАН → CAD →
     ДЕТАЛЬ» нет, D-40), сценарии, процесс, услуги, ленты брендов и новостей (заглушка без ленты, пока нет публикаций;
     D-26), категории, CTA. Видео по умолчанию — один набор: при своём video мобильный ролик и постер — только свои,
     подпись — из источника своего медиа (§7.3). --}}
@use('App\Domain\Content\ContentType')
@use('App\Domain\Content\Models\ContentItem')
@use('Illuminate\Support\Facades\Route')
@php
    $locale = app()->getLocale();
    $video = $heroMedia?->getFirstMedia('video');
    $posterMedia = $heroMedia?->getFirstMedia('video_poster');
    // Свой файл слота, иначе (пока нет своего video) — умолчание этого слота.
    $own = ['mobile' => $heroMedia?->getFirstMedia('video_mobile')?->getUrl(),
        'poster' => $posterMedia?->getUrl($posterMedia->hasGeneratedConversion('w1600') ? 'w1600' : '')];
    $default = fn (string $collection): ?string => $video ? null : asset(ContentItem::mediaDefault(ContentType::PageText, 'home', $collection));
    $hero = [
        'video' => $video?->getUrl() ?? $default('video'),
        'mobile' => $own['mobile'] ?? $default('video_mobile'),
        'poster' => $own['poster'] ?? $default('video_poster'),
    ];
    $source = $video ? $video->getCustomProperty('source') : config('aiqme.hero_video_source');
@endphp
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.home')" :languages="$languages"
    :image="($poster = $heroMedia?->getFirstMedia('video_poster')) ? $poster->getUrl($poster->hasGeneratedConversion('w1600') ? 'w1600' : '') : null"
    :noindex="$locale === 'ru'">
@if (! $home)
    <x-page-top :title="$heading" :breadcrumbs="false" />
    <div class="wrap page-content"><x-placeholder /></div>
@else
    <section class="hero">
        <div class="hero-art hero-video-art" aria-hidden="true">
            @include('public.partials.media', ['mediaCover' => true])
            @include('public.partials.media', ['mediaVideo' => $hero['video'], 'mediaVideoMobile' => $hero['mobile'], 'mediaPoster' => $hero['poster']])
            <div class="hero-video-shade"></div>
        </div>
        <div class="wrap">
            <div class="eyebrow"><span class="dot"></span> {{ $home->field('hero_eyebrow') }}</div>
            <h1>{{ $home->field('hero_title') }}<span class="blue">.</span></h1>
            <p>{{ $home->field('hero_text') }}</p>
            <div class="buttons">
                <x-button :href="route($locale.'.calculator')" arrow>{{ __('public.hero.quote') }}</x-button>
                <x-button :href="route($locale.'.contact')" variant="ghost" arrow>{{ __('public.hero.contact') }}</x-button>
            </div>
            <div class="hero-bottom">
                <div class="stages">
                    @foreach ($home->field('hero_stages', []) as $stage)
                        @unless ($loop->first)<i aria-hidden="true">→</i>@endunless
                        <span><b>{{ sprintf('%02d', $loop->iteration) }}</b>{{ $stage }}</span>
                    @endforeach
                </div>
                <div class="hero-media-controls">
                    @if (filled($source['text'] ?? null))
                        <span>@if (filled($source['url'] ?? null))<a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">{{ $source['text'] }} ↗</a>@else{{ $source['text'] }}@endif</span>
                    @endif
                    <button type="button" id="hero-video-toggle" class="hero-video-toggle" aria-controls="hero-video" aria-label="{{ __('public.hero.play_label') }}"
                        data-play="{{ __('public.hero.play') }}" data-pause="{{ __('public.hero.pause') }}"
                        data-play-label="{{ __('public.hero.play_label') }}" data-pause-label="{{ __('public.hero.pause_label') }}"><span aria-hidden="true">▶</span> {{ __('public.hero.play') }}</button>
                    <span id="hero-video-status" class="sr-only" aria-live="polite" data-error="{{ __('public.hero.video_error') }}"></span>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="wrap">
            <div class="section-head"><div><div class="eyebrow">{{ $home->field('help_eyebrow') }}</div><h2>{{ $home->field('help_title') }}</h2></div></div>
            <div class="scenarios">
                @foreach ($home->field('scenarios', []) as $scenario)
                    @php
                        [$icon, $url] = match ($loop->index) {
                            0 => ['scanner', $modelling ? route($locale.'.services.show', ['slug' => $modelling->slug]) : route($locale.'.services')],
                            1 => ['box', route($locale.'.calculator')],
                            default => ['printer', route($locale.'.products')],
                        };
                    @endphp
                    <div class="scenario">
                        <span class="scenario-icon"><x-line-icon :name="$icon" /></span>
                        <h3>{{ $scenario['title'] }}</h3>
                        <p>{{ $scenario['text'] }}</p>
                        <a class="text-link" href="{{ $url }}">{{ __('public.find_solution') }}</a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="section dark">
        <div class="wrap">
            <div class="section-head">
                <div><div class="eyebrow">{{ $home->field('process_eyebrow') }}</div><h2>{!! nl2br(e($home->field('process_title')), false) !!}</h2></div>
                <p>{{ $home->field('process_text') }}</p>
            </div>
            @include('public.partials.process')
        </div>
    </section>

    <section class="section">
        <div class="wrap">
            <div class="section-head">
                <div><div class="eyebrow">{{ $home->field('services_eyebrow') }}</div><h2>{!! nl2br(e($home->field('services_title')), false) !!}</h2></div>
                <p>{{ $home->field('services_text') }}</p>
            </div>
            @include('public.partials.service-features')
        </div>
    </section>

    <section class="section partner-section" aria-labelledby="partner-heading" data-paused="false">
        <div class="wrap">
            <div class="section-head partner-heading-row">
                <div><div class="eyebrow">{{ $home->field('partners_eyebrow') }}</div><h2 id="partner-heading">{{ $home->field('partners_title') }}</h2></div>
                @if ($brands->isNotEmpty())
                    @include('public.partials.ticker-toggle', ['target' => 'partner-marquee'])
                @endif
            </div>
            @if ($brands->isEmpty())
                <x-placeholder />
            @else
                <div class="partner-marquee" id="partner-marquee" tabindex="0" aria-label="{{ __('public.ticker.brands') }}">
                    <div class="partner-marquee-track">
                        @foreach ([false, true] as $duplicate)
                            <div class="partner-marquee-group" @if ($duplicate) aria-hidden="true" @else role="list" aria-label="{{ __('public.ticker.brands_list') }}" @endif>
                                @foreach ($brands as $brand)
                                    <div class="partner-wordmark" @unless ($duplicate) role="listitem" @endunless>
                                        @if ($logo = $brand->item->getFirstMedia('images'))
                                            @include('public.partials.media', ['mediaImage' => $logo, 'mediaSizes' => '225px'])
                                        @else
                                            {{ $brand->title }}
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </section>

    <section class="section soft">
        <div class="wrap split">
            <div>
                <div class="eyebrow">{{ $home->field('equipment_eyebrow') }}</div>
                <h2>{!! nl2br(e($home->field('equipment_title')), false) !!}</h2>
                <p class="gap">{{ $home->field('equipment_text') }}</p>
            </div>
            <div class="category-list">
                @foreach ($categories as $category)
                    <a href="{{ route($locale.'.products', ['category' => $category->slug]) }}" class="category-row"><span>{{ sprintf('%02d', $loop->iteration) }}</span><h3>{{ $category->title }}</h3><x-line-icon name="arrow" /></a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="section news-section" aria-labelledby="news-heading" data-news-paused="false">
        <div class="wrap">
            <div class="section-head partner-heading-row">
                <div><div class="eyebrow">{{ $home->field('news_eyebrow') }}</div><h2 id="news-heading">{{ $home->field('news_title') }}</h2></div>
                <div class="news-actions">
                    @if ($news->isNotEmpty())
                        @include('public.partials.ticker-toggle', ['target' => 'news-marquee'])
                    @endif
                    <a class="text-link" href="{{ route($locale.'.news') }}">{{ __('public.ticker.all_news') }}</a>
                </div>
            </div>
            @if ($news->isEmpty())
                <x-placeholder />
            @else
                <div class="news-marquee" id="news-marquee" tabindex="0" aria-label="{{ __('public.ticker.news') }}">
                    <div class="news-marquee-track">
                        @foreach ([false, true] as $duplicate)
                            <div class="news-marquee-group" @if ($duplicate) aria-hidden="true" @else role="list" aria-label="{{ __('public.ticker.news_list') }}" @endif>
                                @foreach ($news as $article)
                                    @if ($duplicate)
                                        <div class="news-marquee-card" role="presentation"><span class="tag">{{ __('public.ticker.article') }}</span><h3>{{ $article->title }}</h3><p>{{ $article->field('intro') }}</p></div>
                                    @else
                                        <a class="news-marquee-card" role="listitem" href="{{ Route::has($locale.'.library.show') ? route($locale.'.library.show', ['slug' => $article->slug]) : route($locale.'.news') }}"><span class="tag">{{ __('public.ticker.article') }}</span><h3>{{ $article->title }}</h3><p>{{ $article->field('intro') }}</p><span class="text-link">{{ __('public.ticker.read') }}</span></a>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </section>

    @include('public.partials.cta')
@endif
</x-layout>
