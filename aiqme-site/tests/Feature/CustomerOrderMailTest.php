<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Invoices\InvoiceNumber;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Invoices\SellerSettings;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\OrderSubmission;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductCart;
use App\Domain\Payments\Models\PaymentRequest;
use App\Livewire\Admin\Settings;
use App\Mail\FormSubmitted;
use App\Mail\OrderPaid;
use App\Models\OrderCharge;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\browser\ContentFactory;
use Tests\Support\Html;
use Tests\Support\SignedStripeEvents;
use Tests\TestCase;

/**
 * Правка 12.11, K-01 — письмо клиенту после первой оплаты заказа по шаблонам заказчика: товары — шаблон 1, калькулятор —
 * шаблон 2; язык заказа; кнопка кабинета только клиенту кабинета; позиции и итоги как в счёте; PDF выпущенного счёта во
 * вложении; одно письмо на заказ (повтор webhook, вторая оплата, retry); сбой почты не отменяет оплату и счёт, повтор
 * доставляет; письма на info@aiqme.com при оплате нет; настройка «Numatomas terminas» LT/EN. Оплата — подписанный webhook
 * (Stripe не вызывается), почта — Mail::fake() или транспорт array.
 */
class CustomerOrderMailTest extends TestCase
{
    use RefreshDatabase;
    use SignedStripeEvents;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(PrototypeContentSeeder::class);
        $this->admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $this->admin->save();
        $factory = ContentFactory::as($this->admin);
        $factory->catalog();
        $factory->saleProduct();
        auth()->logout();
        $this->useWebhookSecret();
    }

    /** Тестовые реквизиты продавца (TEST), плательщик VAT 21 %. */
    private function seller(array $overrides = []): void
    {
        app(SellerSettings::class)->save([
            'name' => 'TEST UAB', 'company_code' => 'TEST-000000', 'vat_code' => 'TEST-LT000000', 'address' => 'TEST g. 1, Vilnius',
            'vat_payer' => true, 'vat_rate' => 21, 'vat_note' => null, 'series' => 'TEST', ...$overrides,
        ]);
        InvoiceNumber::setNext('TEST', 7);
    }

    /** Заказ товаров из корзины: 2 × PLA gija по 24 € + доставка 5 € = 53 €. */
    private function productOrder(string $locale = 'lt'): Order
    {
        ProductCart::saveDeliveryCents(500);
        $this->post($locale === 'lt' ? '/lt/krepselis' : '/en/cart', ['product' => $locale === 'lt' ? 'pla-gija' : 'pla-filament', 'quantity' => 2]);
        $this->post($locale === 'lt' ? '/lt/krepselis/uzsakymas' : '/en/cart/checkout', [
            'name' => 'Ona TEST', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => '', 'company_code' => '',
            'vat_code' => '', 'delivery' => 'ship', 'country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gedimino pr. 1', 'postcode' => 'LT-01103',
        ])->assertRedirect();

        return Order::latest('id')->firstOrFail();
    }

    /** Заказ калькулятора (W-12) с подтверждённой ценой: детали 30 + 30 €, плата 5 €, без доставки (самовывоз) = 65 €. */
    private function calculatorOrder(?User $user, string $locale = 'en'): Order
    {
        $cube = file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));
        $order = app(OrderSubmission::class)->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => $locale,
            'contact' => ['name' => 'Jonas TEST', 'email' => 'jonas@example.test', 'phone' => '+370 600 11111', 'company' => 'TEST UAB klientas', 'company_code' => null, 'vat_code' => null],
            'delivery' => 'pickup',
            'address' => null,
            'parts' => array_map(fn (string $name): array => [
                'file' => UploadedFile::fake()->createWithContent($name, $cube), 'parameters' => ['method' => 'printing', 'material' => 'PLA', 'infill' => 100, 'infill_consult' => false, 'unit' => 'mm', 'scale' => 100, 'quantity' => 2, 'color' => 'black', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'term' => 'standard', 'needed_by' => null, 'notes' => null],
            ], ['bracket.stl', 'cover.stl']),
        ], $user)->order;
        $ids = $order->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
            ->modelKeys();
        $prices = array_fill_keys($ids, 3000);
        $prices[end($ids)] = 500;
        app(OrderWorkflow::class)->confirmPrice($this->admin, $order, $prices, null);

        return $order;
    }

    /** Оплата подписанным webhook: запрос (имеющийся открытый или новый на $amount) с intent и payment_intent.succeeded. */
    private function pay(Order $order, ?int $amount, string $eventId): PaymentRequest
    {
        $request = $amount === null
            ? PaymentRequest::where('order_id', $order->id)->where('state', PaymentRequest::PENDING)->firstOrFail()
            : app(OrderWorkflow::class)->requestPayment($this->admin, $order, $amount);
        $request->update(['stripe_intent_id' => 'pi_test_'.$request->id]);
        $this->sendStripe($this->succeededPayload($request, $eventId))->assertOk();

        return $request;
    }

    /** @return list<OrderPaid> */
    private function sentOrderMails(): array
    {
        return Mail::sent(OrderPaid::class)->values()->all();
    }

    public function test_paid_products_order_sends_template_one_in_lithuanian_with_the_invoice_pdf_once(): void
    {
        Mail::fake();
        $this->seller();
        ProductCart::saveDeliveryTime(['lt' => '2–3 darbo dienos', 'en' => '2–3 working days']);
        $order = $this->productOrder('lt');
        $number = OrderMeta::where('order_id', $order->id)->value('number');
        // Письмо при оформлении — только на info@ (заказ ещё не оплачен), клиенту — нет.
        $this->assertSame([], $this->sentOrderMails());
        Mail::assertSent(FormSubmitted::class, 1);

        $request = $this->pay($order, null, 'evt_paid_1');
        $invoice = Invoice::sole();
        $mails = $this->sentOrderMails();
        $this->assertCount(1, $mails);
        $mail = $mails[0];
        $this->assertTrue($mail->hasTo('ona@example.test'));
        $this->assertFalse($mail->hasTo('info@aiqme.com'));
        $this->assertTrue($mail->hasReplyTo('info@aiqme.com'));
        $this->assertTrue($mail->hasSubject('AIQME užsakymas #'.$number.' gautas'));
        $this->assertTrue($mail->hasAttachment(Attachment::fromStorageDisk('local', $invoice->pdf_path)->as($invoice->label().'.pdf')->withMime('application/pdf')));
        $this->assertCount(1, $mail->attachments());
        // Письмо на info@aiqme.com при оплате не уходит (§5.1): FormSubmitted — по-прежнему одно, от оформления.
        Mail::assertSent(FormSubmitted::class, 1);

        $html = $mail->render();
        $page = Html::xpath($html);
        $this->assertSame('Ačiū, kad perkate iš mūsų!', Html::text($page->query('//h1')->item(0)));
        $this->assertStringContainsString('Sveiki, Ona TEST,', $html);
        $this->assertStringContainsString('Gavome Jūsų užsakymą ir apmokėjimą.', $html);
        $this->assertStringNotContainsString('Peržiūrėti užsakymą', $html);
        $this->assertStringNotContainsString('/paskyra/', $html);
        $this->assertSame('Užsakymas #'.$number.' | '.$order->placed_at->format('d.m.Y'), Html::text($page->query('//h2')->item(0)));
        // Позиции — как в счёте: цена без VAT (24 € брутто → 19.83 €), доставка — строкой «Pristatymas».
        $net = fn (int $gross): int => $gross - InvoiceService::vat($gross, 21);
        $money = fn (int $cents): string => number_format($cents / 100, 2, '.', '').' €';
        $this->assertSame([
            ['PLA gija', '2.00 vnt.', $money((int) round($net(4800) / 2)), $money($net(4800))],
            ['Pristatymas', '1.00 vnt.', $money($net(500)), $money($net(500))],
        ], array_map(fn ($row): array => Html::texts($page, './td', $row), iterator_to_array($page->query('//tr[@data-line]'))));
        $this->assertSame(['Produktas', 'Kiekis', 'Kaina', 'Suma'], Html::texts($page, '//th'));
        $this->assertStringContainsString('IŠ VISO:', $html);
        $this->assertStringContainsString($money($invoice->net_total), $html);
        $this->assertStringContainsString('PVM 21 %:', $html);
        $this->assertStringContainsString($money($invoice->vat_total), $html);
        $this->assertSame(['IŠ VISO SU PVM:', '53.00 €'], Html::texts($page, '//tr[@data-grand]/td'));
        $this->assertStringContainsString('Pristatymas visoje ES', $html);
        $this->assertStringContainsString('Gedimino pr. 1, Vilnius, LT-01103, Lietuva', $html);
        $this->assertSame(['Numatomas terminas:', '2–3 darbo dienos'], Html::texts($page, '//tr[@data-delivery-time]/td'));
        $this->assertStringContainsString('ona@example.test  |  +370 600 00000', $html);
        foreach (['Pirkėjo informacija', 'Kas toliau?', 'Kai užsakymas bus išsiųstas, atsiųsime siuntos sekimo informaciją.',
            'Jei turite klausimų, susisiekite su mumis:', 'info@aiqme.com  |  +370 688 84 084', 'Pagarbiai,', 'AIQME komanda',
            'aiqme.com  •  TEST g. 1, Vilnius', url('defaults/logo-white.png')] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<link', $html);
        // Текстовая версия — с теми же данными.
        $text = view('mail.order-paid-text', ['mail' => $mail->data()])->render();
        foreach (['Ačiū, kad perkate iš mūsų!', 'Užsakymas #'.$number, 'PLA gija | 2.00 vnt.', 'IŠ VISO SU PVM: 53.00 €', 'Numatomas terminas: 2–3 darbo dienos'] as $part) {
            $this->assertStringContainsString($part, $text);
        }

        // Повтор webhook и повтор outbox — без второго письма; канал sent.
        $this->sendStripe($this->succeededPayload($request, 'evt_paid_1'))->assertOk();
        $this->sendStripe($this->succeededPayload($request, 'evt_paid_1_again'))->assertOk();
        $this->travel(10)->minutes();
        app(IntegrationEvents::class)->retry();
        $this->assertCount(1, $this->sentOrderMails());
        $event = IntegrationEvent::where('type', OrderPaid::EVENT)->sole();
        $this->assertSame(['sent', 1, $order->id, 'ona@example.test'], [$event->mail_status, $event->mail_attempts, $event->entity_id, $event->data['email']]);
    }

    public function test_paid_calculator_order_of_a_customer_sends_template_two_in_english_with_the_account_link_and_one_mail_for_two_payments(): void
    {
        Mail::fake();
        $this->seller();
        $client = User::factory()->create();
        $order = $this->calculatorOrder($client, 'en');
        $number = OrderMeta::where('order_id', $order->id)->value('number');

        // Частичная оплата: счёта нет — письмо без вложения.
        $this->pay($order, 3000, 'evt_part_1');
        $this->assertSame(0, Invoice::count());
        $mails = $this->sentOrderMails();
        $this->assertCount(1, $mails);
        $this->assertSame([], $mails[0]->attachments());
        $this->assertTrue($mails[0]->hasTo('jonas@example.test'));
        $this->assertTrue($mails[0]->hasSubject('AIQME order #'.$number.' received'));
        $html = $mails[0]->render();
        $page = Html::xpath($html);
        $this->assertSame('Thank you for choosing us!', Html::text($page->query('//h1')->item(0)));
        $this->assertStringContainsString('Hello, Jonas TEST,', $html);
        $this->assertStringContainsString('We have received your order and payment.', $html);
        $link = $page->query('//a[normalize-space()="View order"]')->item(0);
        $this->assertNotNull($link);
        $this->assertSame(route('en.account.orders.show', $number), $link->getAttribute('href'));
        $this->assertSame(['Product', 'Qty', 'Price', 'Total'], Html::texts($page, '//th'));
        $this->assertCount(3, $page->query('//tr[@data-line]'));
        $this->assertSame(['bracket.stl — 3D printing, PLA', '2.00 pcs.'], array_slice(Html::texts($page, '//tr[@data-line][1]/td'), 0, 2));
        $this->assertSame(['TOTAL INCL. VAT:', '65.00 €'], Html::texts($page, '//tr[@data-grand]/td'));
        foreach (['TOTAL:', 'VAT 21 %:', 'Delivery information', 'Delivery method:', 'Collection', 'Delivery address:', 'TEST g. 1, Vilnius',
            'Buyer information', 'TEST UAB klientas', 'jonas@example.test  |  +370 600 11111', "What's next?",
            'When your order is shipped, we will send you the tracking information.', 'If you have any questions, contact us:',
            'Kind regards,', 'AIQME team'] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }
        // «Numatomas terminas» не задан — строки нет.
        $this->assertSame(0, $page->query('//tr[@data-delivery-time]')->length);
        $this->assertStringNotContainsString('Estimated delivery time', $html);

        // Вторая оплата закрывает заказ и выпускает счёт — второго письма нет.
        $this->pay($order, 3500, 'evt_part_2');
        $this->assertSame(1, Invoice::count());
        $this->assertCount(1, $this->sentOrderMails());
        $this->assertSame(1, IntegrationEvent::where('type', OrderPaid::EVENT)->count());
    }

    public function test_without_seller_details_or_for_a_non_vat_payer_the_mail_shows_only_the_order_total(): void
    {
        Mail::fake();
        $order = $this->calculatorOrder(null, 'lt');
        $this->pay($order, 6500, 'evt_full');
        $this->assertSame(0, Invoice::count());
        $mail = $this->sentOrderMails()[0];
        $this->assertSame([], $mail->attachments());
        $page = Html::xpath($html = $mail->render());
        $this->assertSame('Dėkojame, kad pasirinkote mus!', Html::text($page->query('//h1')->item(0)));
        $this->assertSame(['IŠ VISO SU PVM:', '65.00 €'], Html::texts($page, '//tr[@data-grand]/td'));
        $this->assertStringNotContainsString('PVM 21', $html);
        $this->assertStringNotContainsString('IŠ VISO:', $html);
        // Гость — без кнопки кабинета; самовывоз без реквизитов — адрес из шаблона.
        $this->assertStringNotContainsString('Peržiūrėti užsakymą', $html);
        $this->assertStringContainsString('Atsiėmimas', $html);
        $this->assertStringContainsString('Metalo g. 4, LT-02190 Vilnius', $html);

        $this->seller(['vat_payer' => false, 'vat_rate' => 0, 'vat_code' => null, 'vat_note' => 'TEST ne PVM mokėtojas']);
        $page = Html::xpath($html = (new OrderPaid($order->fresh()))->render());
        $this->assertSame(['IŠ VISO:', '65.00 €'], Html::texts($page, '//tr[@data-grand]/td'));
        $this->assertStringNotContainsString('PVM', $html);
        $this->assertSame('30.00 €', Html::texts($page, '//tr[@data-line][1]/td')[3]);
    }

    public function test_mail_failure_keeps_payment_and_invoice_marks_the_channel_failed_and_the_retry_delivers_once(): void
    {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection refused (test transport)');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
        $this->seller();
        $order = $this->productOrder('en');
        $request = $this->pay($order, null, 'evt_fail');

        $this->assertSame(PaymentRequest::PAID, $request->fresh()->state);
        $invoice = Invoice::sole();
        $event = IntegrationEvent::where('type', OrderPaid::EVENT)->sole();
        $this->assertSame(['failed', 1], [$event->mail_status, $event->mail_attempts]);
        $this->assertStringContainsString('Connection refused', $event->mail_error);

        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();
        $this->travel(6)->minutes();
        $this->assertGreaterThanOrEqual(1, app(IntegrationEvents::class)->retry());
        $this->travel(6)->minutes();
        app(IntegrationEvents::class)->retry();
        /** @var ArrayTransport $transport */
        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();
        $sent = $transport->messages()->filter(fn ($message): bool => $message->getEnvelope()->getRecipients()[0]->getAddress() === 'jonas@example.test'
            || $message->getEnvelope()->getRecipients()[0]->getAddress() === 'ona@example.test');
        $this->assertCount(1, $sent);
        $email = $sent->first()->getOriginalMessage();
        $this->assertSame('AIQME order #'.OrderMeta::where('order_id', $order->id)->value('number').' received', $email->getSubject());
        $this->assertSame([$invoice->label().'.pdf'], array_map(fn ($part): string => $part->getFilename(), $email->getAttachments()));
        $this->assertStringContainsString('Thank you for shopping with us!', $email->getTextBody());
        $this->assertSame(['sent', 2, null], [$event->fresh()->mail_status, $event->fresh()->mail_attempts, $event->fresh()->mail_error]);
    }

    public function test_settings_have_the_estimated_delivery_time_per_language_next_to_the_product_delivery_price(): void
    {
        $this->actingAs($this->admin);
        $this->seller();
        app()->setLocale('lt');
        $form = Livewire::test(Settings::class)->assertSeeInOrder(['Pristatymo kaina (prekėms), €', 'Numatomas terminas LT', 'Numatomas terminas EN'])
            ->assertSet('deliveryTimeLt', '')->assertSet('deliveryTimeEn', '');
        $this->assertNull(ProductCart::deliveryTime('lt'));
        $form->set('deliveryTimeLt', ' 2–3 darbo dienos ')->set('deliveryTimeEn', '2–3 working days')->set('productDelivery', '6,50')->call('save')->assertHasNoErrors();
        $this->assertSame(['2–3 darbo dienos', '2–3 working days', 650], [ProductCart::deliveryTime('lt'), ProductCart::deliveryTime('en'), ProductCart::deliveryCents()]);
        Livewire::test(Settings::class)->assertSet('deliveryTimeLt', '2–3 darbo dienos')->assertSet('deliveryTimeEn', '2–3 working days')
            ->set('deliveryTimeEn', '')->call('save')->assertHasNoErrors();
        $this->assertSame(['2–3 darbo dienos', null, 650], [ProductCart::deliveryTime('lt'), ProductCart::deliveryTime('en'), ProductCart::deliveryCents()]);
        $form->set('deliveryTimeLt', str_repeat('x', 256))->call('save')->assertHasErrors('deliveryTimeLt');
    }
}
