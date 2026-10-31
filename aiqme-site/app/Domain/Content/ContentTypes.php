<?php

namespace App\Domain\Content;

use App\Domain\Content\Models\ContentItem;
use App\Http\Controllers\Public\ArticleController;
use App\Http\Controllers\Public\ProjectController;
use Illuminate\Validation\Rule;

/**
 * Описание редактируемых типов контента — один источник для веб-редактора (W-08, W-26) и Telegram-бота (W-19): поля
 * перевода, их вид, непереводимые атрибуты элемента (meta), проверки и коллекции медиа. Зарегистрированы все типы §4.1.
 *
 * Вид поля: line — строка, text — многострочный текст, lines — список строк (в форме — по строке на пункт),
 * pairs — список записей с частями parts (число записей задано блоком страницы), records — то же, но число записей
 * свободное (в форме — сохранённые записи и одна пустая; пустая запись не сохраняется). В форме и в боте значение
 * поля — строка (у pairs/records — строка каждой части); toForm()/fromForm() переводят его в хранимый вид и обратно.
 *
 * Атрибуты элемента (meta) общие для всех языков и применяются сразу при сохранении (без версий, как медиа):
 * select — одно значение из options, choices — несколько, date — дата Y-m-d, url — https-ссылка.
 */
final class ContentTypes
{
    /** Поля перевода услуги (§4.1); тот же набор хранит ContentType::fields(). */
    public const SERVICE_FIELDS = [
        'short' => 'line',
        'label' => 'line', // не выводится на сайте с 28.10; оставлено для совместимости черновиков
        'benefits' => 'lines',
        'description' => 'text',
        'problems' => 'lines',
        'provide' => 'text',
        'receive' => 'text',
    ];

    /** Поля перевода остальных типов (§4.1); ContentType::fields() берёт отсюда же. */
    public const FIELDS = [
        'project' => ['task' => 'text', 'solution' => 'text', 'result' => 'text'],
        'article' => ['intro' => 'text', 'sections' => 'records'],
        'training' => ['description' => 'text'],
        'product' => ['description' => 'text', 'specifications' => 'text', 'compatibility' => 'line'],
        'brand' => [],
        'category' => [],
        'faq_item' => ['answer' => 'text'],
    ];

    /** Части записей полей вида records. */
    private const RECORD_PARTS = ['sections' => ['heading', 'text']];

    /** Форматы обучения, как в редакторе прототипа: online, в AIQME, у клиента. */
    public const TRAINING_FORMATS = ['online', 'aiqme', 'company'];

    /** Группы FAQ, кроме ключей услуг: общий вопрос всех услуг и вопросы только страницы FAQ (§4.1). */
    public const FAQ_GROUPS = ['services', 'general'];

    /** Медиа по типу (коллекция => один файл, новый заменяет прежний); у page_text — по ключу страницы. */
    private const MEDIA = [
        'service' => ['images' => true],
        'project' => ['images' => false],
        'product' => ['images' => false],
        'brand' => ['images' => true],
        'page_text' => [
            'home' => ['video' => true, 'video_mobile' => true, 'video_poster' => true, 'logo' => true, 'logo_white' => true, 'favicon' => true],
            'about' => ['images' => true],
        ],
    ];

    private const LINE_MAX = 255;

    public static function isRegistered(ContentType $type): bool
    {
        return $type === ContentType::PageText || $type === ContentType::Service || array_key_exists($type->value, self::FIELDS);
    }

    /**
     * Поля перевода элемента: имя => ['kind' => line|text|lines|pairs|records, 'parts' => части записи, 'count' => число записей pairs].
     * У page_text вид выводится из исходного значения блока (строка — text, список строк — lines, список записей — pairs).
     *
     * @return array<string, array{kind: string, parts?: list<string>, count?: int}>
     */
    public static function fields(ContentItem $item): array
    {
        if ($item->type !== ContentType::PageText) {
            $kinds = $item->type === ContentType::Service ? self::SERVICE_FIELDS : self::FIELDS[$item->type->value] ?? [];

            $fields = [];
            foreach ($kinds as $name => $kind) {
                $fields[$name] = $kind === 'records' ? ['kind' => $kind, 'parts' => self::RECORD_PARTS[$name]] : ['kind' => $kind];
            }

            return $fields;
        }

        // Блок contact.form_note («* — būtini laukai.») не выводится на сайте с 30.10; оставлен для совместимости черновиков.
        // jsonb не хранит порядок ключей: блоки — по имени (группы hero_*, process_* рядом), части записи — заголовок первым.
        $blocks = $item->pageBlocks();
        ksort($blocks);
        $fields = [];
        foreach ($blocks as $name => $value) {
            $fields[$name] = match (true) {
                is_array($value) && isset($value[0]) && is_array($value[0]) => [
                    'kind' => 'pairs', 'parts' => self::partOrder(array_keys($value[0])), 'count' => count($value),
                ],
                is_array($value) => ['kind' => 'lines'],
                default => ['kind' => 'text'],
            };
        }

        return $fields;
    }

    /**
     * @param  list<string>  $parts
     * @return list<string>
     */
    private static function partOrder(array $parts): array
    {
        $first = array_values(array_intersect(['title', 'heading', 'label', 'value', 'text'], $parts));

        return [...$first, ...array_values(array_diff($parts, $first))];
    }

    /**
     * Атрибуты элемента (meta), общие для языков: имя => ['kind' => select|choices|date|url, 'options' => значение => подпись].
     * Подписи вариантов — на языке интерфейса админки; ключи услуг, категорий и id брендов читаются из БД.
     *
     * @return array<string, array{kind: string, options?: array<string, string>}>
     */
    public static function meta(ContentItem $item): array
    {
        $choices = fn (string $prefix, array $values): array => array_combine($values, array_map(fn (string $value): string => __($prefix.$value), $values));

        return match ($item->type) {
            ContentType::FaqItem => ['group' => ['kind' => 'select', 'options' => self::titles(ContentType::Service)
                + $choices('admin.meta.groups.', self::FAQ_GROUPS)]],
            ContentType::Article => [
                'section' => ['kind' => 'select', 'options' => $choices('admin.meta.sections.', ['news', 'library'])],
                'category' => ['kind' => 'select', 'options' => $choices('public.resources.categories.', ArticleController::CATEGORIES)],
            ],
            ContentType::Project => [
                'services' => ['kind' => 'choices', 'options' => self::titles(ContentType::Service)],
                'application' => ['kind' => 'select', 'options' => $choices('public.projects.applications.', ProjectController::APPLICATIONS)],
                'youtube' => ['kind' => 'url'],
            ],
            ContentType::Training => [
                'date' => ['kind' => 'date'],
                'format' => ['kind' => 'select', 'options' => $choices('admin.meta.formats.', self::TRAINING_FORMATS)],
            ],
            ContentType::Product => [
                'category' => ['kind' => 'select', 'options' => self::titles(ContentType::Category)],
                'brand' => ['kind' => 'select', 'options' => self::titles(ContentType::Brand, byId: true)],
            ],
            default => [],
        };
    }

    /**
     * Элементы типа для выбора в meta: ключ (или id) => заголовок перевода на языке интерфейса, иначе LT, иначе ключ.
     *
     * @return array<string, string>
     */
    private static function titles(ContentType $type, bool $byId = false): array
    {
        $locale = app()->getLocale() === 'lt' ? 'lt' : 'en';

        return ContentItem::where('type', $type)->with('translations')->orderBy('position')->orderBy('id')->get()
            ->mapWithKeys(function (ContentItem $item) use ($locale, $byId): array {
                $translation = $item->translation($locale) ?? $item->translation('lt');
                $title = $translation?->title ?? $translation?->latestRevision()?->title ?? (string) $item->key;

                return [(string) ($byId ? $item->id : $item->key) => $title];
            })->all();
    }

    /**
     * Проверки meta (правила валидатора Laravel по полю meta.<имя>): значение — один из вариантов, пустое — без атрибута.
     *
     * @return array<string, list<mixed>>
     */
    public static function metaRules(ContentItem $item): array
    {
        $rules = [];
        foreach (self::meta($item) as $name => $field) {
            $rules['meta.'.$name] = match ($field['kind']) {
                'select' => ['nullable', 'string', Rule::in(array_map('strval', array_keys($field['options'])))],
                'choices' => ['array'],
                'date' => ['nullable', 'date_format:Y-m-d'],
                'url' => ['nullable', 'string', 'max:500', 'url:https'],
            };
            if ($field['kind'] === 'choices') {
                $rules['meta.'.$name.'.*'] = ['string', Rule::in(array_map('strval', array_keys($field['options'])))];
            }
        }

        return $rules;
    }

    /**
     * meta элемента → форма: строки (у choices — список строк).
     *
     * @return array<string, mixed>
     */
    public static function metaToForm(ContentItem $item): array
    {
        $form = [];
        foreach (self::meta($item) as $name => $field) {
            $value = $item->meta($name);
            $form[$name] = $field['kind'] === 'choices' ? array_map('strval', (array) $value) : (string) $value;
        }

        return $form;
    }

    /**
     * Форма → meta элемента: прочие атрибуты сохраняются, пустое значение удаляет атрибут, id бренда — число.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function metaFromForm(ContentItem $item, array $form): array
    {
        $meta = $item->meta ?? [];
        foreach (self::meta($item) as $name => $field) {
            $value = $form[$name] ?? null;
            $value = $field['kind'] === 'choices'
                ? array_values(array_filter(array_map('strval', (array) $value), fn (string $choice): bool => array_key_exists($choice, $field['options'])))
                : (trim((string) $value) === '' ? null : trim((string) $value));
            if ($value === null || $value === []) {
                unset($meta[$name]);
            } else {
                $meta[$name] = $item->type === ContentType::Product && $name === 'brand' ? (int) $value : $value;
            }
        }

        return $meta;
    }

    /** @return array<string, bool> коллекция => один файл */
    public static function media(ContentItem $item): array
    {
        return $item->type === ContentType::PageText
            ? self::MEDIA['page_text'][$item->key] ?? []
            : self::MEDIA[$item->type->value] ?? [];
    }

    /**
     * Проверки перевода в виде формы (строки) — правила валидатора Laravel; ContentService дополнительно проверяет
     * хранимый вид, права и ревизию.
     *
     * @return array<string, list<string>>
     */
    public static function rules(ContentItem $item): array
    {
        $type = $item->type;
        $rules = ['title' => ['required', 'string', 'max:'.self::LINE_MAX]];
        if ($type->hasSlug()) {
            $rules['slug'] = ['required', 'string', 'max:120', 'regex:'.ContentService::SLUG];
        }
        if ($type->hasSeo()) {
            $rules['seo_title'] = ['nullable', 'string', 'max:'.self::LINE_MAX];
            $rules['seo_description'] = ['nullable', 'string', 'max:500'];
        }
        foreach (self::fields($item) as $name => $field) {
            $max = $field['kind'] === 'line' ? self::LINE_MAX : ContentService::MAX_TEXT;
            if (in_array($field['kind'], ['pairs', 'records'], true)) {
                foreach ($field['parts'] as $part) {
                    $rules['fields.'.$name.'.*.'.$part] = ['nullable', 'string', 'max:'.$max];
                }
            } else {
                $rules['fields.'.$name] = ['nullable', 'string', 'max:'.$max];
            }
        }

        return $rules;
    }

    /**
     * Хранимый перевод (версия) → форма: все строки, списки — по строке на пункт.
     *
     * @param  array<string, mixed>|null  $data  title, slug, fields, seo_title, seo_description
     * @return array{title: string, slug?: string, seo_title?: string, seo_description?: string, fields: array<string, mixed>}
     */
    public static function toForm(ContentItem $item, ?array $data): array
    {
        $form = ['title' => (string) ($data['title'] ?? '')];
        if ($item->type->hasSlug()) {
            $form['slug'] = (string) ($data['slug'] ?? '');
        }
        if ($item->type->hasSeo()) {
            $form['seo_title'] = (string) ($data['seo_title'] ?? '');
            $form['seo_description'] = (string) ($data['seo_description'] ?? '');
        }
        $form['fields'] = [];
        foreach (self::fields($item) as $name => $field) {
            $value = $data['fields'][$name] ?? null;
            $form['fields'][$name] = match ($field['kind']) {
                'lines' => implode("\n", array_map('strval', (array) $value)),
                'pairs' => array_map(
                    fn (int $index): array => array_combine($field['parts'], array_map(
                        fn (string $part): string => (string) ($value[$index][$part] ?? ''), $field['parts'])),
                    range(0, $field['count'] - 1)),
                'records' => [...array_map(fn (mixed $entry): array => array_combine($field['parts'], array_map(
                    fn (string $part): string => (string) ($entry[$part] ?? ''), $field['parts'])), array_values((array) $value)),
                    array_fill_keys($field['parts'], '')],
                default => (string) $value,
            };
        }

        return $form;
    }

    /**
     * Форма → данные ContentService::saveDraft(): пустые строки — null, списки — непустые строки по порядку.
     *
     * @param  array<string, mixed>  $form
     * @return array{title: string, slug?: ?string, seo_title?: ?string, seo_description?: ?string, fields: array<string, mixed>}
     */
    public static function fromForm(ContentItem $item, array $form): array
    {
        $text = fn (mixed $value): ?string => ($value = trim(str_replace("\r", '', (string) $value))) === '' ? null : $value;
        $data = ['title' => (string) $text($form['title'] ?? '')];
        if ($item->type->hasSlug()) {
            $data['slug'] = $text($form['slug'] ?? '');
        }
        if ($item->type->hasSeo()) {
            $data['seo_title'] = $text($form['seo_title'] ?? '');
            $data['seo_description'] = $text($form['seo_description'] ?? '');
        }
        $data['fields'] = [];
        foreach (self::fields($item) as $name => $field) {
            $value = $form['fields'][$name] ?? '';
            $data['fields'][$name] = match ($field['kind']) {
                'lines' => array_values(array_filter(array_map($text, explode("\n", (string) $value)), fn (?string $line): bool => $line !== null)),
                'pairs' => array_map(fn (array $entry): array => array_map(fn (string $part): string => (string) $text($entry[$part] ?? ''),
                    array_combine($field['parts'], $field['parts'])), array_values(is_array($value) ? $value : [])),
                'records' => array_values(array_filter(array_map(fn (mixed $entry): array => array_map(fn (string $part): string => (string) $text(is_array($entry) ? ($entry[$part] ?? '') : ''),
                    array_combine($field['parts'], $field['parts'])), is_array($value) ? array_values($value) : []),
                    fn (array $entry): bool => implode('', $entry) !== '')),
                default => $text($value),
            };
        }

        return $data;
    }
}
