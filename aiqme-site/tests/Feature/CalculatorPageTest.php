<?php

namespace Tests\Feature;

use App\Domain\Pricing\CalculatorSettings;
use Database\Seeders\PrototypeContentSeeder;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Калькулятор W-27 (§1.2): разметка 5 шагов, значения D-09/D-24 (настройки W-11) и §6.4 (конфигурация) в data-атрибутах, шаг 2 без
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
            'file_fee_cents' => 0, 'default_infill' => 20, 'max_dimension_mm' => 1000, 'max_file_bytes' => 52428800, 'max_files' => 10,
        ], json_decode($root->getAttribute('data-settings'), true));
        $texts = json_decode($root->getAttribute('data-texts'), true);
        $this->assertSame('Reading files...', $texts['reading']);
        $this->assertSame(50, $texts['mb']);
        $this->assertSame('en', $root->getAttribute('data-locale'));
    }

    /** П7: габарит 1000 мм в тексте шага 3 обеих локалей (значение из настроек, знак ×, без разделителя тысяч). */
    public function test_review_step_shows_build_size_of_1000_mm(): void
    {
        foreach (['/lt/kalkuliatorius' => 'Įrangos dydžio ribos', '/en/quote' => 'Build size limits'] as $path => $heading) {
            $row = $this->page($path)->query('//*[@data-step-panel="2"]//div[@class="check-row"]/div')->item(0);
            $this->assertSame([$heading, 'FDM: 1000 × 1000 × 1000 mm.'], [Html::text($row->getElementsByTagName('h3')->item(0)), Html::text($row->getElementsByTagName('p')->item(0))], $path);
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
        $this->assertSame('.stl,.step,.stp,.obj', $en->query('//input[@id="model-upload"]')->item(0)->getAttribute('accept'));
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

    public function test_size_limit_label_comes_from_config(): void
    {
        $this->assertSame('Up to 50 MB per file. STL files are priced instantly; STEP and OBJ files are priced by a specialist.',
            Html::text($this->page('/en/quote')->query('//*[@id="drop-zone"]/p')->item(0)));

        config(['aiqme.calculator.max_file_bytes' => 10 * 1024 * 1024]);
        $this->assertSame('Iki 10 MB vienam failui. STL failų kaina apskaičiuojama iš karto, STEP ir OBJ failus įvertina specialistas.',
            Html::text($this->page('/lt/kalkuliatorius')->query('//*[@id="drop-zone"]/p')->item(0)));
    }

    public function test_step_two_has_no_technology_and_infill_list_of_prototype_with_default(): void
    {
        $en = $this->page('/en/quote');
        $this->assertSame(0, $en->query('//*[contains(@class, "tech-choices")]')->length);
        $this->assertSame(['PLA', 'PETG', 'ABS'], Html::texts($en, '//select[@name="material"]/option'));
    }

    /** Шаг 2 по макету заказчика (правка 28.10, E-05): состав, порядок и варианты полей, пояснение срока, notes. */
    public function test_step_two_follows_customer_layout_of_28_10(): void
    {
        $en = $this->page('/en/quote');
        $panel = $en->query('//*[@data-step-panel="1"]')->item(0);
        $names = [];
        foreach ($en->query('.//*[@name]', $panel) as $field) {
            $names[] = $field->getAttribute('name');
        }
        $this->assertSame(['material', 'color', 'purpose', 'service', 'surface', 'infill', 'term', 'notes'], $names);
        foreach (['quality', 'layer', 'finish'] as $old) {
            $this->assertSame(0, $en->query('//*[@name="'.$old.'"]')->length, $old);
        }
        $selected = fn (DOMXPath $page, string $name): string => $page->query('//select[@name="'.$name.'"]/option[@selected]')->item(0)?->getAttribute('value') ?? '';

        $this->assertSame(['15%', '20% · recommended', '40%', '60%', '80%', '100%', 'Consultation needed'], Html::texts($en, '//select[@name="infill"]/option'));
        $this->assertSame('consult', $en->query('//select[@name="infill"]/option[last()]')->item(0)->getAttribute('value'));
        $this->assertSame('20', $selected($en, 'infill'));
        $this->assertSame(['Visual purposes', 'Practical use', 'Consultation needed'], Html::texts($en, '//select[@name="purpose"]/option'));
        $this->assertSame('', $selected($en, 'purpose'));
        $this->assertSame(['Not needed', 'Sanding and painting', 'Threaded insert installation', 'Part assembly', 'Consultation needed'], Html::texts($en, '//select[@name="service"]/option'));
        $this->assertSame('', $selected($en, 'service'));
        $this->assertSame(['High', 'Standard', 'Simplified', 'Consultation needed'], Html::texts($en, '//select[@name="surface"]/option'));
        $this->assertSame('standard', $selected($en, 'surface'));
        $this->assertSame(['Standard', 'Urgent'], Html::texts($en, '//select[@name="term"]/option'));
        $this->assertSame('If you choose urgent production, state the required completion date in the “Additional information” field. The lead time is counted from order confirmation. We will agree the exact lead time after reviewing the model and quantity.',
            Html::text($en->query('//label[select[@name="term"]]/following-sibling::p[@class="legal-note"]')->item(0)));
        $text = Html::text($panel);
        foreach (['do not change the estimate', 'A smaller layer', 'Quality level', 'Layer height', 'Requested schedule'] as $old) {
            $this->assertStringNotContainsString($old, $text);
        }
        $notes = $en->query('//*[@data-quantities]/following-sibling::div[contains(@class, "gap")]//textarea[@name="notes"]')->item(0);
        $this->assertNotNull($notes);
        $this->assertSame('2000', $notes->getAttribute('maxlength'));
        $this->assertSame('Additional information', trim($en->query('//label[textarea[@name="notes"]]/text()[1]')->item(0)->textContent));

        $settings = app(CalculatorSettings::class);
        $settings->save(['default_infill' => 30] + $settings->values());
        $lt = $this->page('/lt/kalkuliatorius');
        $this->assertSame(['15 %', '20 %', '30 % · rekomenduojama', '40 %', '60 %', '80 %', '100 %', 'Reikia konsultacijos'], Html::texts($lt, '//select[@name="infill"]/option'));
        $this->assertSame('30', $selected($lt, 'infill'));
        $labels = [];
        foreach (['material', 'color', 'purpose', 'service', 'surface', 'infill', 'term'] as $name) {
            $labels[] = trim($lt->query('//label[select[@name="'.$name.'"]]/text()[1]')->item(0)->textContent);
        }
        $labels[] = trim($lt->query('//label[textarea[@name="notes"]]/text()[1]')->item(0)->textContent);
        $this->assertSame(['Medžiaga', 'Spalva', 'Detalės paskirtis', 'Papildomos paslaugos', 'Paviršiaus kokybė', 'Užpildas', 'Gamybos terminas', 'Papildoma informacija'], $labels);
        $this->assertSame('FDM parametrai', Html::text($lt->query('//*[@data-step-panel="1"]//*[contains(@class, "inset")]/h3')->item(0)));
    }

    public function test_price_table_summary_and_checkout_have_no_vat_delivery_discount_or_unit_price(): void
    {
        $en = $this->page('/en/quote');
        $this->assertSame(['Part', 'Quantity', 'Estimate'], Html::texts($en, '//*[@data-step-panel="3"]//th'));
        $this->assertSame(['Estimated total'], Html::texts($en, '//aside//div[contains(@class, "total")]/span[1]'));
        $this->assertSame('Preliminary estimate. If the model needs support material for printing, the price may increase. '
            .'The administrator confirms the final price and delivery after reviewing your order.',
            Html::text($en->query('//aside/div[contains(@class, "summary-note")]')->item(0)));
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
