<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Тексты правки 02.11, Г2 (LT дословно от заказчика, EN — перевод плана): [тип, ключ, язык, путь поля, прежний, новый].
     *
     * @var list<array{string, string, string, list<string|int>, string, string}>
     */
    private const TEXTS = [
        ['page_text', 'home', 'lt', ['scenarios', 1, 'text'], 'Įkelkite modelį, pasirinkite parametrus ir peržiūrėkite kainą.', 'Įkelkite modelį, pasirinkite parametrus ir peržiūrėkite preliminarią kainą.'],
        ['page_text', 'home', 'en', ['scenarios', 1, 'text'], 'Upload your model, choose settings and review the price.', 'Upload your model, choose settings and review a preliminary price.'],
        ['service', 'printing', 'lt', ['short'], 'Prototipai, funkcinės detalės ir mažos serijos.', 'Prototipai, funkcinės detalės ir detalių serijos.'],
        ['service', 'printing', 'en', ['short'], 'Prototypes, functional components and small batches.', 'Prototypes, functional components and part series.'],
        ['service', 'printing', 'lt', ['problems', 1], 'Reikia vienos detalės ar mažos serijos', 'Reikia vienos detalės ar detalių serijos'],
        ['service', 'printing', 'en', ['problems', 1], 'A one-off component or a small batch', 'A one-off component or a series of parts'],
        ['faq_item', 'services-1', 'lt', ['answer'], 'Nuo modelio būklės, proceso, kiekio ir konkretaus užsakymo apimties. Terminą patvirtiname pasiūlyme.', 'Nuo detalės dydžio, sudėtingumo, kiekio ir pasirinktos medžiagos.'],
        ['faq_item', 'services-1', 'en', ['answer'], 'The model condition, process, quantity and scope determine timing. We confirm the lead time in the proposal.', 'It depends on the part’s size, complexity, quantity and the chosen material.'],
    ];

    /**
     * Каждое поле меняется в опубликованной версии (content_translations) и в черновике (последняя content_revisions), только
     * если там прежний текст прототипа; правка администратора сохраняется. down() возвращает прежний текст при том же условии.
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
            $this->replace($type, $key, $locale, $path, $new, $old);
        }
    }

    /** @param  list<string|int>  $path */
    private function replace(string $type, string $key, string $locale, array $path, string $from, string $to): void
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
            if (! is_array($fields) || data_get($fields, $path) !== $from) {
                continue;
            }
            data_set($fields, $path, $to);
            DB::table($table)->where('id', $id)->update(['fields' => json_encode($fields, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
