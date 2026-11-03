<?php

namespace Tests\Feature;

use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Payments\Models\PaymentRequest;
use App\Domain\Pricing\CalculatorSettings;
use App\Domain\Pricing\EstimateService;
use App\Models\OrderCharge;
use App\Models\Setting;
use App\Models\StlFile;
use App\Models\StlPart;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use RuntimeException;
use Tests\TestCase;

/**
 * Калькулятор с параметрами у каждого файла — данные и сервер (правка 02.11, Г6): файлы STL/STEP/STP/OBJ/DXF/DWG,
 * способ и его параметры у каждой позиции, цена только у STL + печать по материалу и заполнению позиции, неоценённые
 * позиции (estimate NULL, цена строки 0) и подтверждение цены администратором для всех позиций, отказы проверки,
 * подписи по способу, Saved models и повтор только для STL, миграции схемы и настроек.
 */
class OrderPositionsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        $this->seed(PrototypeContentSeeder::class);
        Storage::fake('local');
        Mail::fake();
        config(['aiqme.orders.per_hour' => 100]);
        $this->token = (string) Str::uuid();
    }

    private function upload(string $fixture, string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(base_path('tests/fixtures/'.$fixture)));
    }

    /** @param  array<string, mixed>  $override */
    private function printed(string $name, array $override = []): array
    {
        return ['file' => $this->upload('stl/cube-10mm-binary.stl', $name), 'method' => 'printing', 'material' => 'PLA', 'infill' => '100', 'color' => 'black',
            'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'unit' => 'mm', 'scale' => '100', 'quantity' => '1', 'priority' => 'standard', ...$override];
    }

    /** @param  array<string, mixed>  $override */
    private function milled(string $fixture = 'cad/plate.dxf', string $name = 'plate.dxf', array $override = []): array
    {
        return ['file' => $this->upload($fixture, $name), 'method' => 'milling', 'material' => 'Aliuminis', 'thickness' => '6', 'quantity' => '1', 'priority' => 'standard', ...$override];
    }

    /**
     * Смешанный заказ: STL печать ABS 20 % ×2 и STL печать PETG 100 %, STEP печать, DXF фрезеровка (скорая, дата,
     * заметка), DWG гравировка.
     *
     * @return list<array<string, mixed>>
     */
    private function mixedParts(string $dxfNotes = 'Kraštai be šerpetų.'): array
    {
        return [
            $this->printed('bracket.stl', ['material' => 'ABS', 'infill' => '20', 'quantity' => '2', 'color' => 'white']),
            $this->printed('cover.stl', ['material' => 'PETG', 'surface' => 'high']),
            ['file' => UploadedFile::fake()->createWithContent('housing.step', "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;\n"), 'method' => 'printing', 'material' => 'PLA',
                'infill' => 'consult', 'color' => 'black', 'purpose' => 'practical', 'service' => 'none', 'surface' => 'standard', 'quantity' => '1', 'priority' => 'standard'],
            $this->milled('cad/plate.dxf', 'plate.dxf', ['quantity' => '3', 'priority' => 'priority', 'needed_by' => now()->addDays(2)->format('Y-m-d'), 'notes' => $dxfNotes]),
            ['file' => $this->upload('cad/plate.dwg', 'sign.DWG'), 'method' => 'engraving', 'material' => 'Mediena', 'depth' => '0.1', 'quantity' => '1',
                'priority' => 'standard', 'needed_by' => now()->addDays(2)->format('Y-m-d')],
        ];
    }

    /** @param  list<array<string, mixed>>  $parts */
    private function submit(array $parts, string $locale = 'en'): TestResponse
    {
        return $this->post($locale === 'lt' ? '/lt/kalkuliatorius' : '/en/quote', [
            'submission_token' => $this->token,
            'name' => 'Ona Petraitė',
            'email' => 'ona@example.test',
            'phone' => '+370 600 00000',
            'delivery' => 'pickup',
            'parts' => $parts,
        ], ['Accept' => 'application/json']);
    }

    /** @return list<StlPart> */
    private function positions(): array
    {
        return StlPart::with('file')->orderBy('id')->get()->all();
    }

    public function test_mixed_order_prices_only_stl_printing_by_its_own_material_and_infill(): void
    {
        $settings = app(CalculatorSettings::class);
        $settings->save(['file_fee_cents' => 25] + $settings->values());
        $this->submit($this->mixedParts())->assertCreated();

        $order = Order::with('lines.purchasable')->sole();
        [$bracket, $cover, $step, $dxf, $dwg] = $this->positions();
        // Куб 1 см³: ABS 1,04 × (0,2 + 0,8 × 0,2) × 2 = 0,7488 г → 7 центов; PETG 1,27 г → 13 центов. Остальные — без оценки.
        $this->assertSame([7, 13, null, null, null], array_map(fn (StlPart $part): ?int => $part->estimate, [$bracket, $cover, $step, $dxf, $dwg]));
        $lines = $order->lines->filter(fn (OrderLine $line): bool => $line->purchasable instanceof StlPart)->values();
        $this->assertSame([7, 13, 0, 0, 0], $lines->map(fn (OrderLine $line): int => $line->unit_price->value)->all());
        // Сумма — оценённые позиции + плата за заказ 500 + плата за каждый из 5 файлов 25.
        $this->assertSame(7 + 13 + 500 + 5 * 25, $order->total->value);
        $fee = $order->lines->first(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge);
        $this->assertSame(625, $fee->unit_price->value);
        $this->assertSame('6.45', IntegrationEvent::sole()->data['amount']);

        $this->assertSame(['method' => 'printing', 'material' => 'ABS', 'infill' => 20, 'infill_consult' => false, 'unit' => 'mm', 'scale' => 100, 'quantity' => 2,
            'color' => 'white', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'term' => 'standard', 'needed_by' => null, 'notes' => null], $bracket->parameters);
        $this->assertSame(['PETG', 100, 'high'], [$cover->parameters['material'], $cover->parameters['infill'], $cover->parameters['surface']]);
        $this->assertSame(['method' => 'printing', 'material' => 'PLA', 'infill' => 20, 'infill_consult' => true, 'quantity' => 1, 'color' => 'black',
            'purpose' => 'practical', 'service' => 'none', 'surface' => 'standard', 'term' => 'standard', 'needed_by' => null, 'notes' => null], $step->parameters);
        $this->assertSame(['method' => 'milling', 'material' => 'Aliuminis', 'material_en' => 'Aluminium', 'thickness' => 6, 'quantity' => 3, 'term' => 'priority',
            'needed_by' => now()->addDays(2)->format('Y-m-d'), 'notes' => 'Kraštai be šerpetų.'], $dxf->parameters);
        // Дата при обычной очереди не хранится.
        $this->assertSame(['method' => 'engraving', 'material' => 'Mediena', 'material_en' => 'Wood', 'depth' => 0.1, 'quantity' => 1, 'term' => 'standard',
            'needed_by' => null, 'notes' => null], $dwg->parameters);

        // Файлы: расширение исходного файла в пути, у не-STL нет разбора и измерения.
        $this->assertSame(['stl', 'stl', 'step', 'dxf', 'dwg'], array_map(fn (StlPart $part): string => $part->file->format(), $this->positions()));
        $this->assertMatchesRegularExpression('#^stl/[0-9a-f-]{36}\.dwg$#', $dwg->file->path);
        $this->assertSame('sign.DWG', $dwg->file->original_name);
        Storage::disk('local')->assertExists($dwg->file->path);
        $this->assertSame([12, null, null, null], [$bracket->file->triangles, $step->file->triangles, $dxf->file->triangles, $dwg->file->analysis]);
        $this->assertSame([null, null, null], [$step->measurement, $dxf->measurement, $dwg->measurement]);
        $this->assertEqualsWithDelta(0.7488, $bracket->measurement['mass_grams'], 1e-9);

        // Подписи строк — по способу, на языке заказа (EN) и по запросу на LT.
        $this->assertSame(['3D printing / ABS', '3D printing / PETG', '3D printing / PLA', 'Milling / Aluminium, 6 mm', 'Engraving / Wood, 0.1 mm'],
            $lines->map(fn (OrderLine $line): string => $line->option)->all());
        $this->assertSame(['3D spausdinimas / ABS', 'Frezavimas / Aliuminis, 6 mm', 'Graviravimas / Mediena, 0,1 mm'],
            [$bracket->label('lt'), $dxf->label('lt'), $dwg->label('lt')]);
    }

    public function test_admin_prices_every_position_before_confirmation_and_payment(): void
    {
        $this->submit($this->mixedParts())->assertCreated();
        $order = Order::sole();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $ids = $order->lines()->orderBy('id')->pluck('id')->all();
        $flow = app(OrderWorkflow::class);

        // Неоценённая позиция с ценой 0 — подтверждение не проходит; оплатить до подтверждения нельзя.
        foreach ([[3000, 3000, 0, 4000, 2500, 500], [3000, 3000, 1500, 0, 2500, 500]] as $prices) {
            try {
                $flow->confirmPrice($admin, $order, array_combine($ids, $prices), null);
                $this->fail('цена 0 у позиции принята');
            } catch (ValidationException $exception) {
                $this->assertSame('workflow', array_key_first($exception->errors()));
            }
        }
        $this->expectValidation(fn () => $flow->requestPayment($admin, $order, 5000));

        $flow->confirmPrice($admin, $order, array_combine($ids, [3000, 3000, 1500, 4000, 2500, 500]), null);
        $this->assertSame(14500, $order->fresh()->total->value);
        $this->assertSame([3000, 3000, 1500, 4000, 2500, 500], $order->lines()->orderBy('id')->get()->map(fn (OrderLine $line): int => $line->unit_price->value)->all());
        $request = $flow->requestPayment($admin, $order, 5000);
        $this->assertSame([PaymentRequest::PENDING, 5000], [$request->state, $request->amount]);
    }

    private function expectValidation(callable $action): void
    {
        try {
            $action();
            $this->fail('принято без ошибки проверки');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_formats_methods_and_position_parameters_are_checked_without_writes(): void
    {
        $exe = "MZ\x90\0\3\0\0\0\4\0\0\0\xFF\xFF\0\0".str_repeat("\0", 48);
        $yesterday = now()->subDay()->format('Y-m-d');
        $cases = [
            'printing DXF' => [[$this->milled(override: ['method' => 'printing'])], 'parts.0.method', 'Choose a value from the list.'],
            'printing DWG' => [[$this->milled('cad/plate.dwg', 'plate.dwg', ['method' => 'printing'])], 'parts.0.method', 'Choose a value from the list.'],
            'unknown method' => [[$this->milled(override: ['method' => 'cutting'])], 'parts.0.method', 'Choose a value from the list.'],
            'no method' => [[array_diff_key($this->milled(), ['method' => 1])], 'parts.0.method', 'Fill in this field.'],
            '.exe' => [[['file' => UploadedFile::fake()->createWithContent('setup.exe', $exe)] + $this->milled()], 'parts.0.file', 'setup.exe: unsupported format — contact a specialist.'],
            'double extension' => [[['file' => UploadedFile::fake()->createWithContent('plate.dxf.exe', $exe)] + $this->milled()], 'parts.0.file', 'plate.dxf.exe: unsupported format — contact a specialist.'],
            'exe as DXF' => [[['file' => UploadedFile::fake()->createWithContent('plate.dxf', $exe)] + $this->milled()], 'parts.1.file', 'plate.dxf: unsupported format — contact a specialist.'],
            'exe as DWG' => [[['file' => UploadedFile::fake()->createWithContent('plate.dwg', $exe)] + $this->milled()], 'parts.0.file', 'plate.dwg: unsupported format — contact a specialist.'],
            'exe as STEP' => [[['file' => UploadedFile::fake()->createWithContent('part.step', $exe)] + $this->printed('x.stl', ['unit' => null, 'scale' => null])], 'parts.0.file', 'part.step: unsupported format — contact a specialist.'],
            'exe as OBJ' => [[['file' => UploadedFile::fake()->createWithContent('part.obj', $exe)] + $this->printed('x.stl')], 'parts.0.file', 'part.obj: unsupported format — contact a specialist.'],
            'exe as STL' => [[['file' => UploadedFile::fake()->createWithContent('part.stl', $exe)] + $this->printed('x.stl')], 'parts.0.file', 'part.stl: Could not read geometry'],
            'milling material' => [[$this->milled(override: ['material' => 'Titanas'])], 'parts.0.material', 'Choose a value from the list.'],
            'engraving list for milling' => [[$this->milled(override: ['material' => 'Mediena'])], 'parts.0.material', 'Choose a value from the list.'],
            'thickness' => [[$this->milled(override: ['thickness' => '7'])], 'parts.0.thickness', 'Choose a value from the list.'],
            'depth' => [[$this->milled(override: ['method' => 'engraving', 'material' => 'Mediena', 'depth' => '0.4'])], 'parts.0.depth', 'Choose a value from the list.'],
            'no depth' => [[$this->milled(override: ['method' => 'engraving', 'material' => 'Mediena'])], 'parts.0.depth', 'Fill in this field.'],
            'printing material' => [[$this->printed('a.stl', ['material' => 'Aliuminis'])], 'parts.0.material', 'Choose a value from the list.'],
            'STL without unit' => [[array_diff_key($this->printed('a.stl'), ['unit' => 1])], 'parts.0.unit', 'Fill in this field.'],
            'priority' => [[$this->milled(override: ['priority' => 'express'])], 'parts.0.priority', 'Choose a value from the list.'],
            'needed by yesterday' => [[$this->milled(override: ['priority' => 'priority', 'needed_by' => $yesterday])], 'parts.0.needed_by', 'Choose a value from the list.'],
            'needed by today' => [[$this->milled(override: ['priority' => 'priority', 'needed_by' => now()->format('Y-m-d')])], 'parts.0.needed_by', 'Choose a value from the list.'],
            'notes' => [[$this->milled(override: ['notes' => str_repeat('a', 2001)])], 'parts.0.notes', 'Too long: up to 2000 characters.'],
            'quantity' => [[$this->milled(override: ['quantity' => '0'])], 'parts.0.quantity', 'Quantity: from 1 to 100.'],
        ];
        // «exe as DXF»: вторая позиция — проверка начала файла идёт на сервере после проверки полей.
        array_unshift($cases['exe as DXF'][0], $this->milled());
        foreach ($cases as $label => [$parts, $field, $message]) {
            $response = $this->submit(array_map(fn (array $part): array => array_filter($part, fn (mixed $value): bool => $value !== null), $parts));
            $this->assertSame([422, $message], [$response->status(), $response->json('errors')[$field][0] ?? null], $label.': '.json_encode($response->json('errors')));
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([0, 0, 0], [Order::count(), StlFile::count(), IntegrationEvent::count()]);
    }

    public function test_previous_shared_format_still_works_and_rejects_dxf(): void
    {
        $legacy = fn (array $parts): TestResponse => $this->post('/en/quote', [
            'submission_token' => $this->token, 'name' => 'Ona', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'delivery' => 'pickup',
            'material' => 'PETG', 'infill' => '100', 'color' => 'blue', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'term' => 'priority',
            'notes' => 'Bendra pastaba.', 'parts' => $parts,
        ], ['Accept' => 'application/json']);

        $legacy([['file' => $this->upload('cad/plate.dxf', 'plate.dxf'), 'quantity' => '1']])
            ->assertUnprocessable()->assertJsonPath('errors', ['parts.0.file' => ['Choose a value from the list.']]);
        $this->assertSame(0, Order::count());

        // Общие поля копируются в каждую позицию; notes — у заказа, как раньше.
        $legacy([
            ['file' => $this->upload('stl/cube-10mm-binary.stl', 'a.stl'), 'unit' => 'mm', 'scale' => '100', 'quantity' => '1'],
            ['file' => $this->upload('stl/cube-10mm-ascii.stl', 'b.stl'), 'unit' => 'cm', 'scale' => '10', 'quantity' => '2'],
        ])->assertCreated();
        $this->assertSame('Bendra pastaba.', OrderMeta::sole()->notes);
        foreach ($this->positions() as $part) {
            $this->assertSame(['printing', 'PETG', 'blue', 'priority', null, null],
                [$part->parameters['method'], $part->parameters['material'], $part->parameters['color'], $part->parameters['term'], $part->parameters['needed_by'], $part->parameters['notes']]);
        }
        $this->assertSame([13, 25], array_map(fn (StlPart $part): int => $part->estimate, $this->positions()));
    }

    public function test_digest_covers_position_parameters(): void
    {
        $this->submit($this->mixedParts())->assertCreated();
        $this->submit($this->mixedParts())->assertCreated();
        $this->assertSame(1, Order::count());

        $this->submit($this->mixedParts('Kita pastaba.'))->assertUnprocessable()->assertJsonPath('errors.submission_token.0', 'This order has already been submitted with other details. Reload the page and try again.');
        $changed = $this->mixedParts();
        $changed[4]['depth'] = '0.2';
        $this->submit($changed)->assertUnprocessable();
        $this->assertSame([1, 5], [Order::count(), StlFile::count()]);
    }

    public function test_positions_before_the_change_read_as_fdm_printing(): void
    {
        $this->submit([$this->printed('old.stl', ['material' => 'ABS'])])->assertCreated();
        $part = StlPart::sole();
        // Позиция до 02.11: без method, needed_by и notes.
        $part->update(['parameters' => array_diff_key($part->parameters, ['method' => 1, 'needed_by' => 1, 'notes' => 1])]);
        $part->refresh();

        $this->assertSame('printing', $part->method());
        $this->assertSame(['3D spausdinimas / ABS', '3D printing / ABS'], [$part->label('lt'), $part->label('en')]);
        app()->setLocale('lt');
        $this->assertSame('3D spausdinimas / ABS', $part->getOption());
        $this->assertStringNotContainsString('FDM', $part->getOption());
    }

    public function test_saved_models_and_reorder_include_only_stl(): void
    {
        $client = User::factory()->create();
        $this->actingAs($client)->submit($this->mixedParts())->assertCreated();
        $stl = StlFile::where('path', 'like', '%.stl')->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $stl);

        $this->get('/en/account/models')->assertOk()->assertSee(['bracket.stl', 'cover.stl'])->assertDontSee('plate.dxf')->assertDontSee('sign.DWG')->assertDontSee('housing.step');
        $this->get('/en/account/orders/'.OrderMeta::sole()->number)->assertOk()
            ->assertSee(route('en.calculator', ['models' => implode(',', $stl)]), false);

        // Заказ без STL повторить нельзя.
        $this->token = (string) Str::uuid();
        $this->submit([$this->milled()])->assertCreated();
        $this->assertStringNotContainsString('data-reorder', $this->get('/en/account/orders/'.OrderMeta::orderByDesc('number')->first()->number)->assertOk()->getContent());
    }

    public function test_estimate_service_uses_each_position_and_skips_unpriced(): void
    {
        $result = app(EstimateService::class)->estimate([
            ['volume_cm3' => 1.0, 'quantity' => 1, 'material' => 'ABS', 'infill' => 20],
            null,
            ['volume_cm3' => 8.0, 'quantity' => 2, 'material' => 'PETG', 'infill' => 100],
        ]);
        $this->assertSame([4, null, 203], array_map(fn (?array $line): ?int => $line['cents'] ?? null, $result['lines']));
        $this->assertSame(4 + 203 + 500, $result['total_cents']);
        $this->assertSame(500, app(EstimateService::class)->estimate([null])['total_cents']);
        $this->expectException(\InvalidArgumentException::class);
        app(EstimateService::class)->estimate([['volume_cm3' => 1.0, 'quantity' => 1, 'material' => 'Aliuminis', 'infill' => 20]]);
    }

    public function test_schema_migration_makes_analysis_and_estimate_nullable_and_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_11_03_000000_allow_order_files_of_any_format.php');
        $nullable = fn (): array => [
            ...collect(Schema::getColumns('stl_files'))->whereIn('name', ['triangles', 'analysis'])->pluck('nullable', 'name')->sortKeys()->all(),
            ...collect(Schema::getColumns('stl_parts'))->whereIn('name', ['estimate', 'measurement'])->pluck('nullable', 'name')->sortKeys()->all(),
        ];
        $this->assertSame(['analysis' => true, 'triangles' => true, 'estimate' => true, 'measurement' => true], $nullable());

        $migration->down();
        $this->assertSame(['analysis' => false, 'triangles' => false, 'estimate' => false, 'measurement' => false], $nullable());
        $migration->up();
        $this->assertSame(['analysis' => true, 'triangles' => true, 'estimate' => true, 'measurement' => true], $nullable());

        // С файлами без разбора откат невозможен — схема не меняется.
        $this->submit([$this->milled()])->assertCreated();
        try {
            $migration->down();
            $this->fail('откат с позициями без оценки');
        } catch (RuntimeException) {
            $this->assertTrue($nullable()['estimate']);
        }
    }

    public function test_settings_migration_adds_only_missing_keys_and_rolls_back_unchanged_ones(): void
    {
        $migration = require database_path('migrations/2026_11_03_100000_add_milling_engraving_calculator_settings.php');
        $settings = app(CalculatorSettings::class);
        $this->assertSame($migration::VALUES, array_intersect_key($settings->values(), $migration::VALUES));
        $this->assertSame([2000, 4000], $settings->values()['milling_max_mm']);

        // Запись без ключей (как до правки): up добавляет, повтор ничего не меняет; down убирает.
        Setting::query()->delete();
        (require database_path('migrations/2026_10_21_100001_insert_calculator_settings.php'))->up();
        $before = $settings->values();
        $migration->up();
        $this->assertSame([...$before, ...$migration::VALUES], $settings->values());
        $after = Setting::sole()->getRawOriginal('value');
        $migration->up();
        $this->assertSame($after, Setting::sole()->getRawOriginal('value'));
        $migration->down();
        $this->assertSame($before, $settings->values());

        // Значение администратора: up его не трогает, down не удаляет.
        $migration->up();
        $settings->save(['milling_thicknesses_mm' => [6, 12]] + $settings->values());
        $migration->up();
        $this->assertSame([6, 12], $settings->values()['milling_thicknesses_mm']);
        $migration->down();
        $this->assertSame([...$before, 'milling_thicknesses_mm' => [6, 12]], $settings->values());

        // Записи нет — без ошибки и без вставки.
        Setting::query()->delete();
        $migration->up();
        $migration->down();
        $this->assertSame(0, DB::table('settings')->count());
    }
}
