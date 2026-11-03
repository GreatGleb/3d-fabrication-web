<?php

namespace Tests\Feature;

use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderWorkflow;
use App\Livewire\Admin\Orders\Card;
use App\Mail\FormSubmitted;
use App\Models\StlFile;
use App\Models\StlPart;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Tests\TestCase;

/**
 * Отображение позиций с параметрами у каждого файла (правка 02.11, Г7): карточка заказа в админке (способ, параметры,
 * «Skubu» и дата, заметка позиции, пустая цена у неоценённой позиции, лист Bambu только для печати из STL, скачивание
 * не-STL), страница заказа в кабинете, страница подтверждения, письмо AIQME и описание строки счёта; позиции до правки
 * (без method) выводятся как раньше.
 */
class OrderPositionsDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private string $neededBy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        $this->seed(PrototypeContentSeeder::class);
        Storage::fake('local');
        Mail::fake();
        config(['aiqme.orders.per_hour' => 100]);
        Livewire::flushState();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        $this->client = User::factory()->create();
        $this->neededBy = now()->addDays(2)->format('Y-m-d');
    }

    private function upload(string $fixture, string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(base_path('tests/fixtures/'.$fixture)));
    }

    /** @return array<string, mixed> */
    private function printed(string $name): array
    {
        return ['file' => $this->upload('stl/cube-10mm-binary.stl', $name), 'method' => 'printing', 'material' => 'ABS', 'infill' => '20', 'color' => 'black',
            'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'unit' => 'mm', 'scale' => '100', 'quantity' => '2', 'priority' => 'standard'];
    }

    /**
     * Заказ клиента: STL печать, STEP печать (без оценки), DXF фрезеровка (скорая, дата, заметка), DWG гравировка;
     * $mixed = false — только STL.
     */
    private function submit(bool $mixed = true, string $locale = 'en'): Order
    {
        $parts = [$this->printed('bracket.stl')];
        if ($mixed) {
            $parts[] = ['file' => UploadedFile::fake()->createWithContent('housing.step', "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;\n"), 'method' => 'printing',
                'material' => 'PLA', 'infill' => '100', 'color' => 'black', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'quantity' => '1', 'priority' => 'standard'];
            $parts[] = ['file' => $this->upload('cad/plate.dxf', 'plate.dxf'), 'method' => 'milling', 'material' => 'Aliuminis', 'thickness' => '6', 'quantity' => '3',
                'priority' => 'priority', 'needed_by' => $this->neededBy, 'notes' => "Kraštai be šerpetų.\n<b>Matinis</b>"];
            $parts[] = ['file' => $this->upload('cad/plate.dwg', 'sign.dwg'), 'method' => 'engraving', 'material' => 'Mediena', 'depth' => '0.1', 'quantity' => '1', 'priority' => 'standard'];
        }
        $this->actingAs($this->client)->post($locale === 'lt' ? '/lt/kalkuliatorius' : '/en/quote', [
            'submission_token' => (string) Str::uuid(), 'name' => 'Ona Petraitė', 'email' => 'ona@example.test', 'phone' => '+370 600 00000',
            'delivery' => 'pickup', 'parts' => $parts,
        ], ['Accept' => 'application/json'])->assertCreated();

        return Order::latest('id')->firstOrFail();
    }

    private function card(Order $order): Testable
    {
        return Livewire::actingAs($this->admin)->test(Card::class, ['orderId' => $order->id]);
    }

    /** @return list<int> id строк StlPart по порядку */
    private function partLines(Order $order): array
    {
        return $order->lines()->orderBy('id')->get()->filter(fn (OrderLine $line): bool => $line->purchasable instanceof StlPart)->modelKeys();
    }

    /** Текст ячеек «Parameters» таблицы файлов без меток Livewire и пробелов вокруг тегов. */
    private static function cells(string $html): array
    {
        preg_match_all('#<td data-part-parameters>(.*?)</td>#s', $html, $matches);

        return array_map(fn (string $cell): string => trim(preg_replace(['/<!--\[if (END)?BLOCK\]><!\[endif\]-->/', '/\s+/', '/\s*(<[^>]+>)\s*/'], ['', ' ', '$1'], $cell)), $matches[1]);
    }

    public function test_admin_card_shows_method_parameters_urgency_notes_and_specialist_price(): void
    {
        $order = $this->submit();
        app()->setLocale('en');
        $card = $this->card($order);
        $html = $card->html();
        [$bracket, $step, $dxf, $dwg] = self::cells($html);

        $this->assertStringStartsWith('Quantity: 2<br><b>3D printing</b><br>ABS / Infill 20%', $bracket);
        $this->assertStringContainsString('Units: mm · Scale, % 100', $bracket);
        $this->assertStringNotContainsString('data-urgent', $bracket);
        $this->assertStringStartsWith('Quantity: 1<br><b>3D printing</b><br>PLA / Infill 100%', $step);
        $this->assertStringNotContainsString('Units', $step);
        $this->assertStringStartsWith('Quantity: 3<br><b>Milling</b><br>Aluminium / Thickness: 6 mm / Production lead time: Urgent', $dxf);
        $this->assertStringContainsString('<span class="tag blue" data-urgent>Urgent</span>Needed by: '.$this->neededBy, $dxf);
        $this->assertStringContainsString('Additional information:<span style="white-space:pre-line">Kraštai be šerpetų. &lt;b&gt;Matinis&lt;/b&gt;</span>', $dxf);
        $this->assertStringStartsWith('Quantity: 1<br><b>Engraving</b><br>Wood / Engraving depth: 0.1 mm / Production lead time: Standard', $dwg);
        $this->assertStringNotContainsString('Needed by', $dwg);
        $this->assertStringNotContainsString('<b>Matinis</b>', $html);

        // Оценка: у STL — сумма, у остальных — «Price to be assessed by a specialist», размеров нет.
        $this->assertSame(3, substr_count($html, '<td>Price to be assessed by a specialist</td>'));
        $this->assertSame(3, preg_match_all('#<td>\s*(<!--\[if BLOCK\]><!\[endif\]-->)?\s*—\s*(<!--\[if ENDBLOCK\]><!\[endif\]-->)?\s*</td>#', $html));

        // Цена: у оценённой позиции — оценка, у неоценённых — пустое поле с подсказкой.
        [$a, $b, $c, $d] = $this->partLines($order);
        $card->assertSet("prices.$a", number_format(StlPart::orderBy('id')->first()->estimate / 100, 2, '.', ''))
            ->assertSet("prices.$b", '')->assertSet("prices.$c", '')->assertSet("prices.$d", '');
        $this->assertSame(3, substr_count($html, 'placeholder="Price to be assessed by a specialist"'));

        app()->setLocale('lt');
        [, , $dxf, $dwg] = self::cells($this->card($order)->html());
        $this->assertStringStartsWith('Kiekis: 3<br><b>Frezavimas</b><br>Aliuminis / Storis: 6 mm / Gamybos terminas: Skubos tvarka', $dxf);
        $this->assertStringContainsString('data-urgent>Skubu</span>Reikalinga iki: '.$this->neededBy, $dxf);
        $this->assertStringStartsWith('Kiekis: 1<br><b>Graviravimas</b><br>Mediena / Graviravimo gylis: 0,1 mm', $dwg);
        $this->assertStringContainsString('Kainą įvertins specialistas', $this->card($order)->html());
    }

    public function test_admin_prices_unassessed_positions_in_the_card(): void
    {
        $order = $this->submit();
        [$a, $b, $c, $d] = $this->partLines($order);
        $fee = $order->lines()->orderBy('id')->get()->last()->id;

        // Пустые цены неоценённых позиций не принимаются.
        $this->card($order)->call('confirmPrice')->assertHasErrors(["prices.$b", "prices.$c", "prices.$d"])->assertHasNoErrors("prices.$a");
        $this->assertNull(ProductionState::where('order_id', $order->id)->value('confirmed_at'));

        $this->card($order)->set("prices.$b", '15')->set("prices.$c", '40.5')->set("prices.$d", '25')->call('confirmPrice')->assertHasNoErrors()
            ->assertSet("prices.$c", '40.50')->assertSet("prices.$fee", '5.00');
        $this->assertSame([1500, 4050, 2500], $order->lines()->whereIn('id', [$b, $c, $d])->orderBy('id')->get()->map(fn (OrderLine $line): int => $line->unit_price->value)->all());
    }

    public function test_bambu_sheet_lists_only_stl_printing_and_any_file_downloads(): void
    {
        $order = $this->submit();
        app()->setLocale('en');
        $html = $this->card($order)->call('toggleJob')->html();
        $job = substr($html, strpos($html, 'id="bambu-job"'));
        $job = substr($job, 0, strpos($job, '</table>'));
        $this->assertStringContainsString('bracket.stl', $job);
        foreach (['housing.step', 'plate.dxf', 'sign.dwg', 'Aluminium'] as $absent) {
            $this->assertStringNotContainsString($absent, $job, $absent);
        }

        // Скачивание любых файлов — та же защищённая выдача.
        foreach (StlFile::where('order_id', $order->id)->orderBy('id')->get() as $file) {
            $this->assertStringContainsString('href="'.route('stl.download', $file).'"', $html);
        }
        $dxf = StlFile::where('original_name', 'plate.dxf')->sole();
        $response = $this->actingAs($this->admin)->get(route('stl.download', $dxf))->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('Content-Disposition', 'attachment; filename=plate.dxf');
        $this->assertSame(file_get_contents(base_path('tests/fixtures/cad/plate.dxf')), $response->streamedContent());

        // Заказ без печати из STL — без кнопки листа Bambu.
        StlPart::query()->get()->each(fn (StlPart $part) => $part->file->format() === 'stl' ? $part->update(['parameters' => ['method' => 'milling', 'material' => 'Aliuminis', 'material_en' => 'Aluminium', 'thickness' => 6, 'quantity' => 1, 'term' => 'standard', 'needed_by' => null, 'notes' => null]]) : null);
        $this->card($order)->assertDontSeeHtml('aria-controls="bambu-job"');
    }

    /**
     * Позиции до правки 02.11 (фикстура прежнего формата: без method, needed_by, notes): ячейки карточки — те же, что
     * выводил шаблон до правки (сверено с прежним шаблоном, docs/progress.md), без способа, «Skubu» и пометок.
     */
    public function test_order_in_the_previous_format_looks_as_before(): void
    {
        $order = $this->submit(mixed: false);
        $part = StlPart::sole();
        $part->update(['parameters' => ['material' => 'PLA', 'infill' => 100, 'infill_consult' => false, 'unit' => 'mm', 'scale' => 100, 'quantity' => 2,
            'color' => 'blue', 'purpose' => 'practical', 'service' => 'inserts', 'surface' => 'high', 'term' => 'priority']]);
        app()->setLocale('en');
        $html = $this->card($order)->html();

        $this->assertSame(['Quantity: 2<br>PLA / Infill 100% / Colour: Blue / Part purpose: Practical use / Additional services (optional): Threaded insert installation / Surface quality: High / Production lead time: Urgent<br>Units: mm · Scale, % 100'], self::cells($html));
        $this->assertStringNotContainsString('data-urgent', $html);
        $this->assertStringNotContainsString('specialist', $html);
        $this->assertStringContainsString('aria-controls="bambu-job"', $html);

        $page = $this->actingAs($this->client)->get('/en/account/orders/'.OrderMeta::sole()->number)->assertOk();
        $page->assertDontSeeHtml('data-part-label')->assertSee('bracket.stl');
    }

    public function test_account_page_shows_method_line_and_specialist_mark_until_price_is_confirmed(): void
    {
        $order = $this->submit();
        $number = OrderMeta::sole()->number;
        $page = $this->actingAs($this->client)->get('/en/account/orders/'.$number)->assertOk()->getContent();
        preg_match_all('#<p data-part-label><small>(.*?)</small></p>#', $page, $labels);
        $this->assertSame(['3D printing / ABS', '3D printing / PLA · Price to be assessed by a specialist', 'Milling / Aluminium, 6 mm · Price to be assessed by a specialist',
            'Engraving / Wood, 0.1 mm · Price to be assessed by a specialist'], $labels[1]);
        // Сумма — по известным позициям и сборам.
        $this->assertStringContainsString('data-total>'.e(Number::currency($order->total->value / 100, in: 'EUR', locale: 'en')).'</b>', $page);

        $this->actingAs($this->client)->get('/lt/paskyra/orders/'.$number)->assertOk()->assertSee('Frezavimas / Aliuminis, 6 mm · Kainą įvertins specialistas');

        $ids = $order->lines()->orderBy('id')->pluck('id')->all();
        app(OrderWorkflow::class)->confirmPrice($this->admin, $order, array_fill_keys($ids, 1000), null);
        $this->actingAs($this->client)->get('/en/account/orders/'.$number)->assertOk()
            ->assertSee('Milling / Aluminium, 6 mm')->assertDontSee('Price to be assessed by a specialist');
    }

    public function test_confirmation_page_lists_positions_with_known_total_and_specialist_marks(): void
    {
        $order = $this->submit();
        $page = $this->actingAs($this->client)->get('/en/quote/submitted')->assertOk()->getContent();
        preg_match_all('#<div class="summary-row gap" data-part><span>(.*?)</span><b>(.*?)</b></div>#', $page, $rows, PREG_SET_ORDER);
        $estimate = StlPart::orderBy('id')->first()->estimate;
        $this->assertSame([
            ['bracket.stl × 2<br><small>3D printing / ABS</small>', e(Number::currency($estimate / 100, in: 'EUR', locale: 'en'))],
            ['housing.step × 1<br><small>3D printing / PLA</small>', 'Price to be assessed by a specialist'],
            ['plate.dxf × 3<br><small>Milling / Aluminium, 6 mm</small>', 'Price to be assessed by a specialist'],
            ['sign.dwg × 1<br><small>Engraving / Wood, 0.1 mm</small>', 'Price to be assessed by a specialist'],
        ], array_map(fn (array $row): array => [$row[1], $row[2]], $rows));
        $this->assertStringContainsString('data-cents="'.$order->total->value.'"', $page);
        $this->assertSame($estimate + 500, $order->total->value);
        $this->assertStringContainsString('+ parts assessed by a specialist', $page);

        // Только STL — без пометки.
        $this->submit(mixed: false, locale: 'lt');
        $page = $this->actingAs($this->client)->get('/lt/kalkuliatorius/pateikta')->assertOk()->getContent();
        $this->assertStringContainsString('<small>3D spausdinimas / ABS</small>', $page);
        $this->assertStringNotContainsString('specialisto vertinamos', $page);
    }

    public function test_mail_names_the_method_of_every_file_and_marks_specialist_positions(): void
    {
        $this->submit();
        $this->assertSame(['bracket.stl — 3D spausdinimas / ABS', 'housing.step — 3D spausdinimas / PLA', 'plate.dxf — Frezavimas / Aliuminis, 6 mm', 'sign.dwg — Graviravimas / Mediena, 0,1 mm'],
            IntegrationEvent::sole()->data['files']);
        Mail::assertSent(FormSubmitted::class, function (FormSubmitted $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'bracket.stl — 3D spausdinimas / ABS, housing.step — 3D spausdinimas / PLA, plate.dxf — Frezavimas / Aliuminis, 6 mm, sign.dwg — Graviravimas / Mediena, 0,1 mm')
                && str_contains($html, IntegrationEvent::sole()->data['amount'].' EUR + specialisto vertinamos detalės');
        });

        // Событие до правки (без files) — письмо со списком имён, как раньше.
        $event = IntegrationEvent::sole();
        $event->update(['data' => array_diff_key($event->data, ['files' => 1, 'specialist' => 1])]);
        $html = (new FormSubmitted($event->fresh()))->render();
        $this->assertStringContainsString('bracket.stl, housing.step, plate.dxf, sign.dwg', $html);
        $this->assertStringNotContainsString('specialisto', $html);
    }

    public function test_invoice_line_description_is_file_name_and_method(): void
    {
        $order = $this->submit();
        $lines = fn (string $locale) => (fn () => $this->lines($order, $locale, 0))->call(app(InvoiceService::class))->pluck('description')->all();
        $this->assertSame(['bracket.stl — 3D spausdinimas, ABS', 'housing.step — 3D spausdinimas, PLA', 'plate.dxf — Frezavimas, Aliuminis, 6 mm', 'sign.dwg — Graviravimas, Mediena, 0,1 mm', 'Užsakymo mokestis'], $lines('lt'));
        $this->assertSame('plate.dxf — Milling, Aluminium, 6 mm', $lines('en')[2]);

        // Позиция до правки (без method) — только имя файла.
        StlPart::orderBy('id')->first()->update(['parameters' => array_diff_key(StlPart::orderBy('id')->first()->parameters, ['method' => 1])]);
        $this->assertSame('bracket.stl', $lines('lt')[0]);
    }
}
