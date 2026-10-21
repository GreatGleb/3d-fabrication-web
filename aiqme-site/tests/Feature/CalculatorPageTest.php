<?php

namespace Tests\Feature;

use Database\Seeders\PrototypeContentSeeder;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Калькулятор W-27 (§1.2): разметка 5 шагов, значения D-09/D-24/§6.4 из конфигурации в data-атрибутах, шаг 2 без
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

    public function test_settings_and_texts_come_from_config_through_data_attributes(): void
    {
        $root = $this->page('/en/quote')->query('//*[@id="quote"]')->item(0);

        $this->assertSame(config('aiqme.calculator'), json_decode($root->getAttribute('data-settings'), true));
        $this->assertSame(['PLA' => 1.24, 'PETG' => 1.27, 'ABS' => 1.04], config('aiqme.calculator.materials'));
        $this->assertSame(['0.10', 500, 0, 20, 250, 26214400, 10], array_values(array_slice(config('aiqme.calculator'), 1)));
        $texts = json_decode($root->getAttribute('data-texts'), true);
        $this->assertSame('Reading files...', $texts['reading']);
        $this->assertSame(25, $texts['mb']);
        $this->assertSame('en', $root->getAttribute('data-locale'));
    }

    public function test_five_steps_and_texts_of_both_locales(): void
    {
        $lt = $this->page('/lt/kalkuliatorius');
        $this->assertSame(['1Failai', '2Gamyba', '3Patikra', '4Kaina', '5Užsakymas'], Html::texts($lt, '//div[@class="steps"]/button'));
        $this->assertSame('AIQME / GAMYBOS UŽSAKYMAS', Html::text($lt->query('//section[@class="page-top"]//div[@class="eyebrow"]')->item(0)));

        $en = $this->page('/en/quote');
        $this->assertSame(['1Files', '2Production', '3Review', '4Price', '5Checkout'], Html::texts($en, '//div[@class="steps"]/button'));
        $this->assertSame(['01. Upload your models', '02. Production settings', '03. Model review', '04. Part pricing', 'Buyer & delivery'],
            Html::texts($en, '//*[@data-step-panel]/h2'));
        $this->assertSame(1, $en->query('//*[@data-step-panel and not(@hidden)]')->length, 'видим только шаг 1');
        $this->assertSame('.stl,.step,.stp,.obj', $en->query('//input[@id="model-upload"]')->item(0)->getAttribute('accept'));
        $this->assertSame(1, $en->query('//*[@data-viewer and contains(@class, "viewer")]')->length, 'место просмотра W-31');
    }

    public function test_size_limit_label_comes_from_config(): void
    {
        $this->assertSame('Up to 25 MB per file. STEP files require specialist review.',
            Html::text($this->page('/en/quote')->query('//*[@id="drop-zone"]/p')->item(0)));

        config(['aiqme.calculator.max_file_bytes' => 10 * 1024 * 1024]);
        $this->assertSame('Iki 10 MB vienam failui. STEP failui reikalinga specialisto patikra.',
            Html::text($this->page('/lt/kalkuliatorius')->query('//*[@id="drop-zone"]/p')->item(0)));
    }

    public function test_step_two_has_no_technology_and_infill_list_of_prototype_with_default(): void
    {
        $en = $this->page('/en/quote');
        $this->assertSame(0, $en->query('//*[contains(@class, "tech-choices")]')->length);
        $this->assertSame(['PLA', 'PETG', 'ABS'], Html::texts($en, '//select[@name="material"]/option'));
        $this->assertSame(['20% · recommended', '40%', '60%', '100%'], Html::texts($en, '//select[@name="infill"]/option'));
        $this->assertSame('20', $en->query('//select[@name="infill"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame(['Standard', 'Priority'], Html::texts($en, '//select[@name="term"]/option'));
        $this->assertStringContainsString('do not change the estimate', Html::text($en->query('//*[@data-step-panel="1"]')->item(0)));

        config(['aiqme.calculator.default_infill' => 30]);
        $lt = $this->page('/lt/kalkuliatorius');
        $this->assertSame(['20 %', '30 % · rekomenduojama', '40 %', '60 %', '100 %'], Html::texts($lt, '//select[@name="infill"]/option'));
        $this->assertSame('30', $lt->query('//select[@name="infill"]/option[@selected]')->item(0)->getAttribute('value'));
    }

    public function test_price_table_summary_and_checkout_have_no_vat_delivery_discount_or_unit_price(): void
    {
        $en = $this->page('/en/quote');
        $this->assertSame(['Part', 'Quantity', 'Estimate'], Html::texts($en, '//*[@data-step-panel="3"]//th'));
        $this->assertSame(['Estimated total'], Html::texts($en, '//aside//div[contains(@class, "total")]/span[1]'));
        $this->assertSame('Preliminary estimate. Delivery and the final price are confirmed by the administrator.',
            Html::text($en->query('//aside/p[@class="legal-note"]')->item(0)));
        $this->assertSame(1, $en->query('//form[@id="checkout-form"]//input[@name="vat_code"]')->length);
        $this->assertSame(['Delivery throughout the EU', 'Collection'], Html::texts($en, '//select[@name="delivery"]/option'));
        $submit = $en->query('//form[@id="checkout-form"]//button[@type="submit"]')->item(0);
        $this->assertTrue($submit->hasAttribute('disabled'));
        $this->assertSame('Order submission is not available yet.', Html::text($en->query('//*[@id="quote-unavailable"]')->item(0)));

        $lt = $this->page('/lt/kalkuliatorius');
        $this->assertSame(['Detalė', 'Kiekis', 'Sąmata'], Html::texts($lt, '//*[@data-step-panel="3"]//th'));
        $this->assertSame('PVM kodas (neprivaloma)', Html::text($lt->query('//label[@for="field-vat_code"]')->item(0)));
    }

    public function test_no_demo_phrases_technologies_or_vat_labels(): void
    {
        foreach (['/en/quote', '/lt/kalkuliatorius'] as $path) {
            $main = Html::text($this->page($path)->query('//main')->item(0));
            foreach (['excl. VAT', 'be PVM', 'VAT 21', 'PVM 21', 'Delivery (demo)', 'discount', 'nuolaida', 'Vnt.',
                'Prepaid', 'iš anksto', 'SLA', 'SLS', 'Metal', 'Try an issue scenario', 'neatitikimo scenarijų', 'Payment is blocked',
                'Resolve the review warnings', 'watertightness', 'sandarumas', 'example', 'pavyzd', 'demo', 'illustrative',
                'prototype', 'prototip', '15 MB', 'working days', 'darbo dienos', 'sample'] as $phrase) {
                $this->assertStringNotContainsStringIgnoringCase($phrase, $main, "$path: «{$phrase}»");
            }
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
