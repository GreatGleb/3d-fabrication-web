<?php

namespace Tests\Feature;

use Database\Seeders\PrototypeContentSeeder;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Калькулятор W-27 (§1.2; правка 02.11, Г8 — карточки шага 2 и проверку шага 3 строит JS): разметка 5 шагов, значения D-09/D-24 (настройки W-11) и §6.4 (конфигурация) в data-атрибутах, шаг 2 без
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

        // Чистая БД после migrate: начальные настройки W-11 (D-09, D-24) и лимиты config (§6.4).
        $this->assertSame([
            'materials' => ['PLA' => 1.24, 'PETG' => 1.27, 'ABS' => 1.04], 'rate_per_gram' => '0.10', 'order_fee_cents' => 500,
            'file_fee_cents' => 0, 'default_infill' => 20, 'max_dimension_mm' => 1000,
            // Правка 02.11, Г6: заглушки фрезеровки и гравировки из миграции данных.
            ...(require database_path('migrations/2026_11_03_100000_add_milling_engraving_calculator_settings.php'))::VALUES,
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
        $this->assertSame('Price to be assessed by a specialist', $texts['price']['specialist']);
        $this->assertSame(asset('icons/ui'), $root->getAttribute('data-icons'));
        $this->assertArrayNotHasKey('specialist_format', $texts);
    }

    /**
     * Шаг 3 по файлам (правка 02.11, Г8): блоки строит JS по data-texts — строка FDM (П7: 1000 мм из настроек), строка предела
     * фрезеровки и гравировки и «Failą peržiūrės specialistas.» для файлов без разбора; общей строки FDM в разметке нет.
     */
    public function test_review_step_texts_of_both_locales(): void
    {
        foreach ([
            '/lt/kalkuliatorius' => ['Įrangos dydžio ribos', 'FDM: :mm × :mm × :mm mm.', 'Frezavimo ir graviravimo matmenys :w × :h mm.', 'Failą peržiūrės specialistas.'],
            '/en/quote' => ['Build size limits', 'FDM: :mm × :mm × :mm mm.', 'Milling and engraving dimensions: :w × :h mm.', 'A specialist will review the file.'],
        ] as $path => $expected) {
            $page = $this->page($path);
            $review = json_decode($page->query('//*[@id="quote"]')->item(0)->getAttribute('data-texts'), true)['review'];
            $this->assertSame($expected, [$review['build'], $review['build_text'], $review['cnc'], $review['specialist_file']], $path);
            $this->assertSame(0, $page->query('//*[@data-step-panel="2"]//*[contains(@class, "check-row")]')->length, $path);
            $this->assertSame(1, $page->query('//*[@data-step-panel="2"]//*[@data-review]')->length, $path);
        }
    }

    public function test_five_steps_and_texts_of_both_locales(): void
    {
        $lt = $this->page('/lt/kalkuliatorius');
        $this->assertSame(['1Failai', '2Gamyba', '3Patikra', '4Kaina', '5Užsakymas'], Html::texts($lt, '//div[@class="steps"]/button'));
        $this->assertCount(0, $lt->query('//section[@class="page-top"]//div[@class="eyebrow"]')); // правка 30.10, Г2

        $en = $this->page('/en/quote');
        $this->assertSame(['1Files', '2Production', '3Review', '4Price', '5Checkout'], Html::texts($en, '//div[@class="steps"]/button'));
        $this->assertSame(['01. Upload your models', '02. Production settings', '03. Model review', '04. Part pricing', 'Buyer & delivery'],
            Html::texts($en, '//*[@data-step-panel]/h2'));
        $this->assertSame(1, $en->query('//*[@data-step-panel and not(@hidden)]')->length, 'видим только шаг 1');
        // Правка 02.11, Г8: принимаются DXF и DWG.
        $this->assertSame('.stl,.step,.stp,.obj,.dxf,.dwg', $en->query('//input[@id="model-upload"]')->item(0)->getAttribute('accept'));
        $this->assertSame(1, $en->query('//*[@data-viewer and contains(@class, "viewer")]')->length, 'место просмотра W-31');
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
        // Надпись области и кнопка не меняются (17.17).
        $this->assertSame(['Nutempkite failus čia arba pasirinkite', 'Pasirinkti 3D modelius'],
            [Html::text($lt->query('//*[@id="drop-zone"]/strong')->item(0)), Html::text($lt->query('//*[@id="drop-zone"]/span[contains(@class, "btn")]')->item(0))]);
    }

    public function test_step_two_has_no_technology_choice_of_prototype(): void
    {
        $en = $this->page('/en/quote');
        $this->assertSame(0, $en->query('//*[contains(@class, "tech-choices")]')->length);
        // Правка 02.11, Г8: материалы печати — из настроек в data-settings (списки карточки строит JS).
        $this->assertSame(['PLA', 'PETG', 'ABS'], array_keys(json_decode($en->query('//*[@id="quote"]')->item(0)->getAttribute('data-settings'), true)['materials']));
    }

    /**
     * Шаг 2 по макету 17.09 (правка 02.11, Г8): общих полей, общего срока и его пояснения нет — карточки на каждый файл строит
     * JS ([data-cards]); подзаголовок и нижняя подсказка — дословно из README плана; тексты карточки (способы, поля, приоритет,
     * дата, заметка) и прежние списки печати (правка 28.10, E-05; 30.10, Г3) — в data-texts.
     */
    public function test_step_two_has_cards_with_texts_of_the_plan(): void
    {
        foreach ([
            '/en/quote' => [
                'Choose the manufacturing method and parameters for each model.', 'A separate production deadline can be chosen for each part.',
                ['thickness' => 'Thickness', 'depth' => 'Engraving depth', 'urgent' => 'Urgent', 'needed_by' => 'Needed by',
                    'intro' => 'Choose the manufacturing method and parameters for each model.', 'hint' => 'A separate production deadline can be chosen for each part.',
                    'change' => 'Change parameters', 'method' => 'Manufacturing method', 'priority' => 'Production priority',
                    'rush_note' => 'The rush deadline will be confirmed separately.', 'notes' => 'Additional information (optional)',
                    'notes_placeholder' => 'E.g. additional requirements, remarks or other important instructions…'],
                ['Material', 'Colour', 'Part purpose', 'Additional services (optional)', 'Surface quality', 'Infill'],
                ['none' => 'Not selected', 'painting' => 'Sanding and painting', 'inserts' => 'Threaded insert installation', 'assembly' => 'Part assembly', 'consult' => 'Consultation needed'],
            ],
            '/lt/kalkuliatorius' => [
                'Kiekvienam modeliui pasirinkite gamybos būdą ir parametrus.', 'Kiekvienai detalei galima pasirinkti atskirą gamybos terminą.',
                ['thickness' => 'Storis', 'depth' => 'Graviravimo gylis', 'urgent' => 'Skubu', 'needed_by' => 'Reikalinga iki',
                    'intro' => 'Kiekvienam modeliui pasirinkite gamybos būdą ir parametrus.', 'hint' => 'Kiekvienai detalei galima pasirinkti atskirą gamybos terminą.',
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

    public function test_price_table_summary_and_checkout_have_no_vat_delivery_discount_or_unit_price(): void
    {
        $en = $this->page('/en/quote');
        $this->assertSame(['Part', 'Quantity', 'Estimate'], Html::texts($en, '//*[@data-step-panel="3"]//th'));
        $this->assertSame(['Estimated total'], Html::texts($en, '//aside//div[contains(@class, "total")]/span[1]'));
        // Правка 02.11, Г2: новый текст заметки панели (LT дословно от заказчика, EN — перевод плана).
        $this->assertSame('The administrator will confirm the final price and delivery date after reviewing the order.',
            Html::text($en->query('//aside/div[contains(@class, "summary-note")]')->item(0)));
        $this->assertSame('Galutinę kainą ir pristatymo terminą administratorius patvirtins, peržiūrėjęs užsakymą.',
            Html::text($this->page('/lt/kalkuliatorius')->query('//aside/div[contains(@class, "summary-note")]')->item(0)));
        $this->assertSame(1, $en->query('//form[@id="checkout-form"]//input[@name="vat_code"]')->length);
        $this->assertSame(['Delivery throughout the EU', 'Collection'], Html::texts($en, '//select[@name="delivery"]/option'));
        // Отправка заявки включена (W-12): кнопка доступна, надписи «недоступно» нет.
        $submit = $en->query('//form[@id="checkout-form"]//button[@type="submit"]')->item(0);
        $this->assertSame(['Submit order', false], [Html::text($submit), $submit->hasAttribute('disabled')]);
        $this->assertSame(0, $en->query('//*[@id="quote-unavailable"]')->length);

        $lt = $this->page('/lt/kalkuliatorius');
        $this->assertSame(['Detalė', 'Kiekis', 'Sąmata'], Html::texts($lt, '//*[@data-step-panel="3"]//th'));
        $this->assertSame('PVM kodas (neprivaloma)', Html::text($lt->query('//label[@for="field-vat_code"]')->item(0)));
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
