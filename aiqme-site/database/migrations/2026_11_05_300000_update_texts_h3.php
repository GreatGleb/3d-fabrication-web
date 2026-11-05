<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ABOUT_LT_0 = 'AIQME padeda įmonėms, kūrėjams, švietimo organizacijoms ir privatiems klientams įgyvendinti praktines užduotis. Vieniems reikia atkurti nebegaminamą detalę. Kitiems — patikrinti produkto idėją ar pasirinkti tinkamą įrangą.';

    private const ABOUT_EN_0 = 'AIQME helps businesses, creators, education organisations and private customers solve practical challenges. Some need to reconstruct an unavailable part. Others want to test an idea or choose the right equipment.';

    /**
     * Тексты правки 05.11, H-03 (LT дословно от заказчика, EN — перевод плана): [тип, ключ, язык, путь поля, прежние, новое].
     * Прежних вариантов может быть несколько: у 3D spausdinimas problems[1] — текст правки 02.11 (Г2) и текст до неё; down()
     * возвращает первый (вариант 02.11, он же был в prototype-content.json до этой правки). «Apie mus»: абзацы intro_paragraphs
     * меняются целиком (второй прежний абзац удаляется, третий — новый текст), только если все три абзаца прежние.
     *
     * @var list<array{string, string, string, list<string|int>, list<string|list<string>>, string|list<string>}>
     */
    private const TEXTS = [
        ['service', 'printing', 'lt', ['problems', 1], ['Reikia vienos detalės ar detalių serijos', 'Reikia vienos detalės ar mažos serijos'], 'Reikia vienos detalės arba serijos'],
        ['service', 'printing', 'en', ['problems', 1], ['A one-off component or a series of parts', 'A one-off component or a small batch'], 'Need a single part or a series'],
        ['page_text', 'about', 'lt', ['intro_paragraphs'], [[
            self::ABOUT_LT_0,
            'Pradedame nuo paskirties: ką detalė turi atlikti, kokiomis sąlygomis ji dirbs ir kaip patikrinsime rezultatą. Tuomet deriname skenavimą, projektavimą, gamybą ir pristatymą.',
            'Visą procesą koordinuoja AIQME, prireikus įtraukdama gamybos partnerius. Aiškiai susitariame dėl darbų apimties, o pakeitimus deriname prieš juos vykdydami.',
        ]], [self::ABOUT_LT_0, 'Visą procesą koordinuoja AIQME. Aiškiai susitariame dėl darbų apimties, o pakeitimus deriname prieš juos vykdydami.']],
        ['page_text', 'about', 'en', ['intro_paragraphs'], [[
            self::ABOUT_EN_0,
            'We start with purpose: what the part must do, its operating conditions and how to verify the result. We then coordinate scanning, design, manufacturing and delivery.',
            'AIQME coordinates the whole process, involving manufacturing partners when needed. We agree the scope clearly and seek approval before making changes.',
        ]], [self::ABOUT_EN_0, 'AIQME coordinates the whole process. We clearly agree on the scope of work and agree any changes before making them.']],
        ['page_text', 'about', 'lt', ['panels', 0, 'title'], ['Suprantamas planas'], 'Individualus požiūris'],
        ['page_text', 'about', 'lt', ['panels', 0, 'text'], ['Įvardijame etapus, atsakomybes ir rezultatą, kurį gausite.'], 'Kiekvienai užduočiai parenkame tinkamiausią sprendimą.'],
        ['page_text', 'about', 'lt', ['panels', 1, 'title'], ['Žmogaus patikra'], 'Sklandus procesas'],
        ['page_text', 'about', 'lt', ['panels', 1, 'text'], ['Prieš gamybą tikriname modelį ir parametrus, prieš pristatymą — gaminį.'], 'Pasirūpiname visais etapais, kad jums nereikėtų rūpintis detalėmis.'],
        ['page_text', 'about', 'lt', ['panels', 2, 'title'], ['Aiški komunikacija'], 'Atsakingas požiūris'],
        ['page_text', 'about', 'lt', ['panels', 2, 'text'], ['Jei patikroje randame neatitikimą, pateikiame paaiškinimą ir pasirinkimus.'], 'Kiekvieną užsakymą vykdome pagal suderintus reikalavimus.'],
        ['page_text', 'about', 'en', ['panels', 0, 'title'], ['A clear plan'], 'Individual approach'],
        ['page_text', 'about', 'en', ['panels', 0, 'text'], ['We define the steps, responsibilities and what you will receive.'], 'We choose the most suitable solution for every task.'],
        ['page_text', 'about', 'en', ['panels', 1, 'title'], ['Human review'], 'Smooth process'],
        ['page_text', 'about', 'en', ['panels', 1, 'text'], ['We review the model and settings before production, and inspect the part before delivery.'], 'We take care of every stage so you don\'t have to worry about the details.'],
        ['page_text', 'about', 'en', ['panels', 2, 'title'], ['Clear communication'], 'Responsible approach'],
        ['page_text', 'about', 'en', ['panels', 2, 'text'], ['If review reveals an issue, we explain it and present the options.'], 'We carry out every order according to the agreed requirements.'],
    ];

    /**
     * Каждое поле меняется в опубликованной версии (content_translations) и в черновике (последняя content_revisions), только
     * если там прежнее значение; правка администратора сохраняется. down() возвращает прежнее при том же условии.
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
     * @param  list<string|list<string>>  $from
     * @param  string|list<string>  $to
     */
    private function replace(string $type, string $key, string $locale, array $path, array $from, string|array $to): void
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
