<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\ContentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Элемент контента (§4.1): тип, ключ, порядок и непереводимые атрибуты meta (группа FAQ, раздел и категория статьи,
 * связанные услуги проекта, дата и формат обучения). Пишется только через ContentService.
 */
#[Fillable(['type', 'key', 'position', 'meta'])]
class ContentItem extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Коллекции медиа (назначение §4.1) => допустимые форматы файла. */
    public const MEDIA_COLLECTIONS = [
        'images' => ['jpg', 'png', 'webp'],
        'video' => ['mp4'],
        'video_mobile' => ['mp4'],
        'video_poster' => ['jpg', 'png', 'webp'],
        'logo' => ['jpg', 'png', 'webp'],
        'favicon' => ['png'],
    ];

    /** Ширины WebP-вариантов изображений (§4.4). */
    public const IMAGE_WIDTHS = [480, 960, 1600];

    protected function casts(): array
    {
        return [
            'type' => ContentType::class,
            'meta' => 'array',
        ];
    }

    /** @return HasMany<ContentTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(ContentTranslation::class);
    }

    public function translation(string $locale): ?ContentTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    public function meta(string $name, mixed $default = null): mixed
    {
        return $this->meta[$name] ?? $default;
    }

    /**
     * Блоки page_text, заданные при создании страницы (первая версия первого перевода): имя блока => исходное
     * значение. Набор и форма блоков не меняются редакторами (без конструктора страниц, §4.1).
     *
     * @return array<string, mixed>
     */
    public function pageBlocks(): array
    {
        $first = ContentRevision::whereIn('content_translation_id', $this->translations()->select('id'))
            ->where('revision', 1)->orderBy('content_translation_id')->first();

        return $first->fields ?? [];
    }

    /** Файл прототипа по умолчанию (D-43, config aiqme.media_defaults) для коллекции записи: путь в public/ или null. */
    public function defaultMedia(string $collection): ?string
    {
        return self::mediaDefault($this->type, (string) $this->key, $collection);
    }

    public static function mediaDefault(ContentType $type, string $key, string $collection): ?string
    {
        return config('aiqme.media_defaults')[$type->value][$key][$collection] ?? null;
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (self::IMAGE_WIDTHS as $width) {
            $this->addMediaConversion('w'.$width)
                ->format('webp')
                ->fit(Fit::Max, $width, $width * 4)
                ->performOnCollections('images', 'video_poster');
        }
    }
}
