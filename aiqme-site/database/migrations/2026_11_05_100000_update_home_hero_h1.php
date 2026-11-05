<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Тексты hero главной правки 05.11, H-01 (LT дословно от заказчика, EN — перевод плана): [язык, поле, прежний, новый].
     *
     * @var list<array{string, string, string, string}>
     */
    private const TEXTS = [
        ['lt', 'hero_eyebrow', '3D INŽINERIJA IR TECHNOLOGIJOS', '3D INŽINERIJA & TECHNOLOGIJOS'],
        ['lt', 'hero_text', 'Skenuojame, projektuojame ir koordinuojame gamybą. Vienas partneris visam procesui — nuo pirmo klausimo iki patikrinto rezultato.', 'Vienas partneris visiems gamybos etapams'],
        ['en', 'hero_text', 'Scanning, design and coordinated manufacturing. One partner for the whole process, from your first question to a checked result.', 'One partner for every stage of production'],
    ];

    /**
     * Ряд этапов hero убран (правка 05.11, H-01): поле hero_stages удаляется, только если в нём прежний список прототипа.
     *
     * @var array<string, list<string>>
     */
    private const STAGES = ['lt' => ['SKENUOTI', 'PROJEKTUOTI', 'GAMINTI'], 'en' => ['SCAN', 'DESIGN', 'MANUFACTURE']];

    /**
     * Поля page_text home меняются в опубликованной версии (content_translations) и в черновике (последняя content_revisions),
     * только если там прежнее значение; правка администратора сохраняется. hero_stages удаляется ещё и из первой версии —
     * по её ключам редактор админки строит поля (ContentItem::pageBlocks). down() возвращает прежнее при том же условии.
     */
    public function up(): void
    {
        foreach (['lt', 'en'] as $locale) {
            $this->update($locale, function (array $fields, bool $first) use ($locale): array {
                if (! $first) {
                    foreach (self::TEXTS as [$textLocale, $name, $old, $new]) {
                        if ($textLocale === $locale && ($fields[$name] ?? null) === $old) {
                            $fields[$name] = $new;
                        }
                    }
                }
                if (($fields['hero_stages'] ?? null) === self::STAGES[$locale]) {
                    unset($fields['hero_stages']);
                }

                return $fields;
            });
        }
    }

    public function down(): void
    {
        foreach (['lt', 'en'] as $locale) {
            $this->update($locale, function (array $fields, bool $first) use ($locale): array {
                if (! $first) {
                    foreach (self::TEXTS as [$textLocale, $name, $old, $new]) {
                        if ($textLocale === $locale && ($fields[$name] ?? null) === $new) {
                            $fields[$name] = $old;
                        }
                    }
                }
                if (! array_key_exists('hero_stages', $fields)) {
                    $fields['hero_stages'] = self::STAGES[$locale];
                }

                return $fields;
            });
        }
    }

    /** @param  callable(array<string, mixed>, bool): array<string, mixed>  $change  второй аргумент — первая версия, а не опубликованная или черновик */
    private function update(string $locale, callable $change): void
    {
        $translation = DB::table('content_translations')
            ->join('content_items', 'content_items.id', '=', 'content_translations.content_item_id')
            ->where('content_items.type', 'page_text')->where('content_items.key', 'home')->where('content_translations.locale', $locale)
            ->select('content_translations.id', 'content_translations.fields', 'content_translations.revision')->first();
        if ($translation === null) {
            return;
        }
        $rows = [['content_translations', $translation->id, $translation->fields, false]];
        $revisions = DB::table('content_revisions')->where('content_translation_id', $translation->id)
            ->whereIn('revision', array_unique([(int) $translation->revision, 1]))->get();
        foreach ($revisions as $revision) {
            $rows[] = ['content_revisions', $revision->id, $revision->fields, (int) $revision->revision !== (int) $translation->revision];
        }
        foreach ($rows as [$table, $id, $json, $first]) {
            $fields = $json === null ? null : json_decode($json, true);
            if (! is_array($fields)) {
                continue;
            }
            $changed = $change($fields, $first);
            if ($changed !== $fields) {
                DB::table($table)->where('id', $id)->update(['fields' => json_encode($changed, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
};
