<?php

namespace Tests\Feature;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Invoices\InvoiceNumber;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Invoices\SellerSettings;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\ProductCart;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentRequest;
use App\Livewire\Admin\Settings;
use App\Mail\FormSubmitted;
use App\Models\OrderCharge;
use App\Models\ProductLine;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Lunar\Models\Order;
use Stripe\StripeClientInterface;
use Tests\browser\ContentFactory;
use Tests\Support\FakeStripe;
use Tests\Support\Html;
use Tests\Support\SignedStripeEvents;
use Tests\TestCase;

/**
 * Правка 05.11, H-08 — корзина и покупка товаров «Pirkti»: корзина в сессии (гостю тоже) с количеством, удалением, итогом
 * и значком в шапке; товар «Gauti pasiūlymą» в корзину не попадает; оформление с полями и проверками калькулятора
 * (телефон обязателен) и доставкой по настройке «Pristatymo kaina (prekėms)» (по умолчанию 0 €); заказ «Prekės» общего
 * журнала с подтверждённой ценой и платёжным запросом на всю сумму, переход на страницу оплаты (Stripe — FakeStripe,
 * без сети), счёт после подписанного webhook; позиции-товары в админке, кабинете и письме; снятый с продажи товар
 * убирается из корзины при оформлении с сообщением.
 */
class ProductPurchaseTest extends TestCase
{
    use RefreshDatabase;
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
        $this->admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $this->admin->save();
        $factory = ContentFactory::as($this->admin);
        $factory->catalog();
        $factory->saleProduct();
        auth()->logout();
        $this->stripe = new FakeStripe;
        $this->app->instance(StripeClientInterface::class, $this->stripe);
        config(['services.stripe.key' => 'test-stripe-publishable-not-real', 'services.stripe.secret' => 'test-stripe-secret-not-real']);
        $this->useWebhookSecret();
    }

    private function pla(): ContentItem
    {
        return ContentItem::where('type', ContentType::Product)->where('key', 'vekta-pla')->firstOrFail();
    }

    /** @return array<string, string> */
    private function buyer(array $overrides = []): array
    {
        return [
            'name' => 'Ona TEST', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => '', 'company_code' => '',
            'vat_code' => '', 'delivery' => 'ship', 'country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gedimino pr. 1',
            'postcode' => 'LT-01103', ...$overrides,
        ];
    }

    private function badge(string $url): ?string
    {
        $node = Html::xpath($this->get($url)->assertOk()->getContent())->query('//header//a[contains(@class,"icon-btn")]//small[@data-cart-count]')->item(0);

        return $node === null ? null : Html::text($node);
    }

    public function test_guest_cart_adds_changes_removes_and_shows_total_and_badge(): void
    {
        $pla = $this->pla();
        $this->assertNull($this->badge('/lt/produktai'));
        $this->post('/lt/krepselis', ['product' => 'pla-gija', 'quantity' => 2])->assertRedirect(route('lt.cart'));
        $this->assertSame('2', $this->badge('/lt/produktai'));

        $page = Html::xpath($this->get('/lt/krepselis')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent());
        $this->assertSame(['Produktas', 'Kiekis', 'Kaina', ''], Html::texts($page, '//table/thead/tr/th'));
        $this->assertSame('PLA gija', Html::text($page->query('//tr[@data-cart-line]//a[contains(@class,"cart-product")]')->item(0)));
        $this->assertSame('2', $page->query('//tr[@data-cart-line]//input[@name="quantity" and @type="number"]')->item(0)->getAttribute('value'));
        $this->assertSame('48,00 €', Html::text($page->query('//td[@data-line-total]')->item(0)));
        // Доставка товаров по умолчанию 0 €, итог — сумма товаров.
        $this->assertSame(['PLA gija × 2', '48,00 €', 'Pristatymas', '0,00 €', 'Iš viso', '48,00 €'], Html::texts($page, '//aside[contains(@class,"quote-summary")]/div/*'));
        $this->assertSame(['Tęsti apsipirkimą', 'Pereiti prie apmokėjimo'], Html::texts($page, '//form[@id="checkout-form"]//div[@class="quote-actions"]/*'));
        $this->assertSame(1, $page->query('//form[@id="checkout-form"]//input[@name="phone" and @required]')->length);

        // Количество — новое значение; позиция не из корзины не добавляется; 0 — удаление.
        $this->post('/lt/krepselis/kiekis', ['item' => $pla->id, 'quantity' => 3])->assertRedirect(route('lt.cart'));
        $this->post('/lt/krepselis/kiekis', ['item' => $pla->id + 1000, 'quantity' => 3])->assertRedirect(route('lt.cart'));
        $this->post('/lt/krepselis/kiekis', ['item' => $pla->id, 'quantity' => 101])->assertSessionHasErrors('quantity');
        $this->assertSame([$pla->id => 3], session(ProductCart::SESSION));
        $this->get('/en/cart')->assertOk()->assertSee('PLA filament × 3')->assertSee('€72.00');
        $this->assertSame('3', $this->badge('/en/products'));
        $this->post('/en/cart/quantity', ['item' => $pla->id, 'quantity' => 0])->assertRedirect(route('en.cart'));
        $this->get('/en/cart')->assertOk()->assertSee('Your basket is empty')->assertDontSee('checkout-form');
        $this->assertNull($this->badge('/en/products'));
    }

    public function test_quote_products_never_get_into_the_cart_even_by_direct_post(): void
    {
        foreach ([['/lt/krepselis', 'petg-gija'], ['/en/cart', 'petg-filament'], ['/en/cart', 'vekta-p2-printer']] as [$url, $slug]) {
            $this->post($url, ['product' => $slug, 'quantity' => 1])->assertNotFound();
        }
        $this->assertSame([], session(ProductCart::SESSION, []));
        // Подделанная сессия с товаром «Gauti pasiūlymą» в корзине — его нет ни в корзине, ни в заказе.
        $petg = ContentItem::where('key', 'vekta-petg')->firstOrFail();
        $this->withSession([ProductCart::SESSION => [$petg->id => 1]])->post('/lt/krepselis/uzsakymas', $this->buyer())
            ->assertRedirect(route('lt.cart'))->assertSessionHas('status', __('public.cart.removed', [], 'lt'));
        $this->assertSame(0, Order::count());
    }

    public function test_checkout_fields_are_checked_like_the_calculator_and_phone_is_required(): void
    {
        $this->post('/lt/krepselis', ['product' => 'pla-gija', 'quantity' => 1]);

        $this->from('/lt/krepselis')->post('/lt/krepselis/uzsakymas', $this->buyer(['phone' => '', 'email' => 'ne-pastas', 'city' => '']))
            ->assertRedirect('/lt/krepselis')->assertSessionHasErrors([
                'phone' => 'Užpildykite šį lauką.', 'email' => 'Įveskite teisingą el. pašto adresą.', 'city' => 'Užpildykite šį lauką.',
            ]);
        $this->post('/lt/krepselis/uzsakymas', $this->buyer(['delivery' => 'courier']))->assertSessionHasErrors('delivery');
        $this->post('/lt/krepselis/uzsakymas', $this->buyer(['website_hp' => 'spam']))->assertSessionHasErrors('form');
        $this->assertSame(0, Order::count());
        $page = Html::xpath($this->get('/lt/krepselis')->getContent());
        $this->assertSame(['Pristatymas visoje ES', 'Atsiėmimas'], Html::texts($page, '//select[@name="delivery"]/option'));

        // Atsiėmimas — adreso nereikia, pristatymo eilutės nėra.
        $this->post('/lt/krepselis/uzsakymas', $this->buyer(['delivery' => 'pickup', 'city' => '', 'address' => '', 'postcode' => '']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::sole();
        $this->assertSame(2400, $order->total->value);
        $this->assertSame(['pickup', null], [OrderMeta::sole()->delivery, OrderMeta::sole()->address]);
        $this->assertSame(0, $order->lines()->where('purchasable_type', (new OrderCharge)->getMorphClass())->count());
    }

    public function test_guest_purchase_creates_a_products_order_pays_through_stripe_and_gets_an_invoice(): void
    {
        Mail::fake();
        $this->seller();
        ProductCart::saveDeliveryCents(500);
        $this->post('/lt/krepselis', ['product' => 'pla-gija', 'quantity' => 2]);
        $this->assertSame(['5,00 €', '53,00 €'], Html::texts(Html::xpath($this->get('/lt/krepselis')->getContent()), '//aside//div[@data-delivery-row]/b | //aside//span[@data-cart-total]'));

        $response = $this->post('/lt/krepselis/uzsakymas', $this->buyer());
        $payment = PaymentRequest::sole();
        $response->assertRedirect(route('lt.checkout', $payment->public_id));
        $this->assertSame([], session(ProductCart::SESSION, []));

        // Заказ общего журнала с номером общей серии и пометкой «Prekės»; цена подтверждена; запрос на всю сумму.
        $order = Order::sole();
        $meta = OrderMeta::sole();
        $state = ProductionState::sole();
        // Правка 12.11, K-05: номер — случайный 8-значный (= reference), а не 1.
        $this->assertSame([true, (int) $order->reference, null, 'lt'], [$meta->products, $meta->number, $order->user_id, $meta->locale]);
        $this->assertMatchesRegularExpression('/^[1-9]\d{7}$/', $order->reference);
        $this->assertNotNull($meta->guest_token_hash);
        $this->assertSame(ProductionStage::Confirmed, $state->stage);
        $this->assertNotNull($state->confirmed_at);
        $this->assertSame(5300, $order->total->value);
        $this->assertSame([5300, PaymentRequest::PENDING, $order->id], [$payment->amount, $payment->state, $payment->order_id]);
        $line = ProductLine::sole();
        $this->assertSame([['lt' => 'PLA gija', 'en' => 'PLA filament'], 2, 2400, $this->pla()->id], [$line->title, $line->quantity, $line->unit_price, $line->content_item_id]);
        $this->assertSame([OrderCharge::DELIVERY, 500, $order->id], [OrderCharge::sole()->kind, OrderCharge::sole()->amount, OrderCharge::sole()->order_id]);

        // Письмо AIQME — то же письмо о заказе, с позициями-товарами.
        Mail::assertSent(FormSubmitted::class, function (FormSubmitted $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('info@aiqme.com') && str_contains($html, '<title>Naujas gamybos užsakymas</title>')
                && str_contains($html, '<th align="left" valign="top">Prekės</th><td>PLA gija × 2 — 48,00'."\u{00A0}".'€</td>')
                && str_contains($html, '53.00 EUR') && ! str_contains($html, 'Preliminari suma');
        });

        // Страница оплаты — гостю с правом в сессии; intent через FakeStripe, без сети.
        $this->get(route('lt.checkout', $payment->public_id))->assertOk()->assertSee('data-client-secret="pi_fake_1_secret_fake"', false);
        $this->assertSame('pi_fake_1', $payment->fresh()->stripe_intent_id);
        $this->assertSame(['create'], $this->stripe->operations());

        // Подписанный webhook об оплате — счёт по H-06 с позициями товаров.
        $this->sendStripe($this->succeededPayload($payment->fresh(), 'evt_products_paid'))->assertOk();
        $this->assertSame(PaymentRequest::PAID, $payment->fresh()->state);
        $invoice = Invoice::sole();
        $this->assertSame('TEST'.(now()->year % 10).'0007', $invoice->label());
        $this->assertSame(5300, $invoice->total);
        $this->assertSame([['PLA gija', 2, 4800], ['Pristatymas', 1, 500]],
            $invoice->lines()->orderBy('id')->get()->map(fn ($row): array => [$row->description, $row->quantity, $row->gross])->all());

        // Админка: «Prekės» в журнале и карточка с позициями.
        $this->actingAs($this->admin);
        $list = Html::xpath($this->get('/lt/administravimas/orders')->assertOk()->getContent());
        // Правка 12.11, K-05: в журнале номер заказа (случайный), а не 1.
        $this->assertSame([$order->reference, 'Prekės'], [Html::text($list->query('//tbody/tr/td[1]/strong')->item(0)), Html::text($list->query('//tbody/tr/td[2]')->item(0))]);
        $card = Html::xpath($this->get('/lt/administravimas/orders?order='.$order->id)->assertOk()->getContent());
        $this->assertSame(['PLA gija', '2', '24,00 €', '48,00 €'], Html::texts($card, '//*[@id="order-products"]//tr[@data-product-line]/td'));
        $this->assertSame(1, $card->query('//*[@data-products-order]')->length);
        $this->assertSame(0, $card->query('//*[@id="order-card"]//p[starts-with(normalize-space(), "Failai ir gamybos parametrai:")]')->length);
    }

    public function test_signed_in_customer_sees_product_lines_in_the_account(): void
    {
        $client = User::factory()->create();
        $this->actingAs($client)->post('/en/cart', ['product' => 'pla-filament', 'quantity' => 2]);
        $this->post('/en/cart/checkout', $this->buyer(['delivery' => 'pickup']))->assertRedirect(route('en.checkout', PaymentRequest::sole()->public_id));
        $meta = OrderMeta::sole();
        $this->assertSame([$client->id, null], [Order::sole()->user_id, $meta->guest_token_hash]);

        $orders = Html::xpath($this->get('/en/account/orders')->assertOk()->getContent());
        $this->assertSame('Products', Html::text($orders->query('//tr[@data-order]/td[2]')->item(0)));
        $page = Html::xpath($this->get('/en/account/orders/'.$meta->number)->assertOk()->getContent());
        $this->assertSame(['PLA filament × 2', '€48.00'], Html::texts($page, '//div[@data-product-line]/*'));
        $this->assertSame('€48.00', Html::text($page->query('//*[@data-total]')->item(0)));
        $this->get(route('en.checkout', PaymentRequest::sole()->public_id))->assertOk();
    }

    public function test_unpublished_or_quote_product_is_removed_from_the_cart_at_checkout_with_a_message(): void
    {
        $pla = $this->pla();
        $this->post('/lt/krepselis', ['product' => 'pla-gija', 'quantity' => 2]);
        // Администратор переводит товар в «Gauti pasiūlymą».
        $pla->forceFill(['meta' => array_diff_key($pla->meta, ['sale' => true])])->save();
        $this->post('/lt/krepselis/uzsakymas', $this->buyer())->assertRedirect(route('lt.cart'))
            ->assertSessionHas('status', 'Kai kurie produktai nebeparduodami ir pašalinti iš krepšelio. Patikrinkite krepšelį.');
        $this->assertSame([0, []], [Order::count(), session(ProductCart::SESSION)]);
        $this->get('/lt/krepselis')->assertOk()->assertSee('Kai kurie produktai nebeparduodami')->assertSee('Krepšelis kol kas tuščias');

        // Снятый с публикации на локали товар — так же.
        $pla->forceFill(['meta' => [...$pla->meta, 'sale' => 'buy']])->save();
        $this->post('/en/cart', ['product' => 'pla-filament', 'quantity' => 1]);
        $pla->translation('en')->forceFill(['published_at' => null])->save();
        $this->post('/en/cart/checkout', $this->buyer())->assertRedirect(route('en.cart'))
            ->assertSessionHas('status', 'Some products are no longer for sale and were removed from your basket. Please check your basket.');
        $this->assertSame(0, Order::count());
    }

    public function test_settings_have_the_product_delivery_price_with_zero_by_default(): void
    {
        $this->actingAs($this->admin);
        $this->seller();
        app()->setLocale('lt');
        $form = Livewire::test(Settings::class)->assertSee('Pristatymo kaina (prekėms), €')->assertSet('productDelivery', '0.00');
        $form->set('productDelivery', '-1')->call('save')->assertHasErrors('productDelivery');
        $this->assertSame(0, ProductCart::deliveryCents());
        $form->set('productDelivery', '6,50')->call('save')->assertHasNoErrors();
        $this->assertSame(650, ProductCart::deliveryCents());
        Livewire::test(Settings::class)->assertSet('productDelivery', '6.50');
    }

    /** Тестовые реквизиты продавца, серия TEST, следующий номер года — 7 (как InvoicesTest). */
    private function seller(): void
    {
        app(SellerSettings::class)->save([
            'name' => 'TEST UAB', 'company_code' => 'TEST-000000', 'vat_code' => 'TEST-LT000000', 'address' => 'TEST g. 1, Vilnius',
            'vat_payer' => true, 'vat_rate' => 21, 'vat_note' => null, 'series' => 'TEST',
        ]);
        InvoiceNumber::setNext('TEST', 7);
    }
}
