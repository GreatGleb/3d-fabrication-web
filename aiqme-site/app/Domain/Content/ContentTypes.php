<?php

namespace App\Domain\Content;

use App\Domain\Content\Models\ContentItem;

/**
 * Описание редактируемых типов контента — один источник для веб-редактора (W-08) и Telegram-бота (W-19): поля перевода,
 * их вид, проверки и коллекции медиа. W-08 регистрирует service и page_text, остальные типы добавляет W-26.
 *
 * Вид поля: line — строка, text — многострочный текст, lines — список строк (в форме — по строке на пункт),
 * pairs — список записей с частями parts (число записей задано блоком страницы). В форме и в боте значение поля —
 * строка (у pairs — строка каждой части); toForm()/fromForm() переводят его в хранимый вид и обратно.
 */
final class ContentTypes
{
    /** Поля перевода услуги (§4.1); тот же набор хранит ContentType::fields(). */
    public const SERVICE_FIELDS = [
        'short' => 'line',
        'label' => 'line',
        'benefits' => 'lines',
        'description' => 'text',
        'problems' => 'lines',
        'provide' => 'text',
        'receive' => 'text',
    ];

    /** Медиа по типу (коллекция => один файл, новый заменяет прежний); у page_text — по ключу страницы. */
    private const MEDIA = [
        'service' => ['images' => true],
        'page_text' => [
            'home' => ['video' => true, 'video_mobile' => true, 'video_poster' => true],
            'about' => ['images' => true],
        ],
    ];

    private const LINE_MAX = 255;

    public static function isRegistered(ContentType $type): bool
    {
        return in_array($type, [ContentType::Service, ContentType::PageText], true);
    }

    /**
     * Поля перевода элемента: имя => ['kind' => line|text|lines|pairs, 'parts' => части записи, 'count' => число записей].
     * У page_text вид выводится из исходного значения блока (строка — text, список строк — lines, список записей — pairs).
     *
     * @return array<string, array{kind: string, parts?: list<string>, count?: int}>
     */
    public static function fields(ContentItem $item): array
    {
        if ($item->type === ContentType::Service) {
            return array_map(fn (string $kind): array => ['kind' => $kind], self::SERVICE_FIELDS);
        }
        if ($item->type !== ContentType::PageText) {
            return [];
        }

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
            if ($field['kind'] === 'pairs') {
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
                default => $text($value),
            };
        }

        return $data;
    }
}
