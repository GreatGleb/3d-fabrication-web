<?php

namespace Tests\Feature;

use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Invoices\InvoiceNumber;
use App\Domain\Invoices\InvoicePdf;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Invoices\IssueInvoice;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Invoices\SellerSettings;
use App\Domain\Orders\OrderSubmission;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\SubmittedOrder;
use App\Domain\Payments\Models\PaymentRequest;
use App\Livewire\Admin\Orders\Card;
use App\Livewire\Admin\Overview;
use App\Livewire\Admin\Settings;
use App\Models\OrderCharge;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Lunar\Models\Transaction;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\LunarCapture;
use Tests\Support\SignedStripeEvents;
use Tests\TestCase;

/**
 * Автоматические счета (W-17, §3.5, §9.2 «Счета»): блокировка без реквизитов и выпуск ожидающих после их сохранения;
 * issueIfDue после оплаты (подписанный webhook W-28), переплаты, снижения цены до оплаченного; не для отменённого заказа;
 * номер — один при повторе события, задания и рендера, исключение рендера откатывает выпуск без расхода номера; итог =
 * подтверждённая цена с VAT, выделенным из строк (D-22); адрес покупателя (D-23); язык PDF — локаль заявки, литовские
 * символы — через pdftotext; событие §5.1 в транзакции выпуска; блокировка цены; доступ к PDF (§6.1); демо-сборка.
 * Заказ — штатная заявка W-12: детали 30 + 30 EUR, плата 5 EUR, доставка 30 EUR = 95 EUR. Реквизиты — только тестовые,
 * с пометкой TEST; ставка 9 % (демо-21 % не используется).
 */
class InvoicesTest extends TestCase
{
    use RefreshDatabase;
    use SignedStripeEvents;

    private const LITHUANIAN = 'ąčęėįšųūž';

    /** Необязательные тестовые реквизиты счёта (правка 02.11, Г5). */
    private const OPTIONAL = [
        'bank' => 'TEST bankas', 'swift' => 'TESTLT2X', 'iban' => 'LT00 TEST 0000 0000 0000', 'email' => 'info@test.example',
        'phone' => '+370 600 11111', 'website' => 'www.test.example', 'delivery_terms' => 'EXW Vilnius', 'issued_by' => 'Jonas TEST',
    ];

    private User $client;

    private User $clientB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        Storage::fake('local');
        $this->client = User::factory()->create();
        $this->clientB = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        $this->useWebhookSecret();
    }

    /**
     * Тестовые реквизиты продавца (TEST), плательщик VAT 9 %, серия TEST; следующий номер текущего года — 7 (правка 05.11,
     * H-06: вместо first_number — счётчик года, как его задаёт админка).
     */
    private function seller(array $overrides = []): void
    {
        $values = [
            'name' => 'TEST UAB '.self::LITHUANIAN,
            'company_code' => 'TEST-000000',
            'vat_code' => 'TEST-LT000000',
            'address' => 'TEST g. 1, Vilnius',
            'vat_payer' => true,
            'vat_rate' => 9,
            'vat_note' => null,
            'series' => 'TEST',
            ...$overrides,
        ];
        app(SellerSettings::class)->save($values);
        if ($values['series'] !== '') {
            InvoiceNumber::setNext($values['series'], 7);
        }
    }

    /** Номер счёта серии TEST текущего года (правка 05.11, H-06): TEST + последняя цифра года + 4 цифры номера. */
    private function seriesNumber(string $digits): string
    {
        return 'TEST'.(now()->year % 10).$digits;
    }

    /** Заявка W-12 с подтверждённой ценой 95 EUR; гость — $user = null. */
    private function order(?User $user = null, string $locale = 'en', string $delivery = 'ship'): SubmittedOrder
    {
        $cube = file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));
        $submitted = app(OrderSubmission::class)->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => $locale,
            'contact' => ['name' => 'Ona TEST', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => null, 'company_code' => null, 'vat_code' => null],
            'delivery' => $delivery,
            'address' => $delivery === 'ship' ? ['country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gedimino pr. 1', 'postcode' => 'LT-01103'] : null,
            'parts' => array_map(fn (string $name): array => [
                'file' => UploadedFile::fake()->createWithContent($name, $cube), 'parameters' => ['method' => 'printing', 'material' => 'PLA', 'infill' => 100, 'infill_consult' => false, 'unit' => 'mm', 'scale' => 100, 'quantity' => 2, 'color' => 'black', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'term' => 'standard', 'needed_by' => null, 'notes' => null],
            ], ['bracket.stl', 'cover.stl']),
        ], $user);
        $this->flow()->confirmPrice($this->admin, $submitted->order, $this->prices($submitted->order, 3000, 500), $delivery === 'ship' ? 3000 : null);

        return $submitted;
    }

    /** @return array<int, int> цены строк без доставки: детали по $part, плата за заказ (последняя строка) $fee */
    private function prices(Order $order, int $part, int $fee): array
    {
        $ids = $order->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
            ->modelKeys();
        $prices = array_fill_keys($ids, $part);
        $prices[end($ids)] = $fee;

        return $prices;
    }

    private function flow(): OrderWorkflow
    {
        return app(OrderWorkflow::class);
    }

    /** Оплата через подписанный webhook W-28: запрос с intent и payment_intent.succeeded на всю сумму запроса. */
    private function pay(Order $order, int $amount, string $eventId): TestResponse
    {
        $request = $this->flow()->requestPayment($this->admin, $order, $amount);
        $request->update(['stripe_intent_id' => 'pi_test_'.$request->id]);

        return $this->sendStripe($this->succeededPayload($request, $eventId))->assertOk();
    }

    /**
     * Текст PDF через pdftotext; без него тест пропускается (причина — в docs/progress.md). $raw — в порядке потока PDF
     * (порядок блоков шаблона), а не раскладки колонок.
     */
    private function pdfText(Invoice $invoice, bool $raw = false): string
    {
        $binary = (new ExecutableFinder)->find('pdftotext');
        if ($binary === null) {
            $this->markTestSkipped('pdftotext не установлен (poppler-utils)');
        }
        $process = new Process([$binary, ...($raw ? ['-raw'] : []), '-enc', 'UTF-8', Storage::disk('local')->path($invoice->pdf_path), '-']);
        $process->mustRun();

        return $process->getOutput();
    }

    private function counter(): ?int
    {
        $value = DB::table('counters')->where('name', 'invoices:TEST:'.now()->year)->value('value');

        return $value === null ? null : (int) $value;
    }

    public function test_without_seller_details_no_invoice_and_saving_them_issues_the_pending_one(): void
    {
        $order = $this->order($this->client)->order;
        $this->pay($order, 9500, 'evt_test_full');
        $this->assertSame(0, Invoice::count());
        $this->assertNull($this->counter(), 'номер не выдан');

        // Предупреждение в Overview — пока реквизиты не сохранены.
        $this->actingAs($this->admin);
        Livewire::test(Overview::class)->assertSeeHtml('id="overview-seller-missing"');

        // Неполные реквизиты (плательщик VAT без ставки) не сохраняются.
        Livewire::test(Settings::class)->set('name', 'TEST UAB')->set('companyCode', 'TEST-1')->set('address', 'TEST g. 1')
            ->set('vatPayer', '1')->set('vatCode', 'TEST-LT1')->set('series', 'TEST')->set('nextNumber', '7')
            ->call('save')->assertHasErrors('vatRate');
        $this->assertSame(0, Invoice::count());

        Livewire::test(Settings::class)->set('name', 'TEST UAB')->set('companyCode', 'TEST-1')->set('address', 'TEST g. 1')
            ->set('vatPayer', '1')->set('vatCode', 'TEST-LT1')->set('vatRate', '9')->set('series', 'TEST')->set('nextNumber', '7')
            ->call('save')->assertHasNoErrors();

        $invoice = Invoice::sole();
        // Правка 05.11, H-06: серия в записи — с цифрой года.
        $this->assertSame([$order->id, 'TEST'.(now()->year % 10), 7, 9500], [$invoice->order_id, $invoice->series, $invoice->number, $invoice->total]);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        Livewire::test(Overview::class)->assertDontSeeHtml('id="overview-seller-missing"');
        $this->get('/en/admin/settings')->assertOk()->assertSee('TEST-LT1');
    }

    /** Правка 02.11, Г5: необязательные поля счёта в форме — EXW Vilnius по умолчанию, пустые — null, complete() их не требует. */
    public function test_settings_form_saves_the_optional_invoice_details(): void
    {
        $this->actingAs($this->admin);
        $form = Livewire::test(Settings::class)->assertSet('deliveryTerms', 'EXW Vilnius')->assertSet('bank', '')
            ->assertSeeHtml('id="field-iban"')->assertSeeHtml('id="field-issuedBy"');
        $form->set('name', 'TEST UAB')->set('companyCode', 'TEST-1')->set('address', 'TEST g. 1')
            ->set('vatPayer', '1')->set('vatCode', 'TEST-LT1')->set('vatRate', '9')->set('series', 'TEST')->set('nextNumber', '1')
            ->set('bank', ' TEST bankas ')->set('iban', 'LT00 TEST')->set('email', 'not-an-email')->call('save')->assertHasErrors('email');
        $form->set('email', 'info@test.example')->set('deliveryTerms', '')->call('save')->assertHasNoErrors();

        $values = app(SellerSettings::class)->values();
        $this->assertSame(
            ['bank' => 'TEST bankas', 'swift' => null, 'iban' => 'LT00 TEST', 'email' => 'info@test.example', 'phone' => null, 'website' => null, 'delivery_terms' => null, 'issued_by' => null],
            array_intersect_key($values, self::OPTIONAL),
        );
        $this->assertTrue(app(SellerSettings::class)->complete());
        Livewire::test(Settings::class)->assertSet('bank', 'TEST bankas')->assertSet('deliveryTerms', '');

        // Запись, сохранённая до правки (без новых ключей), — полная; форма подставляет умолчание условий поставки.
        $this->seller();
        $this->assertTrue(app(SellerSettings::class)->complete());
        Livewire::test(Settings::class)->assertSet('deliveryTerms', 'EXW Vilnius')->assertSet('iban', '');
    }

    public function test_vat_payer_status_and_numbering_are_required_and_a_non_payer_gets_zero_vat_with_the_note(): void
    {
        $settings = app(SellerSettings::class);
        $this->assertFalse($settings->complete());
        $this->seller(['vat_payer' => false, 'vat_rate' => 0, 'vat_code' => null, 'vat_note' => '']);
        $this->assertFalse($settings->complete(), 'неплательщик без пометки');
        $this->seller(['series' => '']);
        $this->assertFalse($settings->complete(), 'без серии');

        $this->seller(['vat_payer' => false, 'vat_rate' => 0, 'vat_code' => null, 'vat_note' => 'TEST: ne PVM mokėtojas']);
        $order = $this->order($this->client)->order;
        $this->pay($order, 9500, 'evt_test_full');

        $invoice = Invoice::sole();
        $this->assertSame([9500, 0, 9500], [$invoice->net_total, $invoice->vat_total, $invoice->total]);
        $this->assertStringContainsString('TEST: ne PVM mokėtojas', $this->pdfText($invoice));
    }

    public function test_full_payment_in_two_steps_issues_one_invoice_whose_total_is_capture_minus_refund_and_vat_is_extracted(): void
    {
        $this->seller();
        $order = $this->order($this->client)->order;
        $this->pay($order, 4000, 'evt_test_advance');
        $this->assertSame(0, Invoice::count(), 'аванс — без счёта (D-04)');
        $this->pay($order, 5500, 'evt_test_rest');

        $invoice = Invoice::with('lines')->sole();
        $paid = Transaction::where('order_id', $order->id)->where('type', 'capture')->sum('amount')
            - Transaction::where('order_id', $order->id)->where('type', 'refund')->sum('amount');
        $this->assertSame((int) $paid, $invoice->total);
        $this->assertSame($order->fresh()->total->value, $invoice->total);
        // VAT 9 % из брутто строки: floor(3000 × 9 / 109 + 0,5) = 248, floor(500 × 9 / 109 + 0,5) = 41.
        $this->assertSame(
            [[3000, 248, 2752], [3000, 248, 2752], [500, 41, 459], [3000, 248, 2752]],
            $invoice->lines->sortBy('id')->map(fn ($line): array => [$line->gross, $line->vat, $line->net])->values()->all(),
        );
        $this->assertSame([785, 8715], [$invoice->vat_total, $invoice->net_total]);
        // Правка 02.11, Г7: описание строки — имя файла и способ.
        $this->assertSame(['bracket.stl — 3D printing, PLA', 'cover.stl — 3D printing, PLA', 'Order fee', 'Delivery'], $invoice->lines->sortBy('id')->pluck('description')->all());
        $this->assertSame([2, 2, 1, 1], $invoice->lines->sortBy('id')->pluck('quantity')->all());

        // Повтор события и повтор задания номер не меняют.
        $this->sendStripe($this->succeededPayload(PaymentRequest::latest('id')->first(), 'evt_test_rest'))->assertOk();
        IssueInvoice::dispatchSync($order->id);
        $this->assertSame([1, 7, 7], [Invoice::count(), Invoice::sole()->number, $this->counter()]);
    }

    public function test_overpayment_issues_the_invoice_on_the_confirmed_price_and_the_surplus_stays_for_review(): void
    {
        $this->seller();
        $order = $this->order($this->client)->order;
        $first = $this->flow()->requestPayment($this->admin, $order, 9500);
        $first->update(['stripe_intent_id' => 'pi_test_first', 'state' => PaymentRequest::VOID]);
        $second = $this->flow()->requestPayment($this->admin, $order, 9500);
        $second->update(['stripe_intent_id' => 'pi_test_second']);
        // Сначала оплачен отменённый запрос, затем второй — оплачено 190 EUR при цене 95 EUR.
        $this->sendStripe($this->succeededPayload($first, 'evt_test_first'))->assertOk();
        $this->sendStripe($this->succeededPayload($second, 'evt_test_second'))->assertOk();

        $this->assertSame(19000, $this->flow()->balance($order->fresh())['paid']);
        $this->assertSame(9500, Invoice::sole()->total);
        $this->assertSame('overpaid', $second->fresh()->review_reason);
    }

    public function test_price_lowered_to_the_paid_amount_issues_the_invoice_and_then_the_price_is_locked(): void
    {
        $this->seller();
        $order = $this->order($this->client)->order;
        LunarCapture::create($order, 4000);
        IssueInvoice::dispatchSync($order->id);
        $this->assertSame(0, Invoice::count());

        $this->flow()->changePrice($this->admin, $order, $this->prices($order, 1000, 500), 1500, 'TEST reason', 'TEST agreed by phone');
        $this->assertSame(4000, Invoice::sole()->total);

        try {
            $this->flow()->changePrice($this->admin, $order->fresh(), $this->prices($order, 1000, 500), 1500, 'TEST again', 'TEST');
            $this->fail('цена изменена после выпуска счёта');
        } catch (ValidationException $exception) {
            $this->assertSame(__('orders.workflow.invoice_issued'), $exception->errors()['workflow'][0]);
            $this->assertSame('The invoice has been issued, so the price cannot be changed.', trans('orders.workflow.invoice_issued', [], 'en'));
        }
        $this->assertSame(4000, $order->fresh()->total->value);
    }

    public function test_cancelled_paid_order_gets_no_invoice(): void
    {
        $this->seller();
        $order = $this->order($this->client)->order;
        $this->flow()->cancel($this->admin, $order, 'TEST cancelled');
        LunarCapture::create($order, 9500);
        IssueInvoice::dispatchSync($order->id);
        $this->assertSame(0, Invoice::count());
        $this->assertSame([], app(InvoiceService::class)->pendingOrderIds()->all());
    }

    public function test_webhook_queues_the_job_after_commit_and_a_render_failure_rolls_back_without_using_a_number(): void
    {
        $this->seller();
        $order = $this->order($this->client, 'en')->order;
        Queue::fake();
        $this->pay($order, 9500, 'evt_test_full');
        Queue::assertPushed(IssueInvoice::class, fn (IssueInvoice $job): bool => $job->orderId === $order->id);
        $this->assertSame(0, Invoice::count());

        // Первый запуск задания: исключение рендера не глотается, выпуск откатывается целиком.
        $failing = true;
        View::composer('invoices.pdf', function () use (&$failing): void {
            if ($failing) {
                throw new RuntimeException('render failed (test)');
            }
        });
        try {
            app()->call([new IssueInvoice($order->id), 'handle']);
            $this->fail('исключение рендера проглочено');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('render failed (test)', $exception->getMessage());
        }
        // Правка 05.11, H-06: счётчик года задан заранее (следующий 7) и откатом не сдвинут.
        $this->assertSame([0, 0, 6], [Invoice::count(), IntegrationEvent::where('type', 'invoice.issued')->count(), $this->counter()]);
        $this->assertSame([], Storage::disk('local')->allFiles('invoices'));

        // Повтор задания выпускает счёт с первым номером серии; ещё один повтор номер не меняет.
        $failing = false;
        app()->call([new IssueInvoice($order->id), 'handle']);
        app()->call([new IssueInvoice($order->id), 'handle']);
        $invoice = Invoice::sole();
        $this->assertSame([7, 7], [$invoice->number, $this->counter()]);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        // Правка 05.11, H-06: серия года и 4 цифры номера.
        $this->assertSame([$this->seriesNumber('0007').'.pdf'], array_map('basename', Storage::disk('local')->allFiles('invoices')));
    }

    public function test_issued_event_is_written_in_the_same_transaction_as_the_invoice(): void
    {
        $this->seller();
        $order = $this->order($this->client)->order;
        LunarCapture::create($order, 9500);
        IntegrationEvent::creating(function (IntegrationEvent $event): void {
            if ($event->type === 'invoice.issued') {
                throw new RuntimeException('outbox write failed (test)');
            }
        });
        try {
            IssueInvoice::dispatchSync($order->id);
            $this->fail('сбой outbox не откатил выпуск');
        } catch (RuntimeException) {
        }
        // Правка 05.11, H-06: счётчик года задан заранее (следующий 7) и откатом не сдвинут.
        $this->assertSame([0, 6], [Invoice::count(), $this->counter()]);
        $this->assertSame([], Storage::disk('local')->allFiles('invoices'));

        IntegrationEvent::flushEventListeners();
        IssueInvoice::dispatchSync($order->id);
        $event = IntegrationEvent::where('type', 'invoice.issued')->sole();
        // Правка 05.11, H-06: номер серии года.
        $this->assertSame(['invoices', Invoice::sole()->id, $this->seriesNumber('0007'), '95.00', 'none'], [
            $event->entity_type, $event->entity_id, $event->data['invoice_number'], $event->data['amount'], $event->mail_status,
        ]);
    }

    public function test_one_invoice_per_order_and_one_number_per_series_are_enforced_by_the_database(): void
    {
        $this->seller();
        $order = $this->order($this->client)->order;
        LunarCapture::create($order, 9500);
        IssueInvoice::dispatchSync($order->id);
        $invoice = Invoice::sole();

        $this->expectException(QueryException::class);
        Invoice::create([...$invoice->only(['series', 'number', 'kind', 'locale', 'seller', 'buyer', 'net_total', 'vat_total', 'total', 'issued_at']), 'order_id' => $invoice->order_id, 'pdf_path' => 'invoices/other.pdf']);
    }

    public function test_pdf_has_lithuanian_characters_in_the_order_locale_without_external_resources(): void
    {
        $this->seller();
        $lt = $this->order($this->client, 'lt')->order;
        $en = $this->order($this->client, 'en')->order;
        foreach ([$lt, $en] as $order) {
            LunarCapture::create($order, 9500);
            IssueInvoice::dispatchSync($order->id);
        }

        // Правка 02.11, Г5: надписи образца заказчика в верхнем регистре.
        $ltText = $this->pdfText(Invoice::where('order_id', $lt->id)->sole());
        $this->assertStringContainsString('TEST UAB '.self::LITHUANIAN, $ltText);
        $this->assertStringContainsString('PVM SĄSKAITA FAKTŪRA', $ltText);
        $this->assertStringContainsString('Pristatymas', $ltText);
        $this->assertStringContainsString('IŠ VISO', $ltText);
        $enText = $this->pdfText(Invoice::where('order_id', $en->id)->sole());
        $this->assertStringContainsString('VAT INVOICE', $enText);
        $this->assertStringContainsString('Delivery', $enText);
        $this->assertStringContainsString(self::LITHUANIAN, $enText);
        $this->assertStringNotContainsString('PVM SĄSKAITA', $enText);

        // Внешних ресурсов нет: единственный src — логотип data:image/ (правка 02.11, Г5), ссылок и url() нет.
        $html = view('invoices.pdf', ['invoice' => Invoice::where('order_id', $en->id)->sole(), 'locale' => 'en', 'orderDate' => now(), 'logo' => InvoicePdf::logo()])->render();
        $this->assertSame(1, preg_match_all('/src=/i', $html));
        $this->assertMatchesRegularExpression('/<img src="data:image\/png;base64,[A-Za-z0-9+\/=]+"/', $html);
        $withoutLogo = preg_replace('/src="data:image\/[^"]+"/', '', $html);
        $this->assertDoesNotMatchRegularExpression('/https?:|src=|href=|<link|url\(|@import/i', $withoutLogo);
    }

    /**
     * Правка 02.11, Г5: блоки образца LT/EN по порядку — номер без дефиса, дата, покупатель, заказ с телефоном и датой
     * заказа (placed_at), условия поставки, KAINA за единицу без VAT, итоги, сумма прописью, «Apmokėta», банк, «gavau»,
     * подписи и подвал продавца из настроек.
     */
    public function test_new_invoice_follows_the_customer_sample_in_lithuanian_and_english(): void
    {
        $this->seller(self::OPTIONAL);
        $lt = $this->order($this->client, 'lt')->order;
        $en = $this->order($this->client, 'en')->order;
        foreach ([$lt, $en] as $order) {
            $order->update(['placed_at' => '2026-10-08 10:00:00']);
            LunarCapture::create($order, 9500);
            IssueInvoice::dispatchSync($order->id);
        }
        $ltInvoice = Invoice::where('order_id', $lt->id)->sole();
        $enInvoice = Invoice::where('order_id', $en->id)->sole();
        // Правка 05.11, H-06: номер серии года.
        $this->assertSame([$this->seriesNumber('0007'), $this->seriesNumber('0008')], [$ltInvoice->label(), $enInvoice->label()]);
        $this->assertSame(['Ona TEST', '+370 600 00000'], [$ltInvoice->buyer['name'], $ltInvoice->buyer['phone']]);

        // Порядок блоков — по тексту HTML, из которого dompdf рисует PDF (в тексте PDF ячейки одной строки при переносе
        // перемешиваются); подвал (position: fixed) стоит в разметке первым и проверяется отдельно.
        // Строки: детали 30 EUR × 2 шт. (нетто 27,52, за единицу 13,76), плата 5 EUR, доставка 30 EUR; итог 95 EUR с VAT 9 %.
        // Правка 02.11, Г7: в описании детали — имя файла и способ.
        $this->assertInOrder($this->htmlText($ltInvoice), [
            'TEST UAB '.self::LITHUANIAN, 'TEST g. 1, Vilnius', 'ĮMONĖS KODAS: TEST-000000', 'PVM MOKĖTOJO KODAS: TEST-LT000000',
            'info@test.example', '+370 600 11111', 'www.test.example',
            'PVM SĄSKAITA FAKTŪRA', $this->seriesNumber('0007'), 'Data : '.now()->format('Y-m-d'),
            'PIRKĖJAS', 'Ona TEST', 'Gedimino pr. 1, Vilnius, LT-01103, LT',
            'UŽSAKYMAS: Ona TEST, TEL. +370 600 00000', 'UŽSAKYMO DATA: 2026-10-08', 'PRISTATYMO SĄLYGOS (INCOTERMS 2010): EXW Vilnius',
            'PAVADINIMAS KIEKIS KAINA SUMA', 'bracket.stl — 3D spausdinimas, PLA 2 13,76 27,52', 'cover.stl — 3D spausdinimas, PLA 2 13,76 27,52', 'Užsakymo mokestis 1 4,59 4,59',
            'Pristatymas 1 27,52 27,52', 'IŠ VISO 87,15', 'PVM 9 % 7,85', 'Devyniasdešimt penki EUR 00 ct IŠ VISO SU PVM 95,00',
            'Apmokėta: 95,00 EUR', 'Bankas: TEST bankas SWIFT: TESTLT2X', 'A / S: LT00 TEST 0000 0000 0000',
            'PREKES / PASLAUGAS GAVAU. PRETENZIJŲ KIEKIUI IR KOKYBEI NETURIU.',
            'IŠRAŠĖ / IŠDAVĖ: Jonas TEST', '(PAREIGOS, VARDAS, PAVARDĖ, PARAŠAS)', 'GAVO:', '(PAREIGOS, VARDAS, PAVARDĖ, PARAŠAS)',
        ]);
        $this->assertInOrder($this->htmlText($enInvoice), [
            'TEST UAB', 'COMPANY NUMBER: TEST-000000', 'VAT NUMBER: TEST-LT000000', 'info@test.example', 'www.test.example',
            'VAT INVOICE', $this->seriesNumber('0008'), 'Date: '.now()->format('Y-m-d'), 'BUYER', 'Ona TEST',
            'ORDER: Ona TEST, TEL. +370 600 00000', 'ORDER DATE: 2026-10-08', 'DELIVERY TERMS (INCOTERMS 2010): EXW Vilnius',
            'DESCRIPTION QTY PRICE AMOUNT', 'bracket.stl — 3D printing, PLA 2 13.76 27.52', 'Order fee 1 4.59 4.59', 'Delivery 1 27.52 27.52',
            'TOTAL 87.15', 'VAT 9 % 7.85', 'Ninety-five EUR 00 ct TOTAL INCL. VAT 95.00',
            'Paid: 95.00 EUR', 'Bank: TEST bankas SWIFT: TESTLT2X', 'Account: LT00 TEST 0000 0000 0000',
            'GOODS / SERVICES RECEIVED. NO CLAIMS AS TO QUANTITY AND QUALITY.', 'ISSUED BY: Jonas TEST', 'RECEIVED BY:', '(POSITION, NAME, SURNAME, SIGNATURE)',
        ]);

        // Сам PDF: те же надписи и значения в тексте pdftotext.
        $ltText = $this->pdfText($ltInvoice);
        foreach (['PVM SĄSKAITA FAKTŪRA', $this->seriesNumber('0007'), 'PIRKĖJAS', 'UŽSAKYMO DATA', '2026-10-08', 'EXW Vilnius', 'KAINA', '13,76',
            'Devyniasdešimt penki EUR 00 ct', 'IŠ VISO SU PVM', 'Apmokėta: 95,00 EUR', 'SWIFT: TESTLT2X', 'A / S: LT00 TEST 0000 0000 0000',
            'NETURIU.', 'IŠRAŠĖ / IŠDAVĖ: Jonas TEST', 'GAVO:', 'TEST UAB '.self::LITHUANIAN, 'www.test.example'] as $part) {
            $this->assertStringContainsString($part, $ltText);
        }
        foreach (['TEST-7', 'Apmokėti iki', 'Liko apmokėti'] as $absent) {
            $this->assertStringNotContainsString($absent, $ltText);
        }
        $enText = $this->pdfText($enInvoice);
        foreach (['VAT INVOICE', $this->seriesNumber('0008'), 'ORDER DATE', 'PRICE', '13.76', 'Ninety-five EUR 00 ct', 'TOTAL INCL. VAT', 'Paid: 95.00 EUR', 'RECEIVED BY:'] as $part) {
            $this->assertStringContainsString($part, $enText);
        }
        $this->snapshot($ltInvoice, 'lt');
        $this->snapshot($enInvoice, 'en');
    }

    /** Правка 02.11, Г5: без необязательных настроек их строк в счёте нет (записи, сохранённые до правки, — без ключей). */
    public function test_empty_optional_seller_details_are_not_printed(): void
    {
        $this->seller();
        $order = $this->order($this->client, 'lt')->order;
        LunarCapture::create($order, 9500);
        IssueInvoice::dispatchSync($order->id);

        $text = $this->pdfText(Invoice::sole());
        foreach (['Bankas', 'SWIFT', 'A / S', 'PRISTATYMO SĄLYGOS', 'IŠRAŠĖ', 'EXW'] as $absent) {
            $this->assertStringNotContainsString($absent, $text);
        }
        $this->assertStringContainsString('Apmokėta: 95,00 EUR', $text);
        $this->assertStringContainsString('GAVO:', $text);
    }

    /**
     * Старые форматы печатаются как были (правка 02.11, Г5; правка 05.11, H-06): AIQ-1 (до 02.11) и AIQW00001 (серия 02.11)
     * — в кабинете, карточке админки и имени скачиваемого файла; номер берётся из сохранённой при выписке записи.
     */
    public function test_invoices_issued_in_earlier_formats_keep_their_printed_numbers(): void
    {
        $this->seller(['series' => 'AIQ']);
        $first = $this->order($this->client);
        $second = $this->order($this->client);
        foreach ([$first, $second] as $submitted) {
            LunarCapture::create($submitted->order, 9500);
            IssueInvoice::dispatchSync($submitted->order->id);
        }
        // Записи до правки: серия без цифры года и PDF по пути, как он был напечатан.
        foreach ([[$first, 'AIQ', 1, 'AIQ-1'], [$second, 'AIQW', 1, 'AIQW00001']] as [$submitted, $series, $number, $printed]) {
            $invoice = Invoice::where('order_id', $submitted->order->id)->sole();
            Storage::disk('local')->move($invoice->pdf_path, 'invoices/'.$printed.'.pdf');
            $invoice->update(['series' => $series, 'number' => $number, 'pdf_path' => 'invoices/'.$printed.'.pdf']);
            $url = '/invoices/'.$invoice->id;

            $this->assertSame($printed, $invoice->fresh()->label());
            $this->actingAs($this->client)->get('/en/account/invoices')->assertOk()->assertSee('data-invoice="'.$printed.'"', false)->assertSee($printed.' ·', false);
            $this->get('/en/account/orders/'.$submitted->meta->number)->assertOk()->assertSee('Invoice '.$printed.' (PDF)');
            $this->get($url)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename='.$printed.'.pdf');
            $this->actingAs($this->admin);
            Livewire::test(Card::class, ['orderId' => $submitted->order->id])->assertSeeHtml('data-invoice="'.$printed.'"');
        }
    }

    /**
     * Правка 05.11, H-06: AIQ + последняя цифра года выписки + номер не короче 4 цифр (AIQ60008, после 9999 — AIQ610000),
     * счётчик свой на каждый год (31.12 → 01.01: AIQ70001). Номер сохраняется при выписке: смена серии в настройках
     * выданные номера не меняет. PDF собирается с новым номером.
     */
    public function test_new_invoices_are_numbered_in_the_customer_series_per_year(): void
    {
        $this->travelTo('2026-12-31 22:00:00');
        $this->seller(['series' => 'AIQ']);
        InvoiceNumber::setNext('AIQ', 8);
        $issue = function (): Invoice {
            $order = $this->order($this->client, 'lt')->order;
            LunarCapture::create($order, 9500);
            IssueInvoice::dispatchSync($order->id);

            return Invoice::where('order_id', $order->id)->sole();
        };

        $invoice = $issue();
        $this->assertSame(['AIQ60008', 'AIQ6', 8, 'invoices/AIQ60008.pdf'], [$invoice->label(), $invoice->series, $invoice->number, $invoice->pdf_path]);
        $this->assertSame('AIQ60009', $issue()->label());
        InvoiceNumber::setNext('AIQ', 9999);
        $this->assertSame(['AIQ69999', 'AIQ610000'], [$issue()->label(), $issue()->label()]);

        $this->travelTo('2027-01-01 00:00:30');
        $newYear = $issue();
        $this->assertSame(['AIQ70001', 'AIQ7', 1], [$newYear->label(), $newYear->series, $newYear->number]);
        $this->assertSame('AIQ70002', $issue()->label());
        $this->assertStringContainsString('AIQ70001', $this->pdfText($newYear));

        // Номер не пересчитывается после смены серии в настройках.
        $this->seller(['series' => 'XYZ']);
        $this->assertSame(['AIQ60008', 'AIQ70001'], [$invoice->fresh()->label(), $newYear->fresh()->label()]);
    }

    /**
     * Правка 05.11, H-06: в «Nustatymai» — «Kitas sąskaitos numeris (šiems metams)» (EN — «Next invoice number (this year)»)
     * вместо первого номера серии; форма показывает следующий номер года, его можно поднять, но не опустить до уже
     * выданного — ошибка по-литовски, настройки и счётчик не меняются.
     */
    public function test_admin_sets_the_next_number_of_this_year_but_not_an_issued_one(): void
    {
        $this->travelTo('2026-11-05 10:00:00');
        $this->actingAs($this->admin);
        $this->get('/lt/administravimas/settings')->assertOk()->assertSee('Kitas sąskaitos numeris (šiems metams)')
            ->assertDontSee('Pirmas sąskaitos numeris')->assertSeeHtml('id="field-nextNumber"');
        $this->get('/en/admin/settings')->assertOk()->assertSee('Next invoice number (this year)')->assertDontSee('First invoice number');

        app()->setLocale('lt');
        $form = fn () => Livewire::test(Settings::class)->set('name', 'TEST UAB')->set('companyCode', 'TEST-1')->set('address', 'TEST g. 1')
            ->set('vatPayer', '1')->set('vatCode', 'TEST-LT1')->set('vatRate', '9');
        $form()->assertSet('series', 'AIQ')->assertSet('nextNumber', '1')->set('nextNumber', '8')->call('save')->assertHasNoErrors();
        $this->assertArrayNotHasKey('first_number', app(SellerSettings::class)->values());

        $order = $this->order($this->client)->order;
        LunarCapture::create($order, 9500);
        IssueInvoice::dispatchSync($order->id);
        $this->assertSame('AIQ60008', Invoice::sole()->label());

        $form()->assertSet('nextNumber', '9');
        foreach (['8', '5'] as $issued) {
            $form()->set('nextNumber', $issued)->set('address', 'TEST g. 2')->call('save')
                ->assertHasErrors('nextNumber')->assertSee('Šis numeris jau išduotas. Įveskite didesnį nei 8.');
        }
        $this->assertSame('TEST g. 1', app(SellerSettings::class)->values()['address']);
        $form()->assertSet('nextNumber', '9');

        $form()->set('nextNumber', '20')->call('save')->assertHasNoErrors();
        $second = $this->order($this->client)->order;
        LunarCapture::create($second, 9500);
        IssueInvoice::dispatchSync($second->id);
        $this->assertSame('AIQ60020', Invoice::where('order_id', $second->id)->sole()->label());
    }

    /** Свой логотип сайта из админки важнее defaults/logo.png (правка 02.11, Г5). */
    public function test_invoice_logo_is_the_site_logo_as_data_uri(): void
    {
        $default = 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('defaults/logo.png')));
        $this->assertSame($default, InvoicePdf::logo());

        Storage::fake('public');
        $image = imagecreatetruecolor(40, 10);
        $path = sys_get_temp_dir().'/aiqme-invoice-logo-'.bin2hex(random_bytes(4)).'.png';
        imagepng($image, $path);
        $home = app(ContentService::class)->createItem($this->admin, ContentType::PageText, 'home');
        app(ContentService::class)->addMedia($this->admin, $home, $path, 'logo');
        @unlink($path);

        $logo = InvoicePdf::logo();
        $this->assertNotSame($default, $logo);
        $this->assertSame(file_get_contents($home->fresh()->getFirstMedia('logo')->getPath()), base64_decode(substr($logo, strlen('data:image/png;base64,'))));
    }

    /**
     * Кадр первой страницы образцового счёта (pdftoppm) в игнорируемый каталог отчёта build/verification/invoices/ —
     * для сверки человеком с образцом заказчика (правка 02.11, Г5). Без pdftoppm кадра нет.
     */
    private function snapshot(Invoice $invoice, string $locale): void
    {
        $binary = (new ExecutableFinder)->find('pdftoppm');
        if ($binary === null) {
            return;
        }
        $directory = base_path('build/verification/invoices');
        @mkdir($directory, 0777, true);
        copy(Storage::disk('local')->path($invoice->pdf_path), $directory.'/invoice-'.$locale.'.pdf');
        (new Process([$binary, '-png', '-r', '100', '-f', '1', '-singlefile', $directory.'/invoice-'.$locale.'.pdf', $directory.'/invoice-'.$locale]))->mustRun();
    }

    /** Текст HTML шаблона счёта без тегов с пробелами вместо переносов — те же параметры, что у InvoicePdf. */
    private function htmlText(Invoice $invoice): string
    {
        $html = view('invoices.pdf', ['invoice' => $invoice, 'locale' => $invoice->locale, 'orderDate' => $invoice->order->placed_at, 'logo' => ''])->render();

        $html = (string) preg_replace(['/<(style|title)>.*?<\/\1>/s', '/<[^>]+>/'], ['', ' '], $html);

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    }

    /** @param  list<string>  $parts  фрагменты текста в этом порядке */
    private function assertInOrder(string $text, array $parts): void
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $offset = 0;
        foreach ($parts as $part) {
            $position = mb_strpos($text, $part, $offset);
            $this->assertNotFalse($position, 'нет «'.$part.'» после позиции '.$offset.' в: '.$text);
            $offset = $position + mb_strlen($part);
        }
    }

    public function test_buyer_address_comes_from_the_request_or_the_profile_and_a_profile_change_does_not_touch_the_pdf(): void
    {
        $this->seller();
        $shipped = $this->order($this->client)->order;
        $this->client->profile()->create(['address' => 'Profilio g. 2', 'city' => 'Kaunas', 'postcode' => 'LT-44000']);
        $pickup = $this->order($this->client, 'en', 'pickup')->order;
        $guest = $this->order(null, 'en', 'pickup')->order;
        foreach ([$shipped, $pickup, $guest] as $order) {
            LunarCapture::create($order, $order->fresh()->total->value);
            IssueInvoice::dispatchSync($order->id);
        }

        $address = fn (Order $order): ?string => Invoice::where('order_id', $order->id)->sole()->buyer['address'];
        $this->assertSame('Gedimino pr. 1, Vilnius, LT-01103, LT', $address($shipped));
        $this->assertSame('Profilio g. 2, Kaunas, LT-44000', $address($pickup));
        $this->assertNull($address($guest), 'без адреса в заявке и профиля — счёт без адреса (D-23)');

        $invoice = Invoice::where('order_id', $pickup->id)->sole();
        $before = hash('sha256', Storage::disk('local')->get($invoice->pdf_path));
        $this->client->profile()->update(['address' => 'Kita g. 3']);
        IssueInvoice::dispatchSync($pickup->id);
        $this->assertSame($before, hash('sha256', Storage::disk('local')->get($invoice->pdf_path)));
        $this->assertSame('Profilio g. 2, Kaunas, LT-44000', $address($pickup));
    }

    public function test_owner_admin_and_guest_with_the_session_right_download_and_everyone_else_gets_404(): void
    {
        $this->seller();
        $own = $this->order($this->client)->order;
        $guest = $this->order(null);
        $otherGuest = $this->order(null);
        foreach ([$own, $guest->order, $otherGuest->order] as $order) {
            LunarCapture::create($order, 9500);
            IssueInvoice::dispatchSync($order->id);
        }
        $ownUrl = '/invoices/'.Invoice::where('order_id', $own->id)->sole()->id;
        $guestUrl = '/invoices/'.Invoice::where('order_id', $guest->order->id)->sole()->id;

        $visitor = function (): static {
            Auth::forgetGuards();
            $this->flushSession();

            return $this;
        };
        $who = [
            'посетитель' => fn () => $visitor(),
            'клиент B' => fn () => $visitor()->actingAs($this->clientB),
            'гость другого заказа' => fn () => $visitor()->get('/en/order/'.$otherGuest->meta->number.'/'.$otherGuest->guestToken)->assertOk(),
        ];
        foreach ($who as $name => $setUp) {
            $setUp();
            foreach ([$ownUrl, $guestUrl, '/invoices/999999'] as $url) {
                $this->assertSame(404, $this->get($url)->getStatusCode(), $name.' GET '.$url);
                $this->assertSame(404, $this->call('HEAD', $url)->getStatusCode(), $name.' HEAD '.$url);
            }
        }

        // Правка 05.11, H-06: имя файла — номер серии года.
        $visitor()->actingAs($this->client)->get($ownUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename='.$this->seriesNumber('0007').'.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($guestUrl)->assertNotFound();
        $visitor()->actingAs($this->admin)->get($ownUrl)->assertOk();
        $this->get($guestUrl)->assertOk();

        // Гость с правом на свой заказ: ссылка на гостевой странице, скачивание без токена в адресе.
        $page = $visitor()->get('/en/order/'.$guest->meta->number.'/'.$guest->guestToken)->assertOk()->assertSee($guestUrl, false);
        $this->assertStringNotContainsString($guest->guestToken, $guestUrl);
        $this->assertStringNotContainsString($ownUrl, $page->getContent());
        $this->get($guestUrl)->assertOk();
        $this->call('HEAD', $guestUrl)->assertOk();
        $this->get($ownUrl)->assertNotFound();
    }

    public function test_account_tab_card_and_admin_card_list_the_invoice_and_hide_invoice_blocks_in_the_demo_build(): void
    {
        $this->seller();
        $submitted = $this->order($this->client);
        LunarCapture::create($submitted->order, 9500);
        IssueInvoice::dispatchSync($submitted->order->id);
        $url = '/invoices/'.Invoice::sole()->id;

        // Правка 05.11, H-06: номер серии года.
        $this->actingAs($this->client)->get('/en/account/invoices')->assertOk()->assertSee('data-invoice="'.$this->seriesNumber('0007').'"', false)->assertSee($url, false);
        $this->actingAs($this->clientB)->get('/en/account/invoices')->assertOk()->assertDontSee($url, false)->assertSee('No invoices yet.');
        $this->actingAs($this->client)->get('/en/account/orders/'.$submitted->meta->number)->assertOk()->assertSee($url, false);
        $this->actingAs($this->admin);
        Livewire::test(Card::class, ['orderId' => $submitted->order->id])->assertSeeHtml($url);

        app(SellerSettings::class)->save([...app(SellerSettings::class)->values(), 'name' => '']);
        $this->app['env'] = 'demo';
        Livewire::test(Card::class, ['orderId' => $submitted->order->id])->assertDontSeeHtml($url);
        Livewire::test(Overview::class)->assertDontSeeHtml('id="overview-seller-missing"');
        $this->actingAs($this->client)->get('/en/account/invoices')->assertOk()->assertDontSee($url, false);
        $this->get('/en/account/orders/'.$submitted->meta->number)->assertOk()->assertDontSee($url, false);
        // Вкладки Settings (реквизиты счетов) в меню демо нет; экспорт её не берёт (AdminController::NOT_IN_DEMO).
        $this->actingAs($this->admin)->get('/en/admin')->assertOk()->assertDontSee('/en/admin/settings', false)->assertSee('/en/admin/integrations', false);
    }

    public function test_pending_invoices_job_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->assertSuccessful()->expectsOutputToContain('IssuePendingInvoices');
    }

    public function test_pdf_render_time_is_measured(): void
    {
        $this->seller();
        $order = $this->order($this->client)->order;
        LunarCapture::create($order, 9500);
        IssueInvoice::dispatchSync($order->id);
        $invoice = Invoice::sole();
        $started = hrtime(true);
        $pdf = app(InvoicePdf::class)->render($invoice);
        $milliseconds = (hrtime(true) - $started) / 1e6;
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertLessThan(10_000, $milliseconds);
        fwrite(STDERR, sprintf("\n[W-17] invoice PDF render: %.0f ms\n", $milliseconds));
    }
}
