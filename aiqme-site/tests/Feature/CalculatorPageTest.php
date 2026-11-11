<?php

namespace Tests\Feature;

use Database\Seeders\PrototypeContentSeeder;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Калькулятор W-27 (§1.2; правка 02.11, Г8 — карточки шага 2 строит JS; правка 05.11, H-04 — три шага без цены): разметка шагов, значения D-09/D-24 (настройки W-11) и §6.4 (конфигурация) в data-атрибутах, шаг 2 без
 * технологии (D-37), шаг 4 и сводка без VAT, доставки, скидки и цены за единицу (D-38), без демо-надписей (D-21),
 * ссылка специалиста по контракту предзаполнения. Поведение шагов в браузере — bin/browser-check, логика — tests/js/.
 */
class CalculatorPageTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $path): DOMXPath
    {
        return Html::xpath($this->get($path)->assertOk()->getContent());
    }

    public function test_settings_and_texts_come_through_data_attributes(): void
    {
        $root = $this->page('/en/quote')->query('//*[@id="quote"]')->item(0);

        // Чистая БД после migrate: начальные настройки W-11 (D-09, D-24) и лимиты config (§6.4). Правка 05.11, H-04: тарифов
        // (цена за грамм, платы) и плотностей материалов нет — только названия материалов и данные проверки файла.
        $this->assertSame([
            'materials' => ['PLA', 'PETG', 'ABS'], 'default_infill' => 20, 'max_dimension_mm' => 1000,
            // Правка 02.11, Г6: заглушки фрезеровки и гравировки из миграции данных; правка 05.11, H-05: новые материалы фрезеровки,
            // списков толщин и глубин нет.
            ...array_diff_key(['milling_materials' => (require database_path('migrations/2026_11_05_400000_update_milling_calculator_settings_h5.php'))::MILLING_MATERIALS]
                + (require database_path('migrations/2026_11_03_100000_add_milling_engraving_calculator_settings.php'))::VALUES,
                ['milling_thicknesses_mm' => 1, 'engraving_depths_mm' => 1]),
            'max_file_bytes' => 52428800, 'max_files' => 10,
        ], json_decode($root->getAttribute('data-settings'), true));
        $texts = json_decode($root->getAttribute('data-texts'), true);
        $this->assertSame('Reading files...', $texts['reading']);
        $this->assertSame(50, $texts['mb']);
        $this->assertSame('en', $root->getAttribute('data-locale'));
        // Правка 02.11, Г8: карточки шага 2, шаги 3 и 4 строит JS — тексты способов, полей, карточки и проверки в data-texts,
        // значки icons/ui — data-icons.
        $this->assertSame(['printing' => '3D printing', 'milling' => 'Milling', 'engraving' => 'Engraving'], $texts['methods']);
        $this->assertSame(['standard' => 'Standard', 'priority' => 'Urgent'], $texts['settings']['terms']);
        $this->assertSame(['specialist' => 'Price to be assessed by a specialist'], $texts['price']);
        $this->assertSame(asset('icons/ui'), $root->getAttribute('data-icons'));
        $this->assertArrayNotHasKey('specialist_format', $texts);
    }

    /** Правка 05.11, H-04: тарифы не уходят в браузер — ни ключей, ни значений тарифов и плотностей в HTML страницы. */
    public function test_tariffs_are_not_in_the_page_html(): void
    {
        foreach (['/en/quote', '/lt/kalkuliatorius'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            foreach (['rate_per_gram', 'order_fee_cents', 'file_fee_cents', 'ratePerGram', '"PLA":1.24', '1.27', '1.04'] as $secret) {
                $this->assertStringNotContainsString($secret, $html, "$path: «{$secret}»");
            }
        }
    }

    /**
     * Проверка по файлам (правка 02.11, Г8; правка 05.11, H-04 — в карточке шага 2): блоки строит JS по data-texts — строка FDM
     * (П7: 1000 мм из настроек), строка предела фрезеровки и гравировки, «Failą peržiūrės specialistas ir įvertins kainą.» (правка 11.11, J-01) для файлов без разбора,
     * подпись масштаба; отдельной панели проверки нет, предупреждения — под карточками шага 2.
     */
    public function test_review_step_texts_of_both_locales(): void
    {
        foreach ([
            '/lt/kalkuliatorius' => ['Įrangos dydžio ribos', 'FDM: :mm × :mm × :mm mm.', 'Frezavimo ir graviravimo matmenys :w × :h mm.', 'Failą peržiūrės specialistas ir įvertins kainą.', 'Mastelis, %'], // правка 11.11, J-01
            '/en/quote' => ['Build size limits', 'FDM: :mm × :mm × :mm mm.', 'Milling and engraving dimensions: :w × :h mm.', 'A specialist will review the file and estimate the price.', 'Scale, %'],
        ] as $path => $expected) {
            $page = $this->page($path);
            $texts = json_decode($page->query('//*[@id="quote"]')->item(0)->getAttribute('data-texts'), true);
            $review = $texts['review'];
            $this->assertSame($expected, [$review['build'], $review['build_text'], $review['cnc'], $review['specialist_file'], $texts['scale']], $path);
            $this->assertArrayNotHasKey('title', $review, $path);
            $this->assertSame(0, $page->query('//*[contains(@class, "check-row")] | //*[@data-review]')->length, $path);
            $this->assertSame(1, $page->query('//*[@data-step-panel="1"]//*[@data-warnings]')->length, $path);
        }
    }

    /** Правка 05.11, H-04: три шага, заголовки панелей без «01. Įkelkite savo modelius», «03. …», «04. …»; доработка 09.11, I-01 — и без «02. …». */
    public function test_three_steps_and_texts_of_both_locales(): void
    {
        $lt = $this->page('/lt/kalkuliatorius');
        $this->assertSame(['1Failai', '2Gamybos parametrai', '3Užklausos pateikimas'], Html::texts($lt, '//div[@class="steps"]/button'));
        $this->assertCount(0, $lt->query('//section[@class="page-top"]//div[@class="eyebrow"]')); // правка 30.10, Г2
        // Доработка 09.11, I-01: заголовка «02. Gamybos pasirinkimai» у шага 2 нет (было ['02. Gamybos pasirinkimai', 'Pirkėjas ir pristatymas']).
        $this->assertSame(['Pirkėjas ir pristatymas'], Html::texts($lt, '//*[@data-step-panel]/h2'));
        $this->assertCount(0, $lt->query('//*[@data-step-panel="1"]//h2'));

        $en = $this->page('/en/quote');
        $this->assertSame(['1Files', '2Manufacturing parameters', '3Submit request'], Html::texts($en, '//div[@class="steps"]/button'));
        $this->assertSame(['0', '1', '2'], array_map(fn ($node) => $node->value, iterator_to_array($en->query('//*[@data-step-panel]/@data-step-panel'))));
        // Доработка 09.11, I-01: заголовка «02. Production settings» у шага 2 нет.
        $this->assertSame(['Buyer & delivery'], Html::texts($en, '//*[@data-step-panel]/h2'));
        $this->assertCount(0, $en->query('//*[@data-step-panel="1"]//h2'));
        $this->assertSame('2', $en->query('//form[@id="checkout-form"]')->item(0)->getAttribute('data-step-panel'));
        $this->assertSame(1, $en->query('//*[@data-step-panel and not(@hidden)]')->length, 'видим только шаг 1');
        // Правка 02.11, Г8: принимаются DXF и DWG.
        $this->assertSame('.stl,.step,.stp,.obj,.dxf,.dwg', $en->query('//input[@id="model-upload"]')->item(0)->getAttribute('accept'));
        $this->assertSame(1, $en->query('//*[@data-viewer and contains(@class, "viewer")]')->length, 'место просмотра W-31');
    }

    /** Правка 05.11, H-04: заголовок и крошки, вступление и мета-описание — дословно из README плана. */
    public function test_heading_intro_and_meta_description_of_the_plan(): void
    {
        foreach ([
            '/lt/kalkuliatorius' => ['Jūsų failai. Mūsų kitas žingsnis.', 'Įkelkite failus, pasirinkite gamybos parametrus ir gaukite pasiūlymą.'],
            '/en/quote' => ['Your files. Our next step.', 'Upload files, choose the manufacturing parameters and get a quote.'],
        ] as $path => [$title, $intro]) {
            $page = $this->page($path);
            $this->assertSame($title, Html::text($page->query('//section[@class="page-top"]//h1')->item(0)), $path);
            $this->assertSame($title, Html::text($page->query('//div[@class="breadcrumbs"]/span[last()]')->item(0)), $path);
            $this->assertSame($intro, Html::text($page->query('//section[@class="page-top"]//p')->item(0)), $path);
            $this->assertSame($intro, $page->query('//meta[@name="description"]')->item(0)->getAttribute('content'), $path);
        }
    }

    public function test_viewer_canvas_and_controls_of_prototype_in_both_locales(): void
    {
        foreach ([
            '/en/quote' => ['GEOMETRY VIEW · Drag to rotate', '3D viewer. Use arrow keys to rotate and plus or minus to zoom.', ['Zoom in', 'Zoom out', 'Reset view']],
            '/lt/kalkuliatorius' => ['GEOMETRIJOS PERŽIŪRA · Sukite vilkdami', '3D peržiūra. Rodyklėmis sukite, pliusu ar minusu keiskite mastelį.', ['Priartinti', 'Atitolinti', 'Atkurti vaizdą']],
        ] as $path => [$label, $aria, $buttons]) {
            $page = $this->page($path);
            $this->assertSame($label, Html::text($page->query('//*[@data-viewer]/span[@class="viewer-label"]')->item(0)));
            $canvas = $page->query('//*[@data-viewer]/canvas')->item(0);
            $this->assertSame([$aria, '0', 'img'], [$canvas->getAttribute('aria-label'), $canvas->getAttribute('tabindex'), $canvas->getAttribute('role')]);
            $controls = $page->query('//*[@data-viewer]/div[@class="viewer-controls"]/button');
            $this->assertSame($buttons, array_map(fn ($button) => $button->getAttribute('aria-label'), iterator_to_array($controls)));
            $this->assertSame(['+', '−', '↺'], Html::texts($page, '//*[@data-viewer]/div[@class="viewer-controls"]/button'));
        }
    }

    /** Правка 02.11, Г8: тексты шага 1 — дословно из README плана; предел — из config. */
    public function test_size_limit_label_comes_from_config(): void
    {
        $en = $this->page('/en/quote');
        $this->assertSame('STL, STEP, OBJ, DXF, DWG. You can select several files.', Html::text($en->query('//*[@data-step-panel="0"]/p')->item(0)));
        $this->assertSame('Up to 50 MB per file.', Html::text($en->query('//*[@id="drop-zone"]/p')->item(0)));

        config(['aiqme.calculator.max_file_bytes' => 10 * 1024 * 1024]);
        $lt = $this->page('/lt/kalkuliatorius');
        $this->assertSame('STL, STEP, OBJ, DXF, DWG. Galite pasirinkti kelis failus.', Html::text($lt->query('//*[@data-step-panel="0"]/p')->item(0)));
        $this->assertSame('Iki 10 MB vienam failui.', Html::text($lt->query('//*[@id="drop-zone"]/p')->item(0)));
        // Надпись области (17.17); кнопка — «Pasirinkti failus» (правка 05.11, H-04). Шаг 1: строка форматов, затем зона, без заголовка.
        $this->assertSame(['Nutempkite failus čia arba pasirinkite', 'Pasirinkti failus'],
            [Html::text($lt->query('//*[@id="drop-zone"]/strong')->item(0)), Html::text($lt->query('//*[@id="drop-zone"]/span[contains(@class, "btn")]')->item(0))]);
        $this->assertSame('Choose files', Html::text($en->query('//*[@id="drop-zone"]/span[contains(@class, "btn")]')->item(0)));
        $this->assertSame(0, $lt->query('//*[@data-step-panel="0"]/h2')->length);
        $this->assertSame(['p', 'label'], array_map(fn ($node) => $node->nodeName, array_slice(iterator_to_array($lt->query('//*[@data-step-panel="0"]/*')), 0, 2)));
    }

    public function test_step_two_has_no_technology_choice_of_prototype(): void
    {
        $en = $this->page('/en/quote');
        $this->assertSame(0, $en->query('//*[contains(@class, "tech-choices")]')->length);
        // Правка 02.11, Г8: материалы печати — из настроек в data-settings (списки карточки строит JS).
        // Правка 05.11, H-04: названия материалов списком, без плотностей.
        $this->assertSame(['PLA', 'PETG', 'ABS'], json_decode($en->query('//*[@id="quote"]')->item(0)->getAttribute('data-settings'), true)['materials']);
    }

    /**
     * Шаг 2 по макету 17.09 (правка 02.11, Г8): общих полей, общего срока и его пояснения нет — карточки на каждый файл строит
     * JS ([data-cards]); подзаголовок и нижняя подсказка — дословно из README плана; тексты карточки (способы, поля, приоритет,
     * дата, заметка) и прежние списки печати (правка 28.10, E-05; 30.10, Г3) — в data-texts.
     * Доработка 09.11, I-01: подзаголовок — «Kiekvienam failui…» / «…for each file» (было «modeliui» / «for each model»).
     */
    public function test_step_two_has_cards_with_texts_of_the_plan(): void
    {
        foreach ([
            '/en/quote' => [
                'Choose the manufacturing method and parameters for each file.', 'A separate production deadline can be chosen for each part.',
                ['thickness' => 'Thickness', 'depth' => 'Engraving depth', 'urgent' => 'Urgent', 'needed_by' => 'Needed by',
                    'intro' => 'Choose the manufacturing method and parameters for each file.', 'hint' => 'A separate production deadline can be chosen for each part.',
                    'change' => 'Change parameters', 'method' => 'Manufacturing method', 'priority' => 'Production priority',
                    'rush_note' => 'The rush deadline will be confirmed separately.', 'notes' => 'Additional information (optional)',
                    'notes_placeholder' => 'E.g. additional requirements, remarks or other important instructions…'],
                ['Material', 'Colour', 'Part purpose', 'Additional services (optional)', 'Surface quality', 'Infill'],
                ['none' => 'Not selected', 'painting' => 'Sanding and painting', 'inserts' => 'Threaded insert installation', 'assembly' => 'Part assembly', 'consult' => 'Consultation needed'],
            ],
            '/lt/kalkuliatorius' => [
                'Kiekvienam failui pasirinkite gamybos būdą ir parametrus.', 'Kiekvienai detalei galima pasirinkti atskirą gamybos terminą.',
                ['thickness' => 'Storis', 'depth' => 'Graviravimo gylis', 'urgent' => 'Skubu', 'needed_by' => 'Reikalinga iki',
                    'intro' => 'Kiekvienam failui pasirinkite gamybos būdą ir parametrus.', 'hint' => 'Kiekvienai detalei galima pasirinkti atskirą gamybos terminą.',
                    'change' => 'Keisti parametrus', 'method' => 'Gamybos būdas', 'priority' => 'Gamybos prioritetas',
                    'rush_note' => 'Skubos terminas bus patvirtintas atskirai.', 'notes' => 'Papildoma informacija (neprivaloma)',
                    'notes_placeholder' => 'Pvz. papildomi reikalavimai, pastabos ar kiti svarbūs nurodymai…'],
                ['Medžiaga', 'Spalva', 'Detalės paskirtis', 'Papildomos paslaugos (neprivaloma)', 'Paviršiaus kokybė', 'Užpildas'],
                ['none' => 'Nepasirinkta', 'painting' => 'Šlifavimas ir dažymas', 'inserts' => 'Srieginių įvorių montavimas', 'assembly' => 'Detalių surinkimas', 'consult' => 'Reikia konsultacijos'],
            ],
        ] as $path => [$intro, $hint, $part, $labels, $services]) {
            $page = $this->page($path);
            $panel = $page->query('//*[@data-step-panel="1"]')->item(0);
            $this->assertSame(0, $page->query('.//*[@name]', $panel)->length, "$path: общих полей шага 2 нет");
            $this->assertSame(1, $page->query('.//*[@data-cards]', $panel)->length, $path);
            $this->assertSame([$intro, $hint], Html::texts($page, '//*[@data-step-panel="1"]/p'), $path);
            $this->assertSame(asset('icons/ui/info.svg'), $page->query('//*[@data-step-panel="1"]/p[contains(@class, "part-note")]/img')->item(0)->getAttribute('src'));
            $texts = json_decode($page->query('//*[@id="quote"]')->item(0)->getAttribute('data-texts'), true);
            $this->assertSame($part, $texts['part'], $path);
            $settings = $texts['settings'];
            $this->assertSame($labels, [$settings['material'], $settings['color'], $settings['purpose'], $settings['service'], $settings['surface'], $settings['infill']], $path);
            $this->assertSame($services, $settings['services'], $path);
            foreach (['intro', 'fdm', 'term_note', 'quantities'] as $removed) {
                $this->assertArrayNotHasKey($removed, $settings, "$path: {$removed}");
            }
            $text = Html::text($panel);
            foreach (['Production lead time', 'Gamybos terminas', 'FDM', 'Part quantities', 'Detalių kiekiai'] as $old) {
                $this->assertStringNotContainsString($old, $text, $path);
            }
        }
    }

    /**
     * Правка 05.11, H-04: шага «Kaina» нет; панель «Užsakymo suvestinė» без надписи «PRELIMINARI SĄMATA», сумм и итога — строки
     * «имя × количество» строит JS, заметка и «Reikia pagalbos?» — по README. Форма без VAT, доставки, скидки (D-38).
     */
    public function test_no_price_step_summary_without_sums_and_checkout_without_vat_or_discount(): void
    {
        foreach ([
            '/en/quote' => ['Order summary', 'We will confirm the final price and production time after reviewing your request.', 'Need help?'],
            '/lt/kalkuliatorius' => ['Užsakymo suvestinė', 'Galutinę kainą ir gamybos terminą patvirtinsime peržiūrėję jūsų užklausą.', 'Reikia pagalbos?'],
        ] as $path => [$title, $note, $help]) {
            $page = $this->page($path);
            $this->assertSame([$title, $help], Html::texts($page, '//aside//h3'), $path);
            $this->assertSame($note, Html::text($page->query('//aside/div[contains(@class, "summary-note")]')->item(0)), $path);
            $this->assertSame(0, $page->query('//aside//*[contains(@class, "eyebrow")] | //aside//*[contains(@class, "total")] | //*[@data-total] | //*[@data-price-rows] | //table')->length, $path);
            $main = Html::text($page->query('//main')->item(0));
            foreach (['PRELIMINARI', 'PRELIMINARY', 'Sąmata', 'Estimate', 'Preliminari suma', 'Estimated total', '€'] as $phrase) {
                $this->assertStringNotContainsString($phrase, $main, "$path: «{$phrase}»");
            }
        }
        $en = $this->page('/en/quote');
        $this->assertSame(1, $en->query('//form[@id="checkout-form"]//input[@name="vat_code"]')->length);
        $this->assertSame(['Delivery throughout the EU', 'Collection'], Html::texts($en, '//select[@name="delivery"]/option'));
        // Отправка заявки включена (W-12): кнопка доступна, надписи «недоступно» нет.
        $submit = $en->query('//form[@id="checkout-form"]//button[@type="submit"]')->item(0);
        $this->assertSame(['Submit order', false], [Html::text($submit), $submit->hasAttribute('disabled')]);
        $this->assertSame(0, $en->query('//*[@id="quote-unavailable"]')->length);
        $lt = $this->page('/lt/kalkuliatorius');
        $this->assertSame('PVM kodas (neprivaloma)', Html::text($lt->query('//label[@for="field-vat_code"]')->item(0)));
        $this->assertSame('Pateikti užsakymą', Html::text($lt->query('//form[@id="checkout-form"]//button[@type="submit"]')->item(0)));
    }

    public function test_no_demo_phrases_technologies_or_vat_labels(): void
    {
        foreach (['/en/quote', '/lt/kalkuliatorius'] as $path) {
            $main = Html::text($this->page($path)->query('//main')->item(0));
            foreach (['excl. VAT', 'be PVM', 'VAT 21', 'PVM 21', 'Delivery (demo)', 'discount', 'nuolaida', 'Vnt.',
                'Prepaid', 'iš anksto', 'Metal', 'Try an issue scenario', 'neatitikimo scenarijų', 'Payment is blocked',
                'Resolve the review warnings', 'watertightness', 'sandarumas', 'example', 'pavyzd', 'demo', 'illustrative',
                'prototype', 'prototip', '15 MB', 'working days', 'darbo dienos', 'sample'] as $phrase) {
                $this->assertStringNotContainsStringIgnoringCase($phrase, $main, "$path: «{$phrase}»");
            }
            // Технологии — целыми словами: «Vizualiniams tikslams» шага 2 (правка 28.10) содержит «sla».
            $this->assertDoesNotMatchRegularExpression('/\\b(SLA|SLS)\\b/i', $main, $path);
        }
    }

    public function test_specialist_link_follows_contact_prefill_contract(): void
    {
        $link = fn (string $path): string => $this->page($path)->query('//aside//a[@data-specialist]')->item(0)->getAttribute('href');
        $this->assertSame(route('en.contact'), $link('/en/quote'), 'без опубликованной услуги печати — без service');

        $this->seed(PrototypeContentSeeder::class);
        $this->assertSame(route('en.contact', ['service' => '3d-printing']), $link('/en/quote'));
        $this->assertSame(route('lt.contact', ['service' => '3d-spausdinimas']), $link('/lt/kalkuliatorius'));
        $this->assertSame(route('en.contact', ['service' => '3d-printing']),
            $this->page('/en/quote')->query('//*[@id="quote"]')->item(0)->getAttribute('data-contact-url'));
    }
}
