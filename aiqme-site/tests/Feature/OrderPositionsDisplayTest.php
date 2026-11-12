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
use Tests\Support\Html;
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
        config(['aiqme.orders.limit' => 100]);
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
            // Правка 05.11, H-05: материал фрезеровки из нового списка, толщина — дробное число (в LT показ с запятой).
            $parts[] = ['file' => $this->upload('cad/plate.dxf', 'plate.dxf'), 'method' => 'milling', 'material' => 'Spalvotieji metalai', 'thickness' => '6.5', 'quantity' => '3',
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
        $this->assertStringStartsWith('Quantity: 3<br><b>Milling</b><br>Non-ferrous metals / Thickness: 6.5 mm / Production lead time: Urgent', $dxf);
        $this->assertStringContainsString('<span class="tag blue" data-urgent>Urgent</span>Needed by: '.now()->addDays(2)->format('d.m.Y'), $dxf); // правка 11.11, J-04: даты dd.mm.yyyy
        $this->assertStringContainsString('Additional information:<span style="white-space:pre-line">Kraštai be šerpetų. &lt;b&gt;Matinis&lt;/b&gt;</span>', $dxf);
        $this->assertStringStartsWith('Quantity: 1<br><b>Engraving</b><br>Wood / Engraving depth: 0.1 mm / Production lead time: Standard', $dwg);
        $this->assertStringNotContainsString('Needed by', $dwg);
        $this->assertStringNotContainsString('<b>Matinis</b>', $html);

        // Оценка: у STL — сумма, у остальных — «Price to be assessed by a specialist», размеров нет. Правка 05.11, H-04: покупатель
        // сумм не видит, а карточка админки показывает оценку по-прежнему.
        $this->assertStringContainsString('<td>'.e(Number::currency(StlPart::orderBy('id')->first()->estimate / 100, in: 'EUR', locale: 'en')).'</td>', $html);
        $this->assertSame(3, substr_count($html, '<td>Price to be assessed by a specialist</td>'));
        $this->assertSame(3, preg_match_all('#<td>\s*(<!--\[if BLOCK\]><!\[endif\]-->)?\s*—\s*(<!--\[if ENDBLOCK\]><!\[endif\]-->)?\s*</td>#', $html));

        // Цена: у оценённой позиции — оценка, у неоценённых — пустое поле с подсказкой.
        [$a, $b, $c, $d] = $this->partLines($order);
        $card->assertSet("prices.$a", number_format(StlPart::orderBy('id')->first()->estimate / 100, 2, '.', ''))
            ->assertSet("prices.$b", '')->assertSet("prices.$c", '')->assertSet("prices.$d", '');
        $this->assertSame(3, substr_count($html, 'placeholder="Price to be assessed by a specialist"'));

        app()->setLocale('lt');
        [, , $dxf, $dwg] = self::cells($this->card($order)->html());
        $this->assertStringStartsWith('Kiekis: 3<br><b>Frezavimas</b><br>Spalvotieji metalai / Storis: 6,5 mm / Gamybos terminas: Skubos tvarka', $dxf);
        $this->assertStringContainsString('data-urgent>Skubu</span>Reikalinga iki: '.now()->addDays(2)->format('d.m.Y'), $dxf);
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
        foreach (['housing.step', 'plate.dxf', 'sign.dwg', 'Non-ferrous metals'] as $absent) {
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

    /**
     * Правка 05.11, H-04: до подтверждения цены — строка способа без пометки «Price to be assessed by a specialist» (пометка
     * подразумевала бы суммы у остальных), суммы нет — заметка; после подтверждения — сумма, как прежде.
     */
    public function test_account_page_shows_method_line_without_sums_until_price_is_confirmed(): void
    {
        $order = $this->submit();
        $number = OrderMeta::sole()->number;
        $page = $this->actingAs($this->client)->get('/en/account/orders/'.$number)->assertOk()->getContent();
        preg_match_all('#<p data-part-label><small>(.*?)</small></p>#', $page, $labels);
        $this->assertSame(['3D printing / ABS', '3D printing / PLA', 'Milling / Non-ferrous metals, 6.5 mm', 'Engraving / Wood, 0.1 mm'], $labels[1]);
        $this->assertStringNotContainsString('data-total', $page);
        $this->assertStringNotContainsString('€', Html::text(Html::xpath($page)->query('//main')->item(0)));
        $this->assertStringContainsString('We will confirm the final price and production time after reviewing your request.', $page);

        $this->actingAs($this->client)->get('/lt/paskyra/orders/'.$number)->assertOk()->assertSee('Frezavimas / Spalvotieji metalai, 6,5 mm')
            ->assertDontSee('Kainą įvertins specialistas')->assertSee('Galutinę kainą ir gamybos terminą patvirtinsime peržiūrėję jūsų užklausą.');

        $ids = $order->lines()->orderBy('id')->pluck('id')->all();
        app(OrderWorkflow::class)->confirmPrice($this->admin, $order, array_fill_keys($ids, 1000), null);
        $page = $this->actingAs($this->client)->get('/en/account/orders/'.$number)->assertOk()
            ->assertSee('Milling / Non-ferrous metals, 6.5 mm')->assertDontSee('Price to be assessed by a specialist');
        $this->assertSame(e(Number::currency($order->fresh()->total->value / 100, in: 'EUR', locale: 'en')),
            trim(Html::xpath($page->getContent())->query('//*[@data-total]')->item(0)->textContent));
    }

    /**
     * Правка 05.11, H-04: страница после отправки — главный текст README, номер заказа и ссылка гостя или кабинета; ни сумм, ни
     * позиций с оценкой (прежде — суммы позиций, итог и «+ parts assessed by a specialist»).
     */
    public function test_confirmation_page_shows_request_received_number_and_no_sums(): void
    {
        $this->submit();
        $number = OrderMeta::sole()->number;
        $page = Html::xpath($this->actingAs($this->client)->get('/en/quote/submitted')->assertOk()->getContent());
        $this->assertSame('Your request has been received. We will contact you shortly.', Html::text($page->query('//div[contains(@class, "notice success")]')->item(0)));
        $this->assertSame("Order No. {$number}", Html::text($page->query('//*[@data-number]')->item(0)));
        $this->assertSame(1, $page->query('//main//a[@href="'.route('en.account').'"]')->length, 'переход в кабинет');
        $main = Html::text($page->query('//main')->item(0));
        foreach (['€', 'EUR', 'Estimated total', 'assessed by a specialist', 'bracket.stl'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $main, $phrase);
        }

        $this->submit(mixed: false, locale: 'lt');
        $page = Html::xpath($this->actingAs($this->client)->get('/lt/kalkuliatorius/pateikta')->assertOk()->getContent());
        $this->assertSame('Jūsų užklausa gauta. Susisieksime su jumis artimiausiu metu.', Html::text($page->query('//div[contains(@class, "notice success")]')->item(0)));
        $main = Html::text($page->query('//main')->item(0));
        foreach (['€', 'Preliminari suma', 'specialisto vertinamos', 'Kainą įvertins'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $main, $phrase);
        }
    }

    public function test_mail_names_the_method_of_every_file_and_marks_specialist_positions(): void
    {
        $this->submit();
        $this->assertSame(['bracket.stl — 3D spausdinimas / ABS', 'housing.step — 3D spausdinimas / PLA', 'plate.dxf — Frezavimas / Spalvotieji metalai, 6,5 mm', 'sign.dwg — Graviravimas / Mediena, 0,1 mm'],
            IntegrationEvent::sole()->data['files']);
        Mail::assertSent(FormSubmitted::class, function (FormSubmitted $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'bracket.stl — 3D spausdinimas / ABS, housing.step — 3D spausdinimas / PLA, plate.dxf — Frezavimas / Spalvotieji metalai, 6,5 mm, sign.dwg — Graviravimas / Mediena, 0,1 mm')
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
        $this->assertSame(['bracket.stl — 3D spausdinimas, ABS', 'housing.step — 3D spausdinimas, PLA', 'plate.dxf — Frezavimas, Spalvotieji metalai, 6,5 mm', 'sign.dwg — Graviravimas, Mediena, 0,1 mm', 'Užsakymo mokestis'], $lines('lt'));
        $this->assertSame('plate.dxf — Milling, Non-ferrous metals, 6.5 mm', $lines('en')[2]);

        // Позиция до правки (без method) — только имя файла.
        StlPart::orderBy('id')->first()->update(['parameters' => array_diff_key(StlPart::orderBy('id')->first()->parameters, ['method' => 1])]);
        $this->assertSame('bracket.stl', $lines('lt')[0]);
    }
}
