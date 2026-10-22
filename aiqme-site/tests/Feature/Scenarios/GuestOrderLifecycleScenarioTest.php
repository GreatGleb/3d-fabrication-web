<?php

namespace Tests\Feature\Scenarios;

use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Invoices\SellerSettings;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentRequest;
use App\Livewire\Admin\Orders\Card;
use App\Mail\FormSubmitted;
use App\Models\OrderCharge;
use App\Models\StlFile;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Lunar\Models\Transaction;
use Stripe\StripeClientInterface;
use Tests\Support\FakeStripe;
use Tests\Support\Html;
use Tests\Support\SignedStripeEvents;
use Tests\TestCase;

/**
 * Сценарий 2 §9.3 (W-30, уровень L): гость отправляет калькулятор с 2 STL (POST /en/quote, W-12) → гостевая ссылка
 * (W-15) → администратор скачивает STL (W-32) и подтверждает 150 EUR = 120 + доставка 30 в карточке (W-14) → запрос
 * аванса 40 EUR → гость открывает оплату (W-16, мок StripeClientInterface) → подписанное payment_intent.succeeded (W-28)
 * → производство при долге 110 EUR после подтверждения → запрос остатка → оплата → счёт TEST-7 по тестовым реквизитам
 * (W-17) → shipped с треком → completed. После каждого шага сверяются строки Orders, Payments и Events фейка сетки (W-18).
 */
class GuestOrderLifecycleScenarioTest extends TestCase
{
    use RefreshDatabase;
    use ScenarioSupport;
    use SignedStripeEvents;

    private User $admin;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        $this->seed(PrototypeContentSeeder::class);
        Storage::fake('local');
        Mail::fake();
        Livewire::flushState();
        $this->admin = $this->makeAdmin();
        $this->connectSheets();
        $this->useWebhookSecret();
        config(['services.stripe.key' => 'test-stripe-publishable-not-real', 'services.stripe.secret' => 'test-stripe-secret-not-real']);
        $this->stripe = new FakeStripe;
        $this->app->instance(StripeClientInterface::class, $this->stripe);
        // Тестовые реквизиты продавца с пометкой TEST (рабочих реквизитов нет — D-22, unknowns задачи).
        app(SellerSettings::class)->save(['name' => 'TEST UAB', 'company_code' => 'TEST-000000', 'vat_code' => 'TEST-LT000000', 'address' => 'TEST g. 1, Vilnius',
            'vat_payer' => true, 'vat_rate' => 9, 'vat_note' => null, 'series' => 'TEST', 'first_number' => 7]);
    }

    private function stl(string $fixture, string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/fixtures/stl/'.$fixture)));
    }

    private function card(Order $order): Testable
    {
        $this->visitor();

        return Livewire::actingAs($this->admin)->test(Card::class, ['orderId' => $order->id]);
    }

    /**
     * Строка Orders фейка сетки: стадия, подтверждённая цена, оплачено, долг, состояние оплаты, трек, номер счёта.
     *
     * @return list<string>
     */
    private function orderRow(string $number): array
    {
        $row = $this->sheets->row('Orders', $number);
        $this->assertNotNull($row, 'строка Orders заказа '.$number);
        $this->assertCount(1, $this->sheets->rows('Orders'), 'одна строка на заказ');

        return [$row['Production stage'], $row['Confirmed price EUR'], $row['Paid EUR'], $row['Due EUR'], $row['Payment state'], $row['Tracking'], $row['Invoice No']];
    }

    /**
     * Строки Payments: public_id => [сумма, состояние, ссылка Stripe].
     *
     * @return array<string, list<string>>
     */
    private function paymentRows(): array
    {
        $rows = [];
        foreach ($this->sheets->rows('Payments') as $row) {
            $row = array_pad($row, 7, '');
            $rows[$row[0]] = [$row[2], $row[3], $row[6]];
        }

        return $rows;
    }

    /** Лист Events повторяет outbox: каждое событие БД ровно одной строкой, по порядку, канал sheets — sent. */
    private function assertEvents(array $expected): void
    {
        $this->assertSame($expected, $this->eventTypes());
        $this->assertSame($expected, IntegrationEvent::orderBy('id')->pluck('type')->all());
        $this->assertSame([], IntegrationEvent::where('sheets_status', '!=', 'sent')->pluck('type')->all());
    }

    /** Гость с чистой сессией открывает гостевую ссылку, переходит к оплате запроса (intent мока), Stripe присылает succeeded. */
    private function guestPays(string $guestUrl, PaymentRequest $request, string $eventId): void
    {
        $page = $this->visitor()->get($guestUrl)->assertOk();
        $checkout = route('en.checkout', $request->public_id);
        $page->assertSee('href="'.$checkout.'"', false);
        $this->get($checkout)->assertOk()->assertSee('id="payment-element"', false);
        $this->assertNotNull($request->fresh()->stripe_intent_id);
        $this->sendStripe($this->succeededPayload($request, $eventId))->assertOk();
        $this->assertSame(PaymentRequest::PAID, $request->fresh()->state);
    }

    public function test_guest_order_from_two_stl_to_completed_with_sheet_rows_after_every_step(): void
    {
        // 1. Гость: калькулятор с 2 STL → заявка без оплаты, страница подтверждения с гостевой ссылкой.
        $redirect = $this->post('/en/quote', [
            'submission_token' => (string) Str::uuid(),
            'name' => 'Ona TEST', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => 'UAB TEST', 'company_code' => 'TEST-123', 'vat_code' => '',
            'delivery' => 'ship', 'country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gedimino pr. 1', 'postcode' => 'LT-01103',
            'material' => 'PLA', 'infill' => '100', 'color' => 'black', 'quality' => 'standard', 'layer' => '0.2', 'finish' => 'none', 'term' => 'standard',
            'parts' => [
                ['file' => $this->stl('cube-10mm-ascii.stl', 'bracket.stl'), 'unit' => 'mm', 'scale' => '100', 'quantity' => '2'],
                ['file' => $this->stl('cube-10mm-binary.stl', 'cover.stl'), 'unit' => 'mm', 'scale' => '100', 'quantity' => '1'],
            ],
        ], ['Accept' => 'application/json'])->assertCreated()->json('redirect');
        $confirmation = Html::xpath($this->get($redirect)->assertOk()->getContent());
        $number = $confirmation->query('//*[@data-order-number]')->item(0)->getAttribute('data-order-number');
        $guestUrl = $confirmation->query('//a[@data-guest-link]')->item(0)->getAttribute('href');
        $order = Order::sole();
        $this->assertNull($order->user_id);
        Mail::assertSent(FormSubmitted::class, fn (FormSubmitted $mail): bool => $mail->hasTo('info@aiqme.com'));
        $this->assertSame(['review', '', '0.00', '', 'unpaid', '', ''], $this->orderRow($number));
        $this->assertSame([], $this->paymentRows());
        $this->assertEvents(['order.submitted']);
        $this->assertSame('2', $this->sheets->row('Orders', $number)['Files']);

        // 2. Гостевая ссылка открывается из чистой сессии.
        $this->visitor()->get($guestUrl)->assertOk()->assertSee('Order No. '.$number)->assertSee('bracket.stl')->assertSee('cover.stl');

        // 3. Администратор скачивает оба STL по ссылкам карточки: байты те же, что загрузил гость.
        $this->visitor()->actingAs($this->admin);
        $adminPage = Html::xpath($this->get('/en/admin/orders?order='.$order->id)->assertOk()->getContent());
        $links = array_values(array_unique(array_map(fn ($a): string => $a->getAttribute('href'),
            iterator_to_array($adminPage->query('//a[contains(@href, "/stl/")]')))));
        $this->assertCount(2, $links);
        $expected = ['bracket.stl' => 'cube-10mm-ascii.stl', 'cover.stl' => 'cube-10mm-binary.stl'];
        foreach ($links as $link) {
            $file = StlFile::findOrFail((int) basename($link));
            $download = $this->get($link)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename='.$file->original_name);
            $this->assertSame(file_get_contents(base_path('tests/fixtures/stl/'.$expected[$file->original_name])), $download->streamedContent());
        }

        // 4. Подтверждение 150 EUR: детали 70 + 45, плата 5 (итого 120) и доставка 30 — через форму карточки.
        [$a, $b, $fee] = $order->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
            ->modelKeys();
        $this->card($order)->set("prices.$a", '70')->set("prices.$b", '45')->set("prices.$fee", '5')->set('delivery', '30')
            ->call('confirmPrice')->assertHasNoErrors();
        $this->assertSame(15000, $order->fresh()->total->value);
        $this->assertSame(['confirmed', '150.00', '0.00', '150.00', 'unpaid', '', ''], $this->orderRow($number));
        $this->assertEvents(['order.submitted', 'order.price_confirmed']);

        // 5. Запрос аванса 40 EUR.
        $this->card($order)->set('amount', '40')->call('requestPayment')->assertHasNoErrors()->assertSee('Payment request created.');
        $advance = PaymentRequest::sole();
        $this->assertSame([$advance->public_id => ['40.00', 'pending', '']], $this->paymentRows());
        $this->assertEvents(['order.submitted', 'order.price_confirmed', 'payment_request.created']);

        // 6. Гость платит аванс: intent создаёт первое открытие страницы оплаты, деньги подтверждает только подписанный webhook.
        $this->guestPays($guestUrl, $advance, 'evt_test_advance');
        $this->assertSame(['confirmed', '150.00', '40.00', '110.00', 'partial', '', ''], $this->orderRow($number));
        $this->assertSame([$advance->public_id => ['40.00', 'paid', 'ch_test_'.$advance->public_id]], $this->paymentRows());
        $this->assertEvents(['order.submitted', 'order.price_confirmed', 'payment_request.created', 'payment.succeeded']);
        $this->assertSame(ProductionStage::Confirmed, ProductionState::where('order_id', $order->id)->value('stage'), 'оплата не запускает производство');

        // 7. Производство при долге 110 EUR — только после второго нажатия (подтверждение администратора).
        $card = $this->card($order)->call('startProduction')->assertSet('confirming', 'start');
        $this->assertSame(ProductionStage::Confirmed, ProductionState::where('order_id', $order->id)->value('stage'));
        $card->call('startProduction')->assertSee('Stage changed.');
        $this->assertSame(['in_production', '150.00', '40.00', '110.00', 'partial', '', ''], $this->orderRow($number));
        $this->assertEvents(['order.submitted', 'order.price_confirmed', 'payment_request.created', 'payment.succeeded', 'order.stage_changed']);

        // 8. Запрос остатка 110 EUR и оплата → счёт выпущен автоматически.
        $this->card($order)->set('amount', '110')->call('requestPayment')->assertHasNoErrors();
        $rest = PaymentRequest::where('id', '!=', $advance->id)->sole();
        $this->assertSame(['110.00', 'pending', ''], $this->paymentRows()[$rest->public_id]);
        $this->assertSame(0, Invoice::count());
        $this->guestPays($guestUrl, $rest, 'evt_test_rest');
        $invoice = Invoice::sole();
        $this->assertSame(['TEST-7', 15000], [$invoice->label(), $invoice->total]);
        $this->assertSame(['in_production', '150.00', '150.00', '0.00', 'paid', '', 'TEST-7'], $this->orderRow($number));
        $this->assertSame([
            $advance->public_id => ['40.00', 'paid', 'ch_test_'.$advance->public_id],
            $rest->public_id => ['110.00', 'paid', 'ch_test_'.$rest->public_id],
        ], $this->paymentRows());
        $this->assertEvents(['order.submitted', 'order.price_confirmed', 'payment_request.created', 'payment.succeeded', 'order.stage_changed',
            'payment_request.created', 'payment.succeeded', 'invoice.issued']);
        // Гость скачивает счёт со своей страницы (право в сессии).
        $this->visitor()->get($guestUrl)->assertOk()->assertSee(route('invoices.download', $invoice->id), false);
        $this->get(route('invoices.download', $invoice->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // 9. Контроль качества, готово, трек, shipped, completed.
        $this->card($order)->call('advance', 'quality_check')->call('advance', 'ready')
            ->call('advance', 'shipped')->assertSee(__('orders.workflow.tracking_required'));
        $this->assertSame(['ready', '150.00', '150.00', '0.00', 'paid', '', 'TEST-7'], $this->orderRow($number));
        $this->card($order)->set('carrier', 'DPD')->set('trackingNumber', 'TEST-TRACK-1')->call('setTracking')->assertSee('Tracking saved.');
        $this->assertSame(['ready', '150.00', '150.00', '0.00', 'paid', 'DPD TEST-TRACK-1', 'TEST-7'], $this->orderRow($number));
        $this->card($order)->call('advance', 'shipped')->assertHasNoErrors();
        $this->assertSame(['shipped', '150.00', '150.00', '0.00', 'paid', 'DPD TEST-TRACK-1', 'TEST-7'], $this->orderRow($number));
        $this->card($order)->call('advance', 'completed')->assertHasNoErrors();
        $this->assertSame(['completed', '150.00', '150.00', '0.00', 'paid', 'DPD TEST-TRACK-1', 'TEST-7'], $this->orderRow($number));
        $this->assertEvents(['order.submitted', 'order.price_confirmed', 'payment_request.created', 'payment.succeeded', 'order.stage_changed',
            'payment_request.created', 'payment.succeeded', 'invoice.issued',
            'order.stage_changed', 'order.stage_changed', 'order.tracking_set', 'order.stage_changed', 'order.stage_changed']);

        // Итог: два списания по 40 и 110 EUR, возвратов нет; Stripe вызывался только для создания двух intent.
        $this->assertSame([4000, 11000], Transaction::where('order_id', $order->id)->where('type', 'capture')->orderBy('id')->pluck('amount')->map(fn ($amount): int => (int) $amount->value)->all());
        $this->assertSame(0, Transaction::where('order_id', $order->id)->where('type', 'refund')->count());
        $this->assertSame(['create', 'create'], $this->stripe->operations());
        $this->visitor()->get($guestUrl)->assertOk()->assertSee('Tracking: DPD TEST-TRACK-1');
    }
}
