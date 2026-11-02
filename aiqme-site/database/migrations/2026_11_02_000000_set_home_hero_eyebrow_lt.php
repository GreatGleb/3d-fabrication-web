<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = '3D ENGINEERING & TECHNOLOGY';

    private const NEW = '3D INŽINERIJA IR TECHNOLOGIJOS';

    /**
     * Надпись над заголовком hero главной на LT — литовская (правка 02.11, Г1): поле hero_eyebrow LT-перевода page_text home
     * в опубликованной версии (content_translations) и в черновике (последняя content_revisions). Меняется только прежний
     * текст прототипа; правка администратора сохраняется. EN не меняется.
     */
    public function up(): void
    {
        $this->replace(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->replace(self::NEW, self::OLD);
    }

    private function replace(string $from, string $to): void
    {
        $translation = DB::table('content_translations')
            ->join('content_items', 'content_items.id', '=', 'content_translations.content_item_id')
            ->where('content_items.type', 'page_text')->where('content_items.key', 'home')->where('content_translations.locale', 'lt')
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
            if (($fields['hero_eyebrow'] ?? null) !== $from) {
                continue;
            }
            $fields['hero_eyebrow'] = $to;
            DB::table($table)->where('id', $id)->update(['fields' => json_encode($fields, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
