<?php

namespace Tests\Feature\Scenarios;

use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentRequest;
use App\Livewire\Admin\Orders\Card;
use App\Models\OrderCharge;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 * Сценарий 3 §9.3 (W-30, уровень L): регистрация клиента формой (W-07) → заявка из калькулятора (W-12) → подтверждение
 * 150 EUR и аванс 40 EUR, оплаченный через страницу оплаты (W-16) и подписанный webhook (W-28) → отмена заказа
 * администратором в карточке (W-14) → в карточке клиента и администратора «оплачено 40 EUR, отменён»; транзакции refund
 * нет (возврат вручную в Stripe, D-04), строка Orders фейка сетки — cancelled / 40.00.
 */
class ClientCancelAfterAdvanceScenarioTest extends TestCase
{
    use RefreshDatabase;
    use ScenarioSupport;
    use SignedStripeEvents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        $this->seed(PrototypeContentSeeder::class);
        Storage::fake('local');
        Mail::fake();
        Livewire::flushState();
        $this->connectSheets();
        $this->useWebhookSecret();
        config(['services.stripe.key' => 'test-stripe-publishable-not-real', 'services.stripe.secret' => 'test-stripe-secret-not-real']);
        $this->app->instance(StripeClientInterface::class, new FakeStripe);
    }

    public function test_registered_client_sees_paid_40_and_cancelled_without_a_refund(): void
    {
        $admin = $this->makeAdmin();

        // 1. Регистрация формой: клиент сразу вошёл.
        $this->get('/en/account/register')->assertOk();
        $this->post('/en/account/register', ['name' => 'Rūta TEST', 'email' => 'ruta@example.test', 'password' => 'long-enough-1'])
            ->assertRedirect(route('en.account'));
        $client = User::where('email', 'ruta@example.test')->sole();
        $this->assertFalse($client->is_admin);
        $this->assertAuthenticatedAs($client);

        // 2. Заявка из калькулятора от имени вошедшего клиента: заказ его, гостевой ссылки нет.
        $redirect = $this->post('/en/quote', [
            'submission_token' => (string) Str::uuid(),
            'name' => 'Rūta TEST', 'email' => 'ruta@example.test', 'phone' => '+370 622 22222', 'company' => '', 'company_code' => '', 'vat_code' => '',
            'delivery' => 'ship', 'country' => 'LT', 'city' => 'Kaunas', 'address' => 'Laisvės al. 1', 'postcode' => 'LT-44000',
            'material' => 'PLA', 'infill' => '100', 'color' => 'black', 'quality' => 'standard', 'layer' => '0.2', 'finish' => 'none', 'term' => 'standard',
            'parts' => [['file' => UploadedFile::fake()->createWithContent('bracket.stl', file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'))),
                'unit' => 'mm', 'scale' => '100', 'quantity' => '1']],
        ], ['Accept' => 'application/json'])->assertCreated()->json('redirect');
        $this->get($redirect)->assertOk()->assertSee('The order is saved in your account.')->assertDontSee('data-guest-link', false);
        $order = Order::sole();
        $this->assertSame($client->id, $order->user_id);
        $number = (string) OrderMeta::where('order_id', $order->id)->value('number');
        $clientCard = '/en/account/orders/'.$number;

        // 3. Администратор подтверждает 150 EUR (деталь 115, плата 5, доставка 30) и запрашивает аванс 40 EUR.
        [$part, $fee] = $order->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
            ->modelKeys();
        $flow = app(OrderWorkflow::class);
        $flow->confirmPrice($admin, $order, [$part => 11500, $fee => 500], 3000);
        $advance = $flow->requestPayment($admin, $order, 4000);

        // 4. Клиент платит аванс со своей карточки: страница оплаты создаёт intent, деньги подтверждает подписанный webhook.
        $checkout = route('en.checkout', $advance->public_id);
        $this->get($clientCard)->assertOk()->assertSee('href="'.$checkout.'"', false);
        $this->get($checkout)->assertOk()->assertSee('id="payment-element"', false);
        $this->sendStripe($this->succeededPayload($advance, 'evt_test_advance'))->assertOk();
        $this->assertSame(PaymentRequest::PAID, $advance->fresh()->state);
        $this->assertSame(['40.00', '110.00', 'partial'], [$this->sheets->row('Orders', $number)['Paid EUR'], $this->sheets->row('Orders', $number)['Due EUR'], $this->sheets->row('Orders', $number)['Payment state']]);

        // 5. Отмена администратором в карточке — со вторым нажатием и причиной.
        $card = Livewire::actingAs($admin)->test(Card::class, ['orderId' => $order->id]);
        $card->set('cancelReason', 'Customer withdrew')->call('cancelOrder')->assertSet('confirming', 'cancel');
        $this->assertSame(ProductionStage::Confirmed, ProductionState::where('order_id', $order->id)->value('stage'));
        $card->call('cancelOrder')->assertSee('Order cancelled.')->assertSee('Cancelled: Customer withdrew')->assertSee('€40.00');
        $this->assertSame(ProductionStage::Cancelled, ProductionState::where('order_id', $order->id)->value('stage'));

        // 6. Карточка клиента: оплачено 40 EUR, заказ отменён, оплатить больше нечего.
        $page = $this->actingAs($client)->get($clientCard)->assertOk()->assertSee('Cancelled')->assertDontSee('data-checkout-link', false);
        $html = Html::xpath($page->getContent());
        $this->assertSame('cancelled', $html->query('//*[@data-stage]/@data-stage')->item(0)->value);
        $this->assertSame('€40.00', trim($html->query('//*[@data-paid]')->item(0)->textContent));

        // Возврат не создан: одна транзакция capture на 40 EUR; в листе — cancelled, оплачено 40.00.
        $this->assertSame(0, Transaction::where('order_id', $order->id)->where('type', 'refund')->count());
        $this->assertSame([4000], Transaction::where('order_id', $order->id)->pluck('amount')->map(fn ($amount): int => (int) $amount->value)->all());
        $this->assertSame(4000, $flow->balance($order->fresh())['paid']);
        $row = $this->sheets->row('Orders', $number);
        $this->assertSame(['cancelled', '150.00', '40.00'], [$row['Production stage'], $row['Confirmed price EUR'], $row['Paid EUR']]);
        $this->assertContains('order.cancelled', $this->eventTypes());
        $this->assertNotContains('payment.refunded', $this->eventTypes());
    }
}
