<?php

namespace Tests\Feature;

use App\Domain\Integrations\Models\IntegrationEvent;
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

    /** Тестовые реквизиты продавца (TEST), плательщик VAT 9 %, серия TEST с первого номера 7. */
    private function seller(array $overrides = []): void
    {
        app(SellerSettings::class)->save([
            'name' => 'TEST UAB '.self::LITHUANIAN,
            'company_code' => 'TEST-000000',
            'vat_code' => 'TEST-LT000000',
            'address' => 'TEST g. 1, Vilnius',
            'vat_payer' => true,
            'vat_rate' => 9,
            'vat_note' => null,
            'series' => 'TEST',
            'first_number' => 7,
            ...$overrides,
        ]);
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
            'material' => 'PLA',
            'infill' => 100,
            'preferences' => ['color' => 'black', 'quality' => 'standard', 'layer' => '0.2', 'finish' => 'none', 'term' => 'standard'],
            'parts' => array_map(fn (string $name): array => [
                'file' => UploadedFile::fake()->createWithContent($name, $cube), 'unit' => 'mm', 'scale' => 100, 'quantity' => 2,
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

    /** Текст PDF через pdftotext; без него тест пропускается (причина — в docs/progress.md). */
    private function pdfText(Invoice $invoice): string
    {
        $binary = (new ExecutableFinder)->find('pdftotext');
        if ($binary === null) {
            $this->markTestSkipped('pdftotext не установлен (poppler-utils)');
        }
        $process = new Process([$binary, '-enc', 'UTF-8', Storage::disk('local')->path($invoice->pdf_path), '-']);
        $process->mustRun();

        return $process->getOutput();
    }

    private function counter(): ?int
    {
        $value = DB::table('counters')->where('name', 'invoices:TEST')->value('value');

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
            ->set('vatPayer', '1')->set('vatCode', 'TEST-LT1')->set('series', 'TEST')->set('firstNumber', '7')
            ->call('save')->assertHasErrors('vatRate');
        $this->assertSame(0, Invoice::count());

        Livewire::test(Settings::class)->set('name', 'TEST UAB')->set('companyCode', 'TEST-1')->set('address', 'TEST g. 1')
            ->set('vatPayer', '1')->set('vatCode', 'TEST-LT1')->set('vatRate', '9')->set('series', 'TEST')->set('firstNumber', '7')
            ->call('save')->assertHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame([$order->id, 'TEST', 7, 9500], [$invoice->order_id, $invoice->series, $invoice->number, $invoice->total]);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        Livewire::test(Overview::class)->assertDontSeeHtml('id="overview-seller-missing"');
        $this->get('/en/admin/settings')->assertOk()->assertSee('TEST-LT1');
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
        $this->assertSame(['bracket.stl', 'cover.stl', 'Order fee', 'Delivery'], $invoice->lines->sortBy('id')->pluck('description')->all());
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
        $this->assertSame([0, 0, null], [Invoice::count(), IntegrationEvent::where('type', 'invoice.issued')->count(), $this->counter()]);
        $this->assertSame([], Storage::disk('local')->allFiles('invoices'));

        // Повтор задания выпускает счёт с первым номером серии; ещё один повтор номер не меняет.
        $failing = false;
        app()->call([new IssueInvoice($order->id), 'handle']);
        app()->call([new IssueInvoice($order->id), 'handle']);
        $invoice = Invoice::sole();
        $this->assertSame([7, 7], [$invoice->number, $this->counter()]);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        $this->assertSame(['TEST-7.pdf'], array_map('basename', Storage::disk('local')->allFiles('invoices')));
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
        $this->assertSame([0, null], [Invoice::count(), $this->counter()]);
        $this->assertSame([], Storage::disk('local')->allFiles('invoices'));

        IntegrationEvent::flushEventListeners();
        IssueInvoice::dispatchSync($order->id);
        $event = IntegrationEvent::where('type', 'invoice.issued')->sole();
        $this->assertSame(['invoices', Invoice::sole()->id, 'TEST-7', '95.00', 'none'], [
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

        $ltText = $this->pdfText(Invoice::where('order_id', $lt->id)->sole());
        $this->assertStringContainsString('TEST UAB '.self::LITHUANIAN, $ltText);
        $this->assertStringContainsString('PVM sąskaita faktūra', $ltText);
        $this->assertStringContainsString('Pristatymas', $ltText);
        $this->assertStringContainsString('Iš viso', $ltText);
        $enText = $this->pdfText(Invoice::where('order_id', $en->id)->sole());
        $this->assertStringContainsString('VAT invoice', $enText);
        $this->assertStringContainsString('Delivery', $enText);
        $this->assertStringContainsString(self::LITHUANIAN, $enText);
        $this->assertStringNotContainsString('PVM sąskaita', $enText);

        $html = view('invoices.pdf', ['invoice' => Invoice::where('order_id', $en->id)->sole(), 'locale' => 'en', 'orderNumber' => 1])->render();
        $this->assertDoesNotMatchRegularExpression('/https?:|src=|<link|url\(|@import/i', $html);
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

        $visitor()->actingAs($this->client)->get($ownUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename=TEST-7.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
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

        $this->actingAs($this->client)->get('/en/account/invoices')->assertOk()->assertSee('data-invoice="TEST-7"', false)->assertSee($url, false);
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
