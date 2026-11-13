<?php

namespace Tests\Feature\Scenarios;

use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Invoices\InvoiceNumber;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Invoices\SellerSettings;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\ProductCart;
use App\Domain\Payments\Models\PaymentRequest;
use App\Livewire\Admin\Orders\Card;
use App\Mail\FormSubmitted;
use App\Mail\OrderPaid;
use App\Mail\OrderShipped;
use App\Models\OrderCharge;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Stripe\StripeClientInterface;
use Tests\browser\ContentFactory;
use Tests\Support\FakeStripe;
use Tests\Support\Html;
use Tests\Support\SignedStripeEvents;
use Tests\TestCase;

/**
 * Правка 12.11, K-03 — сквозная проверка писем клиенту через страницы сайта: заказ калькулятора клиента кабинета (LT) →
 * подтверждение цены в карточке → запрос оплаты → страница оплаты (FakeStripe) → подписанный webhook → письмо шаблона 2
 * с PDF счёта и кнопкой кабинета → трек-номер в карточке → письмо отправки; то же для заказа товаров гостем (EN, шаблон 1,
 * без кнопки). Номера двух новых заказов — случайные 8-значные, не соседние (K-05). Почта — Mail::fake(), Stripe не вызывается.
 */
class CustomerOrderMailScenarioTest extends TestCase
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
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(PrototypeContentSeeder::class);
        Mail::fake();
        Livewire::flushState();
        $this->admin = $this->makeAdmin();
        $factory = ContentFactory::as($this->admin);
        $factory->catalog();
        $factory->saleProduct();
        $this->visitor();
        $this->useWebhookSecret();
        config(['services.stripe.key' => 'test-stripe-publishable-not-real', 'services.stripe.secret' => 'test-stripe-secret-not-real']);
        $this->stripe = new FakeStripe;
        $this->app->instance(StripeClientInterface::class, $this->stripe);
        app(SellerSettings::class)->save(['name' => 'TEST UAB', 'company_code' => 'TEST-000000', 'vat_code' => 'TEST-LT000000', 'address' => 'TEST g. 1, Vilnius',
            'vat_payer' => true, 'vat_rate' => 21, 'vat_note' => null, 'series' => 'TEST']);
        InvoiceNumber::setNext('TEST', 7);
        ProductCart::saveDeliveryCents(500);
        ProductCart::saveDeliveryTime(['lt' => '2–3 darbo dienos', 'en' => '2–3 working days']);
    }

    private function card(Order $order): Testable
    {
        $this->visitor();

        return Livewire::actingAs($this->admin)->test(Card::class, ['orderId' => $order->id]);
    }

    /** Покупатель открывает страницу оплаты (intent FakeStripe), Stripe присылает подписанный succeeded. */
    private function pays(PaymentRequest $request, string $checkoutRoute, string $eventId): void
    {
        $this->get(route($checkoutRoute, $request->public_id))->assertOk()->assertSee('id="payment-element"', false);
        $this->sendStripe($this->succeededPayload($request->fresh(), $eventId))->assertOk();
        $this->assertSame(PaymentRequest::PAID, $request->fresh()->state);
    }

    /** @return list<OrderPaid> */
    private function paidMails(): array
    {
        return Mail::sent(OrderPaid::class)->values()->all();
    }

    /** @return list<OrderShipped> */
    private function shippedMails(): array
    {
        return Mail::sent(OrderShipped::class)->values()->all();
    }

    private function assertInvoiceAttached(OrderPaid $mail, Invoice $invoice): void
    {
        $this->assertCount(1, $mail->attachments());
        $this->assertTrue($mail->hasAttachment(Attachment::fromStorageDisk('local', $invoice->pdf_path)->as($invoice->label().'.pdf')->withMime('application/pdf')));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($invoice->pdf_path));
    }

    public function test_calculator_and_products_orders_end_with_both_customer_mails_and_random_order_numbers(): void
    {
        // 1. Клиент кабинета отправляет калькулятор (LT, доставка; самовывозу трек-номер не задаётся).
        $client = User::factory()->create(['email' => 'jonas.account@example.test']);
        $this->actingAs($client);
        $cube = file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));
        $this->post('/lt/kalkuliatorius', [
            'submission_token' => (string) Str::uuid(),
            'name' => 'Jonas TEST', 'email' => 'jonas@example.test', 'phone' => '+370 600 11111', 'company' => '', 'company_code' => '', 'vat_code' => '',
            'delivery' => 'ship', 'country' => 'LT', 'city' => 'Kaunas', 'address' => 'Laisvės al. 1', 'postcode' => 'LT-44001',
            'parts' => [['file' => UploadedFile::fake()->createWithContent('bracket.stl', $cube), 'unit' => 'mm', 'scale' => '100', 'quantity' => '2',
                'method' => 'printing', 'material' => 'PLA', 'infill' => '100', 'color' => 'black', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'priority' => 'standard']],
        ], ['Accept' => 'application/json'])->assertCreated();
        $calculator = Order::sole();
        $calculatorNumber = OrderMeta::where('order_id', $calculator->id)->value('number');
        $this->assertSame([$client->id, 'lt'], [$calculator->user_id, OrderMeta::where('order_id', $calculator->id)->value('locale')]);
        // Оформление — без письма клиенту (только info@).
        $this->assertSame([], $this->paidMails());
        Mail::assertSent(FormSubmitted::class, 1);

        // 2. Администратор подтверждает цену 75 € (деталь 60 + плата 5 + доставка 10) и запрашивает всю сумму.
        [$part, $fee] = $calculator->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
            ->modelKeys();
        $this->card($calculator)->set("prices.$part", '60')->set("prices.$fee", '5')->set('delivery', '10')->call('confirmPrice')->assertHasNoErrors();
        $this->assertSame([], $this->paidMails(), 'подтверждение цены писем клиенту не даёт');
        $this->card($calculator)->set('amount', '75')->call('requestPayment')->assertHasNoErrors();
        $this->assertSame([], $this->paidMails(), 'запрос оплаты писем клиенту не даёт');
        $request = PaymentRequest::sole();

        // 3. Клиент платит из кабинета → счёт выпущен → письмо шаблона 2 с PDF и кнопкой кабинета.
        $this->visitor()->actingAs($client);
        $this->pays($request, 'lt.checkout', 'evt_k03_calc');
        $invoice = Invoice::sole();
        $mails = $this->paidMails();
        $this->assertCount(1, $mails);
        $mail = $mails[0];
        $this->assertTrue($mail->hasTo('jonas@example.test'));
        $this->assertTrue($mail->hasReplyTo('info@aiqme.com'));
        $this->assertTrue($mail->hasSubject('AIQME užsakymas #'.$calculatorNumber.' gautas'));
        $this->assertInvoiceAttached($mail, $invoice);
        $page = Html::xpath($html = $mail->render());
        $this->assertSame('Dėkojame, kad pasirinkote mus!', Html::text($page->query('//h1')->item(0)));
        $this->assertSame('Užsakymas #'.$calculatorNumber.' | '.$calculator->placed_at->format('d.m.Y'), Html::text($page->query('//h2')->item(0)));
        $this->assertSame(route('lt.account.orders.show', $calculatorNumber), $page->query('//a[normalize-space()="Peržiūrėti užsakymą"]')->item(0)?->getAttribute('href'));
        $this->assertSame(['IŠ VISO SU PVM:', '75.00 €'], Html::texts($page, '//tr[@data-grand]/td'));
        $this->assertSame([7500, 7500], [$invoice->total, $calculator->fresh()->total->value]);
        $this->assertSame('Pristatymas', Html::texts($page, '//tr[@data-line][last()]/td')[0]);
        $this->assertStringContainsString('Pristatymas visoje ES', $html);
        $this->assertStringContainsString('Laisvės al. 1, Kaunas, LT-44001, Lietuva', $html);
        $this->assertSame(['Numatomas terminas:', '2–3 darbo dienos'], Html::texts($page, '//tr[@data-delivery-time]/td'));
        // Ссылка письма открывает заказ в кабинете клиента.
        $this->get(route('lt.account.orders.show', $calculatorNumber))->assertOk()->assertSee($calculatorNumber);

        // 4. Трек-номер в карточке → письмо отправки (LT, с кнопкой кабинета).
        $this->card($calculator)->set('carrier', 'DPD')->set('trackingNumber', 'TEST-K03-1')->call('setTracking')->assertHasNoErrors();
        $shipped = $this->shippedMails();
        $this->assertCount(1, $shipped);
        $this->assertTrue($shipped[0]->hasTo('jonas@example.test'));
        $this->assertTrue($shipped[0]->hasSubject('AIQME užsakymas #'.$calculatorNumber.' išsiųstas'));
        $shippedPage = Html::xpath($shippedHtml = $shipped[0]->render());
        $this->assertSame('Jūsų užsakymas išsiųstas!', Html::text($shippedPage->query('//h1')->item(0)));
        $this->assertSame(['Vežėjas:', 'DPD', 'Siuntos numeris:', 'TEST-K03-1'], Html::texts($shippedPage, '//h2/following-sibling::table[1]//td[not(table)]'));
        $this->assertStringContainsString('Užsakymas #'.$calculatorNumber.' išsiųstas.', $shippedHtml);
        $this->assertSame(1, $shippedPage->query('//a[normalize-space()="Peržiūrėti užsakymą"]')->length);

        // 5. Гость покупает товары (EN, доставка) → страница оплаты → webhook → письмо шаблона 1 с PDF, без кнопки.
        $this->visitor();
        $this->post('/en/cart', ['product' => 'pla-filament', 'quantity' => 2]);
        $this->post('/en/cart/checkout', [
            'name' => 'Ona TEST', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => '', 'company_code' => '',
            'vat_code' => '', 'delivery' => 'ship', 'country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gedimino pr. 1', 'postcode' => 'LT-01103',
        ])->assertRedirect();
        $products = Order::where('id', '!=', $calculator->id)->sole();
        $productsNumber = OrderMeta::where('order_id', $products->id)->value('number');
        $this->assertNull($products->user_id);
        $this->assertCount(1, $this->paidMails(), 'оформление корзины — без письма клиенту');
        $productsRequest = PaymentRequest::where('order_id', $products->id)->sole();
        $this->pays($productsRequest, 'en.checkout', 'evt_k03_products');
        $productsInvoice = Invoice::where('order_id', $products->id)->sole();
        $mails = $this->paidMails();
        $this->assertCount(2, $mails);
        $mail = $mails[1];
        $this->assertTrue($mail->hasTo('ona@example.test'));
        $this->assertTrue($mail->hasSubject('AIQME order #'.$productsNumber.' received'));
        $this->assertInvoiceAttached($mail, $productsInvoice);
        $page = Html::xpath($html = $mail->render());
        $this->assertSame('Thank you for shopping with us!', Html::text($page->query('//h1')->item(0)));
        $this->assertSame('Order #'.$productsNumber.' | '.$products->placed_at->format('d.m.Y'), Html::text($page->query('//h2')->item(0)));
        $this->assertSame(['PLA filament', 'Delivery'], array_map(fn ($row): string => Html::texts($page, './td', $row)[0], iterator_to_array($page->query('//tr[@data-line]'))));
        $this->assertSame(['TOTAL INCL. VAT:', '53.00 €'], Html::texts($page, '//tr[@data-grand]/td'));
        $this->assertSame(['Estimated delivery time:', '2–3 working days'], Html::texts($page, '//tr[@data-delivery-time]/td'));
        $this->assertSame(0, $page->query('//a[normalize-space()="View order"]')->length);
        $this->assertStringNotContainsString('/account/', $html);

        // 6. Трек-номер заказа товаров → письмо отправки EN без кнопки.
        $this->card($products)->set('carrier', 'Omniva')->set('trackingNumber', 'CC123456789EE')->call('setTracking')->assertHasNoErrors();
        $shipped = $this->shippedMails();
        $this->assertCount(2, $shipped);
        $this->assertTrue($shipped[1]->hasTo('ona@example.test'));
        $this->assertTrue($shipped[1]->hasSubject('AIQME order #'.$productsNumber.' shipped'));
        $shippedHtml = $shipped[1]->render();
        $this->assertStringContainsString('Your order has been shipped!', $shippedHtml);
        $this->assertStringContainsString('CC123456789EE', $shippedHtml);
        $this->assertStringNotContainsString('View order', $shippedHtml);

        // Итог: по одному письму каждого вида на заказ, все доставлены через outbox; info@ — только письма оформления.
        $this->assertSame(['sent', 'sent'], IntegrationEvent::where('type', OrderPaid::EVENT)->orderBy('id')->pluck('mail_status')->all());
        $this->assertSame(['sent', 'sent'], IntegrationEvent::where('type', OrderShipped::EVENT)->orderBy('id')->pluck('mail_status')->all());
        Mail::assertSent(FormSubmitted::class, 2);
        Mail::assertNotSent(OrderPaid::class, fn (OrderPaid $mail): bool => $mail->hasTo('info@aiqme.com'));

        // K-05: два новых заказа — случайные 8-значные номера, не соседние и не порядковые.
        foreach ([$calculatorNumber, $productsNumber] as $number) {
            $this->assertMatchesRegularExpression('/^[1-9]\d{7}$/', (string) $number);
        }
        $this->assertGreaterThan(1, abs((int) $calculatorNumber - (int) $productsNumber));
        $this->assertSame(2, count($this->stripe->operations()));
    }
}
