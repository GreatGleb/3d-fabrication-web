<?php

namespace App\Domain\Content;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\Channel;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Content\Models\ContentRevision;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\Models\UiText;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Единственная точка записи контента (§2.4, §4.2) для веб-админки, Telegram-бота и сидера: черновик, публикация и
 * снятие по каждой локали отдельно, версии с проверкой ожидаемой ревизии, строки интерфейса и медиа.
 * Права — только администратор (Gate "admin"); import() — только для сидера исходного контента (канал system).
 */
final class ContentService
{
    public const LOCALES = ['lt', 'en', 'ru'];

    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const MAX_TEXT = 20000;

    public function createItem(User $actor, ContentType $type, ?string $key = null, array $meta = [], int $position = 0): ContentItem
    {
        $this->authorize($actor);
        if ($key !== null && ! preg_match(self::SLUG, $key)) {
            $this->fail('key');
        }

        return ContentItem::create(['type' => $type, 'key' => $key, 'meta' => $this->cleanValue($meta, 'meta'), 'position' => $position]);
    }

    public function updateItem(User $actor, ContentItem $item, array $meta, int $position): ContentItem
    {
        $this->authorize($actor);
        $item->update(['meta' => $this->cleanValue($meta, 'meta'), 'position' => $position]);

        return $item;
    }

    /**
     * Сохраняет новую версию перевода; опубликованное не меняется до publish().
     *
     * @param  array{title?: string, slug?: ?string, fields?: array<string, mixed>, seo_title?: ?string, seo_description?: ?string}  $data
     */
    public function saveDraft(User $actor, ContentItem $item, string $locale, array $data, int $expectedRevision, Channel $channel): ContentTranslation
    {
        $this->authorize($actor);
        $this->checkLocale($locale);
        $data = $this->validated($item, $data);

        return DB::transaction(function () use ($actor, $item, $locale, $data, $expectedRevision, $channel): ContentTranslation {
            $translation = $this->lockedTranslation($item, $locale, create: true);
            $this->checkRevision($translation, $expectedRevision);
            $this->addRevision($translation, $data, $actor, $channel);

            return $translation;
        });
    }

    /** Делает последнюю версию перевода видимой на этой локали; другие локали не меняются. */
    public function publish(User $actor, ContentItem $item, string $locale, int $expectedRevision, Channel $channel): ContentTranslation
    {
        $this->authorize($actor);
        $this->checkLocale($locale);

        return DB::transaction(function () use ($actor, $item, $locale, $expectedRevision, $channel): ContentTranslation {
            $translation = $this->lockedTranslation($item, $locale);
            $this->checkRevision($translation, $expectedRevision);
            $this->publishLatest($translation, $actor, $channel);

            return $translation;
        });
    }

    public function unpublish(User $actor, ContentItem $item, string $locale, int $expectedRevision, Channel $channel): ContentTranslation
    {
        $this->authorize($actor);
        $this->checkLocale($locale);

        return DB::transaction(function () use ($actor, $item, $locale, $expectedRevision, $channel): ContentTranslation {
            $translation = $this->lockedTranslation($item, $locale);
            $this->checkRevision($translation, $expectedRevision);
            if ($translation->isPublished()) {
                $translation->forceFill(['published_at' => null])->save();
                AuditLog::record('content.unpublished', $translation, $channel, $actor, [
                    'locale' => $locale, 'revision' => $translation->published_revision,
                ]);
            }

            return $translation;
        });
    }

    /** Восстановление старой версии создаёт новую версию с её содержимым (§4.2). */
    public function restore(User $actor, ContentItem $item, string $locale, int $revision, int $expectedRevision, Channel $channel): ContentTranslation
    {
        $this->authorize($actor);
        $this->checkLocale($locale);

        return DB::transaction(function () use ($actor, $item, $locale, $revision, $expectedRevision, $channel): ContentTranslation {
            $translation = $this->lockedTranslation($item, $locale);
            $this->checkRevision($translation, $expectedRevision);
            $old = $translation->revisions()->where('revision', $revision)->first() ?? $this->fail('revision');
            $this->addRevision($translation, $old->only(['title', 'slug', 'fields', 'seo_title', 'seo_description']), $actor, $channel);

            return $translation;
        });
    }

    /**
     * Переопределяет строку lang/ на локали (null — вернуть значение из файла). Видно сразу, без публикации.
     * $expectedRevision — ревизия текущего переопределения, 0 — если его нет.
     */
    public function saveUiText(User $actor, string $locale, string $key, ?string $value, int $expectedRevision, Channel $channel): ?UiText
    {
        $this->authorize($actor);
        $this->checkLocale($locale);
        if (! $this->isUiTextKey($key)) {
            $this->fail('key');
        }
        $value = $value === null ? null : trim($value);
        if ($value === '' || ($value !== null && mb_strlen($value) > 2000)) {
            $this->fail('value');
        }

        $text = DB::transaction(function () use ($actor, $locale, $key, $value, $expectedRevision): ?UiText {
            $text = UiText::where('locale', $locale)->where('key', $key)->lockForUpdate()->first();
            if (($text->revision ?? 0) !== $expectedRevision) {
                $this->fail('revision', 'content.stale');
            }
            if ($value === null) {
                $text?->delete();

                return null;
            }
            $text ??= new UiText(['locale' => $locale, 'key' => $key, 'revision' => 0]);
            $text->fill(['value' => $value, 'revision' => $text->revision + 1, 'updated_by' => $actor->id])->save();

            return $text;
        });

        // Переводчик кеширует загруженные группы: следующий __() должен прочитать новое значение.
        app('translator')->setLoaded([]);

        return $text;
    }

    /**
     * Медиа элемента (spatie/medialibrary): тип файла — по сигнатуре (JPEG, PNG, WebP, MP4; SVG не принимается, §6.4),
     * имя — UUID; alt/подпись — на локаль, необязательный источник (текст и http(s)-ссылка).
     *
     * @param  array<string, string>  $alt
     * @param  array{text?: string, url?: string}|null  $source
     */
    public function addMedia(User $actor, ContentItem $item, string $path, string $collection, array $alt = [], ?array $source = null): Media
    {
        $this->authorize($actor);
        $extension = self::sniff($path);
        if (! in_array($extension, ContentItem::MEDIA_COLLECTIONS[$collection] ?? [], true)) {
            $this->fail('file');
        }
        foreach ($alt as $locale => $text) {
            if (! in_array($locale, self::LOCALES, true) || ! is_string($text) || mb_strlen($text) > 500) {
                $this->fail('alt');
            }
        }
        if ($source !== null) {
            $url = $source['url'] ?? null;
            if (array_diff(array_keys($source), ['text', 'url']) !== []
                || ($url !== null && ! preg_match('~^https?://[^\s<>"]+$~i', (string) $url))) {
                $this->fail('source');
            }
        }

        return $item->addMedia($path)
            ->preservingOriginal()
            ->usingFileName(Str::uuid().'.'.$extension)
            ->withCustomProperties(['alt' => $alt, 'source' => $source])
            ->toMediaCollection($collection);
    }

    /**
     * Сидер исходного контента (W-04): создаёт элемент с переводами, если элемента (type, key) ещё нет; существующие
     * не трогает. Публикуемые переводы публикуются сразу (канал system).
     *
     * @param  array{type: string, key: string, position: int, published: bool, meta: array<string, mixed>, translations: array<string, array<string, mixed>>}  $data
     */
    public function import(array $data): bool
    {
        return DB::transaction(function () use ($data): bool {
            $type = ContentType::from($data['type']);
            if (ContentItem::where('type', $type)->where('key', $data['key'])->exists()) {
                return false;
            }
            $item = ContentItem::create([
                'type' => $type, 'key' => $data['key'], 'position' => $data['position'], 'meta' => $data['meta'],
            ]);
            foreach ($data['translations'] as $locale => $translationData) {
                $this->checkLocale($locale);
                $translation = $item->translations()->create(['type' => $type, 'locale' => $locale, 'revision' => 0]);
                $this->addRevision($translation, $this->validated($item, $translationData, definingBlocks: true), null, Channel::System);
                if ($data['published']) {
                    $this->publishLatest($translation, null, Channel::System);
                }
            }

            return true;
        });
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('admin');
    }

    private function checkLocale(string $locale): void
    {
        if (! in_array($locale, self::LOCALES, true)) {
            $this->fail('locale');
        }
    }

    private function lockedTranslation(ContentItem $item, string $locale, bool $create = false): ContentTranslation
    {
        if ($create) {
            ContentTranslation::firstOrCreate(
                ['content_item_id' => $item->id, 'locale' => $locale],
                ['type' => $item->type, 'revision' => 0],
            );
        }

        return ContentTranslation::where('content_item_id', $item->id)->where('locale', $locale)->lockForUpdate()->first()
            ?? $this->fail('locale');
    }

    /** Сохранение с устаревшей ревизией отклоняется (§4.2): «последняя запись побеждает» не применяется. */
    private function checkRevision(ContentTranslation $translation, int $expectedRevision): void
    {
        if ($translation->revision !== $expectedRevision) {
            $this->fail('revision', 'content.stale');
        }
    }

    private function addRevision(ContentTranslation $translation, array $data, ?User $actor, Channel $channel): void
    {
        $revision = $translation->revision + 1;
        ContentRevision::create($data + [
            'content_translation_id' => $translation->id,
            'revision' => $revision,
            'user_id' => $actor?->id,
            'channel' => $channel->value,
        ]);
        $translation->forceFill(['revision' => $revision])->save();
    }

    private function publishLatest(ContentTranslation $translation, ?User $actor, Channel $channel): void
    {
        $latest = $translation->latestRevision() ?? $this->fail('revision');
        $type = $translation->type;
        if ($latest->title === null || ($type->hasSlug() && $latest->slug === null)) {
            $this->fail($latest->title === null ? 'title' : 'slug');
        }
        if ($type->hasSlug() && ContentTranslation::where('locale', $translation->locale)->where('type', $type)
            ->where('slug', $latest->slug)->whereKeyNot($translation->id)->exists()) {
            $this->fail('slug', 'content.slug_taken');
        }

        $translation->forceFill($latest->only(['title', 'slug', 'fields', 'seo_title', 'seo_description']) + [
            'published_revision' => $latest->revision,
            'published_at' => now(),
        ])->save();

        AuditLog::record('content.published', $translation, $channel, $actor, [
            'locale' => $translation->locale, 'revision' => $latest->revision,
        ]);
    }

    /**
     * Проверка полей перевода: набор полей задан типом (у page_text — блоками, созданными сидером), значения —
     * только текст (строки, списки строк, списки пар «поле → строка»). Разметка не исполняется: вывод — через Markup.
     *
     * @return array{title: string, slug: ?string, fields: array<string, mixed>, seo_title: ?string, seo_description: ?string}
     */
    private function validated(ContentItem $item, array $data, bool $definingBlocks = false): array
    {
        $type = $item->type;
        if (array_diff(array_keys($data), ['title', 'slug', 'fields', 'seo_title', 'seo_description']) !== []) {
            $this->fail('fields');
        }
        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        if ($title === '' || mb_strlen($title) > 255) {
            $this->fail('title');
        }

        $slug = $data['slug'] ?? null;
        if ($type->hasSlug() ? (! is_string($slug) || strlen($slug) > 120 || ! preg_match(self::SLUG, $slug)) : $slug !== null) {
            $this->fail('slug');
        }

        $seo = ['seo_title' => $data['seo_title'] ?? null, 'seo_description' => $data['seo_description'] ?? null];
        foreach ($seo as $name => $value) {
            if ($value !== null && (! $type->hasSeo() || ! is_string($value) || mb_strlen($value) > ($name === 'seo_title' ? 255 : 500))) {
                $this->fail($name);
            }
            $seo[$name] = $value === null || trim($value) === '' ? null : trim($value);
        }

        $fields = $data['fields'] ?? [];
        if (! is_array($fields) || array_is_list($fields) && $fields !== []) {
            $this->fail('fields');
        }
        $blocks = $type->fields() ?? ($definingBlocks ? array_keys($fields) : $this->pageBlocks($item));
        foreach ($fields as $name => $value) {
            if (! in_array($name, $blocks, true)) {
                $this->fail('fields');
            }
            $fields[$name] = $this->cleanValue($value, 'fields');
        }

        return ['title' => $title, 'slug' => $slug, 'fields' => $fields] + $seo;
    }

    /** @return list<string> Блоки page_text, заданные при создании страницы (первая версия перевода). */
    private function pageBlocks(ContentItem $item): array
    {
        $first = ContentRevision::whereIn('content_translation_id', $item->translations()->select('id'))
            ->where('revision', 1)->first();

        return array_keys($first->fields ?? []);
    }

    /** Текст: строка, null или вложенные массивы строк (не глубже трёх уровней). */
    private function cleanValue(mixed $value, string $field, int $depth = 0): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return mb_strlen($value) <= self::MAX_TEXT ? trim($value) : $this->fail($field);
        }
        if (is_array($value) && $depth < 3) {
            return array_map(fn (mixed $child): mixed => $this->cleanValue($child, $field, $depth + 1), $value);
        }
        if ((is_int($value) || is_bool($value)) && $field === 'meta') {
            return $value;
        }

        return $this->fail($field);
    }

    private function isUiTextKey(string $key): bool
    {
        if (! str_contains($key, '.')) {
            return false;
        }
        foreach (['lt', 'en'] as $locale) {
            if (Lang::hasForLocale($key, $locale) && is_string(Lang::get($key, [], $locale, false))) {
                return true;
            }
        }

        return false;
    }

    /** Формат файла по первым байтам; расширение и MIME клиента — лишь подсказки (§6.4). */
    public static function sniff(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            substr($head, 4, 4) === 'ftyp' => 'mp4',
            default => null,
        };
    }

    private function fail(string $field, string $message = 'content.invalid'): never
    {
        throw ValidationException::withMessages([$field => __($message, ['field' => $field])]);
    }
}
