<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Тексты правки 05.11, H-02 (LT дословно от заказчика, EN — перевод плана): [тип, ключ, язык, путь поля, прежние, новый].
     * Прежних вариантов может быть несколько: у карточки 2 главной — текст правки 02.11 (Г2) и текст до неё; down() возвращает
     * первый (вариант 02.11, он же был в prototype-content.json до этой правки).
     *
     * @var list<array{string, string, string, list<string|int>, list<string>, string}>
     */
    private const TEXTS = [
        ['page_text', 'home', 'lt', ['scenarios', 1, 'text'], ['Įkelkite modelį, pasirinkite parametrus ir peržiūrėkite preliminarią kainą.', 'Įkelkite modelį, pasirinkite parametrus ir peržiūrėkite kainą.'], 'Įkelkite failą, pasirinkite gamybos parametrus ir gaukite preliminarų pasiūlymą.'],
        ['page_text', 'home', 'en', ['scenarios', 1, 'text'], ['Upload your model, choose settings and review a preliminary price.', 'Upload your model, choose settings and review the price.'], 'Upload a file, choose the manufacturing parameters and get a preliminary quote.'],
        ['page_text', 'home', 'lt', ['process_title'], ["Viena užduotis.\nAiškus kelias iki rezultato."], "Aiškus ir nuoseklus gamybos\nprocesas."],
        ['page_text', 'home', 'en', ['process_title'], ["One challenge.\nA clear path to the result."], "A clear and consistent\nmanufacturing process."],
        ['page_text', 'home', 'lt', ['process_text'], ['AIQME koordinuoja visą procesą, prireikus įtraukdama gamybos partnerius. Jums nereikia patiems derinti skirtingų tiekėjų.'], 'AIQME koordinuoja visą procesą. Jums nereikia patiems derinti skirtingų tiekėjų.'],
        ['page_text', 'home', 'en', ['process_text'], ['AIQME coordinates the whole process, involving manufacturing partners when needed. You do not have to coordinate separate suppliers.'], 'AIQME coordinates the whole process. You don\'t need to coordinate different suppliers yourself.'],
        ['page_text', 'home', 'lt', ['process_steps', 0, 'title'], ['Jūsų užduotis'], 'Užklausos įvertinimas'],
        ['page_text', 'home', 'lt', ['process_steps', 0, 'text'], ['Atsiųskite failą, eskizą, detalės nuotrauką ar pačią detalę. Pakonsultuosime, kaip geriausia ją pagaminti, ir suderinsime kainą bei terminą.'], 'Pagal jūsų pateiktą failą, eskizą, nuotrauką, idėją ar pačią detalę įvertiname ir suderiname gamybos procesą, medžiagą, kainą bei terminą.'],
        ['page_text', 'home', 'lt', ['process_steps', 1, 'title'], ['Suderintas sprendimas'], 'Gamyba'],
        ['page_text', 'home', 'lt', ['process_steps', 1, 'text'], ['Nuskenuosime arba išmatuosime detalę ir paruošime skaitmeninį failą gamybai.'], 'Paruošiame skaitmeninį modelį ir pagal suderintus reikalavimus pagaminame detalę.'],
        ['page_text', 'home', 'lt', ['process_steps', 2, 'title'], ['Patikrinta gamyba'], 'Kokybės kontrolė'],
        ['page_text', 'home', 'lt', ['process_steps', 2, 'text'], ['Prieš gamybą darbuotojas patikrina modelį ir parametrus. Koordinuojame sutartų darbų vykdymą.'], 'Patikriname pagamintos detalės kokybę, matmenis ir atitiktį suderintiems techniniams reikalavimams.'],
        ['page_text', 'home', 'lt', ['process_steps', 3, 'title'], ['Rezultatas jums'], 'Perdavimas'],
        ['page_text', 'home', 'lt', ['process_steps', 3, 'text'], ['Patikriname rezultatą ir paruošiame jį pristatyti arba atsiimti.'], 'Pagaminta detalė perduodama užsakovui arba pristatoma nurodytu adresu.'],
        ['page_text', 'home', 'en', ['process_steps', 0, 'title'], ['Your challenge'], 'Request assessment'],
        ['page_text', 'home', 'en', ['process_steps', 0, 'text'], ['Send a file, sketch, photo or the part itself. We will advise on a suitable way to make it and agree the price and timing.'], 'Based on the file, sketch, photo, idea or the part itself, we assess and agree on the manufacturing process, material, price and lead time.'],
        ['page_text', 'home', 'en', ['process_steps', 1, 'title'], ['Agreed solution'], 'Manufacturing'],
        ['page_text', 'home', 'en', ['process_steps', 1, 'text'], ['We will scan or measure the part and prepare a digital file for manufacturing.'], 'We prepare the digital model and manufacture the part to the agreed requirements.'],
        ['page_text', 'home', 'en', ['process_steps', 2, 'title'], ['Reviewed production'], 'Quality control'],
        ['page_text', 'home', 'en', ['process_steps', 2, 'text'], ['Before production, a team member checks the model and settings. We coordinate the agreed work.'], 'We check the quality, dimensions and compliance of the finished part with the agreed technical requirements.'],
        ['page_text', 'home', 'en', ['process_steps', 3, 'title'], ['The result for you'], 'Handover'],
        ['page_text', 'home', 'en', ['process_steps', 3, 'text'], ['We check the result and prepare it for delivery or collection.'], 'The finished part is handed over to the customer or delivered to the specified address.'],
        ['page_text', 'home', 'lt', ['services_title'], ["Tinkamas procesas.\nJūsų užduočiai."], "Skirtingos technologijos\njūsų užduočiai."],
        ['page_text', 'home', 'en', ['services_title'], ["The right process.\nFor your challenge."], "Different technologies\nfor your task."],
        ['page_text', 'home', 'lt', ['services_text'], ['Sujungiame skaitmeninį modelį ir fizinę gamybą. Pasirinkite vieną paslaugą arba patikėkite mums visą darbų seką.'], 'Sujungiame skaitmeninį modeliavimą ir fizinę gamybą. Pasirinkite reikiamą paslaugą arba patikėkite mums visą procesą.'],
        ['page_text', 'home', 'en', ['services_text'], ['We connect digital models and physical production. Choose a service or let us coordinate the complete workflow.'], 'We combine digital modelling and physical manufacturing. Choose the service you need or entrust us with the whole process.'],
        ['service', 'scanning', 'lt', ['benefits', 0], ['Objekto forma ir paviršius perkeliami į 3D modelį.'], 'Objekto geometrija perkeliama į skaitmeninį 3D modelį.'],
        ['service', 'scanning', 'en', ['benefits', 0], ['Capture an object’s shape and surface as a 3D model.'], 'The object\'s geometry is transferred into a digital 3D model.'],
        ['page_text', 'home', 'lt', ['help_eyebrow'], ['01 / PRADĖKIME NUO JŪSŲ'], 'PRADĖKIME NUO JŪSŲ'],
        ['page_text', 'home', 'en', ['help_eyebrow'], ['01 / START WITH YOUR NEED'], 'START WITH YOUR NEED'],
        ['page_text', 'home', 'lt', ['process_eyebrow'], ['02 / DARBO EIGA'], 'DARBO EIGA'],
        ['page_text', 'home', 'en', ['process_eyebrow'], ['02 / OUR PROCESS'], 'OUR PROCESS'],
        ['page_text', 'home', 'lt', ['services_eyebrow'], ['03 / PASLAUGOS'], 'PASLAUGOS'],
        ['page_text', 'home', 'en', ['services_eyebrow'], ['03 / SERVICES'], 'SERVICES'],
        ['page_text', 'home', 'lt', ['partners_eyebrow'], ['04 / PARTNERIAI IR TECHNOLOGIJOS'], 'PARTNERIAI IR TECHNOLOGIJOS'],
        ['page_text', 'home', 'en', ['partners_eyebrow'], ['04 / PARTNERS & TECHNOLOGY'], 'PARTNERS & TECHNOLOGY'],
        ['page_text', 'home', 'lt', ['equipment_eyebrow'], ['05 / ĮRANGA IR MEDŽIAGOS'], 'ĮRANGA IR MEDŽIAGOS'],
        ['page_text', 'home', 'en', ['equipment_eyebrow'], ['05 / EQUIPMENT & MATERIALS'], 'EQUIPMENT & MATERIALS'],
        ['page_text', 'home', 'lt', ['news_eyebrow'], ['06 / NAUJIENOS IR ĮŽVALGOS'], 'NAUJIENOS IR ĮŽVALGOS'],
        ['page_text', 'home', 'en', ['news_eyebrow'], ['06 / NEWS & INSIGHTS'], 'NEWS & INSIGHTS'],
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
