<?php

namespace Tests\Feature;

use App\Domain\Pricing\CalculatorSettings as Settings;
use App\Domain\Pricing\EstimateService;
use App\Livewire\Admin\CalculatorSettings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Настройки калькулятора (W-11 done[2–4], §1.4 Calculator): вкладка админки, пометка «не подтверждено», запись в settings
 * и вывод тех же значений на страницах калькулятора и в EstimateService; лимиты файлов — только config (§6.4).
 */
class CalculatorSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $this->admin->save();
        app()->setLocale('en');
    }

    /** @return array<string, mixed> */
    private function pageSettings(string $path): array
    {
        $root = Html::xpath($this->get($path)->assertOk()->getContent())->query('//*[@id="quote"]')->item(0);

        return json_decode($root->getAttribute('data-settings'), true);
    }

    /** @return array<string, mixed> заглушки фрезеровки и гравировки (правка 02.11, Г6) */
    private static function millingDefaults(): array
    {
        return (require database_path('migrations/2026_11_03_100000_add_milling_engraving_calculator_settings.php'))::VALUES;
    }

    private function valid(): Testable
    {
        return Livewire::actingAs($this->admin)->test(CalculatorSettings::class);
    }

    public function test_clean_database_after_migrate_has_d09_d24_values_on_the_calculator_pages(): void
    {
        $expected = [
            'materials' => ['PLA' => 1.24, 'PETG' => 1.27, 'ABS' => 1.04], 'rate_per_gram' => '0.10', 'order_fee_cents' => 500,
            'file_fee_cents' => 0, 'default_infill' => 20, 'max_dimension_mm' => 1000,
            // Правка 02.11, Г6: заглушки фрезеровки и гравировки из миграции данных.
            ...self::millingDefaults(), 'max_file_bytes' => 52428800, 'max_files' => 10,
        ];
        $this->assertSame($expected, $this->pageSettings('/en/quote'));
        $this->assertSame($expected, $this->pageSettings('/lt/kalkuliatorius'));
        $this->assertSame(['materials', 'rate_per_gram', 'order_fee_cents', 'file_fee_cents'], app(Settings::class)->unconfirmed());
    }

    /** П7: габарит 1000 мм подтверждён заказчиком 28.10 — миграция данных меняет только исходные 250 мм W-11. */
    public function test_migration_sets_confirmed_1000_mm_only_over_the_initial_250(): void
    {
        $migration = require database_path('migrations/2026_10_29_000000_set_calculator_max_dimension_1000.php');
        $settings = app(Settings::class);

        // (а) Запись в виде старой миграции W-11: 250 и пометка → 1000 без пометки; остальное без изменений.
        Setting::query()->delete();
        (require database_path('migrations/2026_10_21_100001_insert_calculator_settings.php'))->up();
        $before = $settings->values();
        $migration->up();
        $this->assertSame([...$before, 'max_dimension_mm' => 1000], $settings->values());
        $this->assertSame(['materials', 'rate_per_gram', 'order_fee_cents', 'file_fee_cents'], $settings->unconfirmed());
        $after = Setting::sole()->getRawOriginal('value');
        $migration->up();
        $this->assertSame($after, Setting::sole()->getRawOriginal('value'));

        // down() при 1000 возвращает 250 и пометку.
        $migration->down();
        $this->assertSame(250, $settings->values()['max_dimension_mm']);
        $this->assertSame(['materials', 'rate_per_gram', 'order_fee_cents', 'file_fee_cents', 'max_dimension_mm'], $settings->unconfirmed());

        // (б) Значение администратора (не 250) сохраняется.
        $settings->save(['max_dimension_mm' => 320] + $settings->values());
        $migration->up();
        $this->assertSame(320, $settings->values()['max_dimension_mm']);
        $this->assertSame(['materials', 'rate_per_gram', 'order_fee_cents', 'file_fee_cents'], $settings->unconfirmed());

        // (в) Записи нет — без ошибки и без вставки.
        Setting::query()->delete();
        $migration->up();
        $this->assertSame(0, Setting::count());
    }

    public function test_data_migration_does_not_overwrite_existing_settings(): void
    {
        $settings = app(Settings::class);
        $settings->save(['rate_per_gram' => '0.2'] + $settings->values());
        (require database_path('migrations/2026_10_21_100001_insert_calculator_settings.php'))->up();

        $this->assertSame('0.2', $settings->values()['rate_per_gram']);
        $this->assertSame(1, Setting::count());
    }

    public function test_admin_tab_shows_prototype_heading_and_unconfirmed_marks_without_demo_prices(): void
    {
        foreach ([
            '/en/admin/calculator' => ['Materials, settings &amp; pricing', 'Price per gram, € · Not confirmed', 'Build size, mm', 'Save parameters'],
            '/lt/administravimas/calculator' => ['Medžiagos, parametrai ir kainodara', 'Kaina už gramą, € · Nepatvirtinta', 'Įrangos kraštinė, mm', 'Išsaugoti parametrus'],
        ] as $path => $texts) {
            $html = $this->actingAs($this->admin)->get($path)->assertOk()->getContent();
            foreach ($texts as $text) {
                $this->assertStringContainsString($text, $html, $path);
            }
            foreach (['Build size, mm · Not confirmed', 'Įrangos kraštinė, mm · Nepatvirtinta', 'FDM · ba', 'SLA', 'Metal', 'discount', 'nuolaida', 'Illustrative', 'demonstracinės', 'Demo', 'max_file', '52428800'] as $phrase) {
                $this->assertStringNotContainsString($phrase, $html, $path);
            }
        }
        $this->get('/en/admin/calculator')->assertSee('Default infill, %')->assertDontSee('Default infill, % · Not confirmed');
    }

    public function test_tab_and_component_are_404_for_guest_and_customer(): void
    {
        $this->get('/en/admin/calculator')->assertNotFound();
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/lt/administravimas/calculator')->assertNotFound();
        Livewire::actingAs($customer)->test(CalculatorSettings::class)->assertStatus(404);
    }

    public function test_saved_settings_reach_both_calculator_pages_and_the_estimate_not_config(): void
    {
        $this->valid()
            ->assertSet('ratePerGram', '0.10')->assertSet('orderFee', '5.00')->assertSet('fileFee', '0.00')
            ->set('materials.0.density', '1.3')
            ->set('materials.2.name', '')
            ->set('materials.3', ['name' => 'TPU', 'density' => '1.21'])
            ->set('ratePerGram', '0.125')
            ->set('orderFee', '7.5')
            ->set('fileFee', '1.05')
            ->set('defaultInfill', '30')
            ->set('maxDimension', '300')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Saved.')
            ->assertSet('unconfirmed', [])
            ->assertSet('materials', [['name' => 'PLA', 'density' => '1.3'], ['name' => 'PETG', 'density' => '1.27'], ['name' => 'TPU', 'density' => '1.21'], ['name' => '', 'density' => '']]);

        // Конфигурация тарифов больше не читается: посторонние значения в config не попадают на страницу.
        config(['aiqme.calculator.rate_per_gram' => '9.99', 'aiqme.calculator.default_infill' => 77, 'aiqme.calculator.max_dimension_mm' => 5]);
        $expected = [
            'materials' => ['PLA' => 1.3, 'PETG' => 1.27, 'TPU' => 1.21], 'rate_per_gram' => '0.125', 'order_fee_cents' => 750,
            'file_fee_cents' => 105, 'default_infill' => 30, 'max_dimension_mm' => 300,
            // Правка 02.11, Г6: списков фрезеровки и гравировки нет в форме — сохранение их не теряет.
            ...self::millingDefaults(), 'max_file_bytes' => 52428800, 'max_files' => 10,
        ];
        $this->assertSame($expected, array_intersect_key($this->pageSettings('/en/quote'), $expected));
        $lt = $this->pageSettings('/lt/kalkuliatorius');
        $this->assertSame($expected, $lt);
        // Правка 02.11, Г8: список материалов карточки строит JS из data-settings.
        $this->assertSame(['PLA', 'PETG', 'TPU'], array_keys(json_decode(Html::xpath($this->get('/en/quote')->getContent())->query('//*[@id="quote"]')->item(0)->getAttribute('data-settings'), true)['materials']));

        // EstimateService — по тем же значениям: 1 см³ PLA 1,3 г × 0,125 €/г = 16,25 → 16 центов; 750 + 2 × 105.
        $estimate = app(EstimateService::class)->estimate([['volume_cm3' => 1.0, 'quantity' => 1, 'material' => 'PLA', 'infill' => 100], ['volume_cm3' => 2.0, 'quantity' => 1, 'material' => 'PLA', 'infill' => 100]]);
        $this->assertSame([16, 33], array_column($estimate['lines'], 'cents'));
        $this->assertSame(16 + 33 + 750 + 210, $estimate['total_cents']);
        $this->assertSame(array_diff_key($lt, ['max_file_bytes' => 0, 'max_files' => 0]), $estimate['settings']);
    }

    public function test_unconfirmed_mark_is_removed_only_from_changed_values(): void
    {
        $this->valid()->set('maxDimension', '320')->call('save')->assertHasNoErrors()
            ->assertSet('unconfirmed', ['materials', 'rate_per_gram', 'order_fee_cents', 'file_fee_cents'])
            ->assertSee('Price per gram, € · Not confirmed')->assertDontSee('Build size, mm · Not confirmed');
        $this->valid()->call('save')->assertSet('unconfirmed', ['materials', 'rate_per_gram', 'order_fee_cents', 'file_fee_cents']);
        $this->valid()->set('orderFee', '6')->call('save')->assertSet('unconfirmed', ['materials', 'rate_per_gram', 'file_fee_cents']);
        $this->assertSame(600, app(Settings::class)->values()['order_fee_cents']);
    }

    public function test_invalid_values_are_not_saved(): void
    {
        $before = app(Settings::class)->values();
        foreach ([
            ['ratePerGram', '-0.1'], ['ratePerGram', '0.1.2'], ['ratePerGram', "0.1\n"], ['orderFee', '5.001'], ['fileFee', ''],
            ['defaultInfill', '101'], ['defaultInfill', '2.5'], ['maxDimension', '9'], ['maxDimension', '2001'],
            ['materials.0.density', '0'], ['materials.0.density', 'abc'], ['materials.1.name', 'PLA'], ['materials.3.name', '123'],
            ['materials.3', ['name' => 'Nylon', 'density' => '']],
        ] as [$field, $value]) {
            $component = $this->valid()->set($field, $value)->call('save');
            $this->assertNotEmpty($component->errors()->all(), "{$field} = ".json_encode($value));
            $this->assertNull($component->get('status'));
        }
        $this->valid()->set('materials', [['name' => '', 'density' => '1'], ['name' => '', 'density' => '']])->call('save')->assertHasErrors('materials.0.name');
        $this->assertSame($before, app(Settings::class)->values());
        $this->assertSame('Check the value.', $this->valid()->set('maxDimension', '1')->call('save')->errors()->first('maxDimension'));
    }

    /** Правка 02.11, Г7: списки фрезеровки и гравировки и предел редактируются в той же форме. */
    public function test_milling_and_engraving_lists_and_limit_are_edited_and_reach_the_calculator(): void
    {
        $this->valid()
            ->assertSet('millingMaterials', [...self::millingDefaults()['milling_materials'], ['lt' => '', 'en' => '']])
            ->assertSet('millingThicknesses', "1\n2\n3\n4\n5\n6\n8\n10")
            ->assertSet('engravingDepths', "0.1\n0.2\n0.3\n0.5")
            ->assertSet('millingWidth', '2000')->assertSet('millingLength', '4000')
            ->assertSee('Milling materials')->assertSee('Engraving depths, mm')
            ->set('millingMaterials.1', ['lt' => '', 'en' => ''])
            ->set('millingMaterials.5', ['lt' => ' Titanas ', 'en' => 'Titanium'])
            ->set('engravingMaterials.3', ['lt' => '', 'en' => ''])
            ->set('millingThicknesses', "1,5\n 12 \n\n")
            ->set('engravingDepths', '0,25')
            ->set('millingWidth', '1500')->set('millingLength', '3000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Saved.')
            ->assertSet('millingThicknesses', "1.5\n12");

        $values = app(Settings::class)->values();
        $this->assertSame([['lt' => 'Aliuminis', 'en' => 'Aluminium'], ['lt' => 'Nerūdijantis plienas', 'en' => 'Stainless steel'], ['lt' => 'Žalvaris', 'en' => 'Brass'],
            ['lt' => 'Plastikas', 'en' => 'Plastic'], ['lt' => 'Titanas', 'en' => 'Titanium']], $values['milling_materials']);
        $this->assertSame(['Aliuminis', 'Nerūdijantis plienas', 'Plastikas'], array_column($values['engraving_materials'], 'lt'));
        $this->assertSame([[1.5, 12], [0.25], [1500, 3000]], [$values['milling_thicknesses_mm'], $values['engraving_depths_mm'], $values['milling_max_mm']]);
        // Прежние тарифы и пометки не меняются.
        $this->assertSame(['materials', 'rate_per_gram', 'order_fee_cents', 'file_fee_cents'], app(Settings::class)->unconfirmed());
        $this->assertSame([1.5, 12], $this->pageSettings('/en/quote')['milling_thicknesses_mm']);
        $this->assertSame([1500, 3000], $this->pageSettings('/lt/kalkuliatorius')['milling_max_mm']);
    }

    public function test_invalid_milling_and_engraving_values_are_not_saved(): void
    {
        $before = app(Settings::class)->values();
        foreach ([
            ['millingMaterials.0.en', ''], ['millingMaterials.5', ['lt' => '', 'en' => 'Titanium']], ['millingMaterials.1.lt', 'Aliuminis'],
            ['engravingMaterials.0.lt', '123'], ['engravingMaterials.4', ['lt' => 'x', 'en' => str_repeat('a', 61)]],
            ['millingThicknesses', ''], ['millingThicknesses', '0'], ['millingThicknesses', '-1'], ['millingThicknesses', '1 2'], ['millingThicknesses', 'abc'],
            ['engravingDepths', '0,0'], ['engravingDepths', '0.1.2'], ['engravingDepths', '0.123'],
            ['millingWidth', '0'], ['millingWidth', ''], ['millingLength', '2.5'], ['millingLength', 'x'],
        ] as [$field, $value]) {
            $component = $this->valid()->set($field, $value)->call('save');
            $this->assertNotEmpty($component->errors()->all(), "{$field} = ".json_encode($value));
            $this->assertNull($component->get('status'), $field);
        }
        $empty = [['lt' => '', 'en' => ''], ['lt' => '', 'en' => '']];
        $this->valid()->set('millingMaterials', $empty)->call('save')->assertHasErrors('millingMaterials.0.lt');
        $this->valid()->set('engravingMaterials', $empty)->call('save')->assertHasErrors('engravingMaterials.0.lt');
        $this->assertSame($before, app(Settings::class)->values());
        $this->assertSame('Check the value.', $this->valid()->set('millingThicknesses', '0')->call('save')->errors()->first('millingThicknesses'));
    }
}
