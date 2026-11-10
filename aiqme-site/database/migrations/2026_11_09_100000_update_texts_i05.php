<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Доработка 09.11, I-05: переносы строк как на макете «Стало» рев.4 — заголовок hero после «–», текст «Darbo eiga» после
     * первого предложения. Только LT: макет LT; у заголовка hero EN тире нет, а в EN-тексте «Darbo eiga» перенос оставил бы
     * висячее «yourself.» на 1024–1920 px. На сайте перенос — <br> только на ПК (public.css). [тип, ключ, язык, путь поля, прежние, новый].
     *
     * @var list<array{string, string, string, list<string|int>, list<string>, string}>
     */
    private const TEXTS = [
        ['page_text', 'home', 'lt', ['hero_title'], ['Nuo jūsų idėjos ar detalės – iki pagaminto sprendimo'], "Nuo jūsų idėjos ar detalės –\niki pagaminto sprendimo"],
        ['page_text', 'home', 'lt', ['process_text'], ['AIQME koordinuoja visą procesą. Jums nereikia patiems derinti skirtingų tiekėjų.'], "AIQME koordinuoja visą procesą.\nJums nereikia patiems derinti skirtingų tiekėjų."],
    ];

    /**
     * Каждое поле меняется в опубликованной версии (content_translations) и в черновике (последняя content_revisions), только
     * если там прежний текст; правка администратора сохраняется. down() возвращает прежний текст при том же условии.
     */
    public function up(): void
    {
        foreach (self::TEXTS as [$type, $key, $locale, $path, $old, $new]) {
            $this->replace($type, $key, $locale, $path, $old, $new);
        }
    }

    public function down(): void
    {
        foreach (self::TEXTS as [$type, $key, $locale, $path, $old, $new]) {
            $this->replace($type, $key, $locale, $path, [$new], $old[0]);
        }
    }

    /**
     * @param  list<string|int>  $path
     * @param  list<string>  $from
     */
    private function replace(string $type, string $key, string $locale, array $path, array $from, string $to): void
    {
        $translation = DB::table('content_translations')
            ->join('content_items', 'content_items.id', '=', 'content_translations.content_item_id')
            ->where('content_items.type', $type)->where('content_items.key', $key)->where('content_translations.locale', $locale)
            ->select('content_translations.id', 'content_translations.fields', 'content_translations.revision')->first();
        if ($translation === null) {
            return;
        }
        $rows = [['content_translations', $translation->id, $translation->fields]];
        $draft = DB::table('content_revisions')->where('content_translation_id', $translation->id)->where('revision', $translation->revision)->first();
        if ($draft !== null) {
            $rows[] = ['content_revisions', $draft->id, $draft->fields];
        }
        foreach ($rows as [$table, $id, $json]) {
            $fields = $json === null ? null : json_decode($json, true);
            if (! is_array($fields) || ! in_array(data_get($fields, $path), $from, true)) {
                continue;
            }
            data_set($fields, $path, $to);
            DB::table($table)->where('id', $id)->update(['fields' => json_encode($fields, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
