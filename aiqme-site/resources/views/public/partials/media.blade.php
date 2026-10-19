{{-- Вывод медиа (§4.4, один компонент для страниц): изображение медиатеки — WebP-варианты в srcset/sizes, width/height
     оригинала, loading=lazy ниже первого экрана, alt из подписи локали; видео hero — preload=metadata, мобильный MP4 ≤700 px,
     постер; без медиа — своя заглушка. Параметры: $mediaImage | $mediaVideo (+ $mediaVideoMobile, $mediaPoster), $mediaSizes,
     $mediaLazy, $mediaCover. --}}
@use('App\Domain\Content\Models\ContentItem')
@php
    $mediaImage ??= null;
    $mediaVideo ??= null;
    $mediaPoster ??= null;
    $locale = app()->getLocale();
    $variant = fn ($media, int $width): ?string => $media->hasGeneratedConversion('w'.$width) ? $media->getUrl('w'.$width) : null;
@endphp
@if ($mediaVideo)
    <video class="hero-video" id="hero-video" muted loop playsinline preload="metadata" tabindex="-1" disablepictureinpicture
        @if ($mediaPoster) poster="{{ $variant($mediaPoster, 1600) ?? $mediaPoster->getUrl() }}" @endif>
        @if ($mediaVideoMobile ?? null)
            <source src="{{ $mediaVideoMobile->getUrl() }}" type="video/mp4" media="(max-width: 700px)">
        @endif
        <source src="{{ $mediaVideo->getUrl() }}" type="video/mp4">
    </video>
@elseif ($mediaImage)
    @php
        [$width, $height] = @getimagesize($mediaImage->getPath()) ?: [null, null];
        $sources = [];
        foreach (ContentItem::IMAGE_WIDTHS as $size) {
            if (($url = $variant($mediaImage, $size)) !== null) {
                $sources[min($size, $width ?? $size)] ??= $url;
            }
        }
        $alt = $mediaImage->getCustomProperty('alt', []);
    @endphp
    <img src="{{ $sources ? end($sources) : $mediaImage->getUrl() }}"
        @if ($sources) srcset="{{ collect($sources)->map(fn ($url, $size) => $url.' '.$size.'w')->implode(', ') }}" sizes="{{ $mediaSizes ?? '100vw' }}" @endif
        @if ($width) width="{{ $width }}" height="{{ $height }}" @endif
        loading="{{ ($mediaLazy ?? true) ? 'lazy' : 'eager' }}" alt="{{ $alt[$locale] ?? $alt['en'] ?? '' }}">
@else
    @include('public.partials.art', ['artCover' => $mediaCover ?? false])
@endif
