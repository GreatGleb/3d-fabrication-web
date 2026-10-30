<?php

namespace Tests\Feature;

use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderSubmission;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Domain\Orders\SubmittedOrder;
use App\Domain\Payments\Models\PaymentRequest;
use App\Domain\Payments\PaymentRecorder;
use App\Domain\Payments\PaymentRequestService;
use App\Livewire\Admin\Orders\Card;
use App\Models\OrderCharge;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\Models\OrderLine;
use Lunar\Models\Transaction;
use RuntimeException;
use Stripe\PaymentIntent;
use Stripe\StripeClientInterface;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * Платёжные запросы и Stripe на уровне L (W-16, §3.3, §3.4, §6.2, §9.2 «Оплата» и «Права»): intent при первом открытии
 * страницы оплаты, продолжение того же intent, неизвестный исход и «проверить статус в Stripe», отмена запроса и заказа
 * по автомату, ответ «уже оплачено», запись capture внутренним сервисом, права на страницу оплаты, CSP, демо-режим.
 * Stripe — только мок StripeClientInterface (FakeStripe) под настоящими сервисами SDK; каждый тест проверяет, что ни
 * один вызов Stripe не сделан внутри DB::transaction (уровень вызова = уровень на начало теста, с RefreshDatabase — 1).
 * Заказ — штатная заявка W-12 (две детали по 30 EUR, плата 5 EUR, доставка 30 EUR = 95 EUR), запрос — requestPayment W-13.
 */
class PaymentRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $clientA;

    private User $clientB;

    private User $admin;

    private FakeStripe $stripe;

    private int $level;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        Storage::fake('local');
        $this->clientA = User::factory()->create();
        $this->clientB = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        config(['services.stripe.key' => 'test-stripe-publishable-not-real', 'services.stripe.secret' => 'test-stripe-secret-not-real']);
        $this->stripe = new FakeStripe;
        $this->app->instance(StripeClientInterface::class, $this->stripe);
        $this->level = DB::transactionLevel();
    }

    protected function tearDown(): void
    {
        $calls = isset($this->stripe) ? $this->stripe->calls : [];
        // Сначала откат транзакции теста (RefreshDatabase), чтобы провал проверки не оставил блокировки строк.
        parent::tearDown();
        // Ни одного вызова Stripe внутри транзакции БД (§2.6): сравнение с уровнем на начало теста, а не с 0.
        foreach ($calls as $call) {
            $this->assertSame($this->level, $call['level'], 'вызов Stripe '.$call['operation'].' внутри DB::transaction');
        }
    }

    /** Заявка W-12 (две детали по 2 шт.); гость — $user = null. Цена подтверждена: 30 + 30 + 5 + доставка 30 = 95 EUR. */
    private function order(?User $user, bool $confirm = true): SubmittedOrder
    {
        $cube = file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));
        $submitted = app(OrderSubmission::class)->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => 'en',
            'contact' => ['name' => 'Ona', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => null, 'company_code' => null, 'vat_code' => null],
            'delivery' => 'ship',
            'address' => ['country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gedimino pr. 1', 'postcode' => 'LT-01103'],
            'material' => 'PLA',
            'infill' => 100,
            'preferences' => ['color' => 'black', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'term' => 'standard'],
            'parts' => array_map(fn (string $name): array => [
                'file' => UploadedFile::fake()->createWithContent($name, $cube), 'unit' => 'mm', 'scale' => 100, 'quantity' => 2,
            ], ['bracket.stl', 'cover.stl']),
        ], $user);
        if ($confirm) {
            $ids = $submitted->order->lines()->orderBy('id')->get()
                ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
                ->modelKeys();
            $prices = array_fill_keys($ids, 3000);
            $prices[end($ids)] = 500;
            $this->workflow()->confirmPrice($this->admin, $submitted->order, $prices, 3000);
        }

        return $submitted;
    }

    private function workflow(): OrderWorkflow
    {
        return app(OrderWorkflow::class);
    }

    private function payments(): PaymentRequestService
    {
        return app(PaymentRequestService::class);
    }

    private function request(SubmittedOrder $submitted, int $amount = 4000): PaymentRequest
    {
        return $this->workflow()->requestPayment($this->admin, $submitted->order, $amount);
    }

    private function url(PaymentRequest $request, string $locale = 'en'): string
    {
        return route($locale.'.checkout', $request->public_id);
    }

    /** Новый посетитель: без входа и с пустой сессией. */
    private function visitor(): static
    {
        Auth::forgetGuards();
        $this->flushSession();

        return $this;
    }

    /** Запрос с intent: владелец открыл страницу оплаты (intent создан, форма показана). */
    private function opened(SubmittedOrder $submitted, int $amount = 4000): PaymentRequest
    {
        $request = $this->request($submitted, $amount);
        $this->actingAs($this->clientA)->get($this->url($request))->assertOk()->assertSee('id="payment-element"', false);
        $this->visitor();

        return $request->fresh();
    }

    /** Запрос в creating: создание intent прервал таймаут (неизвестный исход). */
    private function creating(SubmittedOrder $submitted): PaymentRequest
    {
        $request = $this->request($submitted);
        $this->stripe->fail('create', FakeStripe::timeout());
        $this->actingAs($this->clientA)->get($this->url($request))->assertOk()->assertSee(__('checkout.states.checking'));
        $this->visitor();

        return $request->fresh();
    }

    private function assertRejected(string $code, Closure $action): void
    {
        try {
            $action();
            $this->fail("Expected rejection {$code}");
        } catch (ValidationException $exception) {
            $this->assertSame([__('orders.workflow.'.$code)], $exception->errors()['workflow']);
        }
    }

    /** @return list<Transaction> */
    private function captures(SubmittedOrder $submitted): array
    {
        return Transaction::where('order_id', $submitted->order->id)->where('type', 'capture')->orderBy('id')->get()->all();
    }

    public function test_checkout_is_idempotent_and_does_not_create_a_second_intent(): void
    {
        $submitted = $this->order($this->clientA);
        $request = $this->request($submitted);
        // Запрос создаётся без обращения к Stripe.
        $this->assertSame([], $this->stripe->calls);
        $this->assertSame(PaymentRequest::PENDING, $request->state);
        $this->assertNull($request->stripe_intent_id);

        $page = $this->actingAs($this->clientA)->get($this->url($request))->assertOk();
        $page->assertSee('id="payment-element"', false)->assertSee('data-client-secret="pi_fake_1_secret_fake"', false)
            ->assertSee('data-stripe-key="test-stripe-publishable-not-real"', false)
            ->assertSee('data-return-url="'.$this->url($request).'"', false)
            ->assertSee('<script src="https://js.stripe.com/v3/" nonce=', false);
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $request->refresh();
        $this->assertSame(PaymentRequest::PENDING, $request->state);
        $this->assertSame('pi_fake_1', $request->stripe_intent_id);
        $this->assertSame('aiqme-request-'.$request->public_id, $request->idempotency_key);
        $this->assertNotNull($request->intent_first_attempt_at);

        // Параметры intent: сумма и валюта из запроса, автоматическое списание, только карта (D-33), metadata, ключ.
        $create = $this->stripe->calls[0];
        $this->assertSame('create', $create['operation']);
        $this->assertSame(4000, $create['params']['amount']);
        $this->assertSame('eur', $create['params']['currency']);
        $this->assertSame('automatic', $create['params']['capture_method']);
        $this->assertSame(['card'], $create['params']['payment_method_types']);
        $this->assertArrayNotHasKey('automatic_payment_methods', $create['params']);
        $this->assertSame(['request_id' => $request->public_id, 'order_id' => (string) $submitted->order->id], $create['params']['metadata']);
        $this->assertSame('aiqme-request-'.$request->public_id, $create['opts']['Idempotency-Key']);

        // Повторное открытие продолжает тот же intent (retrieve), второго не создаёт.
        $this->get($this->url($request))->assertOk()->assertSee('data-client-secret="pi_fake_1_secret_fake"', false);
        $this->assertSame(['create', 'retrieve'], $this->stripe->operations());
        $this->assertCount(1, $this->stripe->intents);
        $this->assertSame('pi_fake_1', $request->fresh()->stripe_intent_id);
    }

    public function test_other_buyer_cannot_create_intent_for_someone_elses_request(): void
    {
        $request = $this->request($this->order($this->clientA));
        $guestOrder = $this->order(null);
        $guestRequest = $this->request($guestOrder);
        $otherGuest = $this->order(null);

        $who = [
            'клиент B' => fn () => $this->visitor()->actingAs($this->clientB),
            'посетитель' => fn () => $this->visitor(),
            'гость с правом на другой гостевой заказ' => fn () => $this->visitor()->get('/en/order/'.$otherGuest->meta->number.'/'.$otherGuest->guestToken)->assertOk(),
        ];
        foreach ([$request, $guestRequest] as $target) {
            foreach ($who as $name => $as) {
                $as();
                foreach (['GET', 'HEAD'] as $method) {
                    $response = $this->call($method, $this->url($target));
                    $this->assertSame(404, $response->getStatusCode(), $name.' '.$method);
                    $this->assertStringNotContainsString('secret', $response->getContent(), $name);
                }
                $this->get($this->url($target, 'lt'))->assertNotFound();
            }
        }
        // Неизвестный public_id и голый адрес — тоже 404.
        $this->visitor()->actingAs($this->clientA)->get('/en/checkout/'.Str::uuid())->assertNotFound();
        $this->get('/en/checkout/not-a-uuid')->assertNotFound();

        $this->assertSame([], $this->stripe->calls);
        foreach ([$request, $guestRequest] as $target) {
            $this->assertSame(PaymentRequest::PENDING, $target->fresh()->state);
            $this->assertNull($target->fresh()->stripe_intent_id);
        }
    }

    public function test_guest_from_a_clean_session_reaches_checkout_from_the_guest_page_until_the_link_is_reissued(): void
    {
        $guest = $this->order(null);
        $request = $this->request($guest);

        $page = $this->visitor()->get('/en/order/'.$guest->meta->number.'/'.$guest->guestToken)->assertOk();
        $page->assertSee('href="'.$this->url($request).'"', false)->assertSee('Proceed to checkout');
        $this->get($this->url($request))->assertOk()->assertSee('id="payment-element"', false)
            ->assertSee('data-client-secret="pi_fake_1_secret_fake"', false);
        // HEAD с правом — 200 и без нового вызова Stripe.
        $this->call('HEAD', $this->url($request))->assertOk();
        $this->assertSame(['create'], $this->stripe->operations());

        // Перевыпуск ссылки: старое право в сессии больше не действует.
        $this->workflow()->reissueGuestLink($this->admin, $guest->order);
        $this->get($this->url($request))->assertNotFound();
        $this->call('HEAD', $this->url($request))->assertNotFound();
        $this->assertSame(['create'], $this->stripe->operations());
    }

    public function test_admin_sees_the_page_without_payment_element_and_no_intent_is_created(): void
    {
        $submitted = $this->order($this->clientA);
        $request = $this->request($submitted);

        $this->actingAs($this->admin)->get($this->url($request))->assertOk()
            ->assertSee(__('checkout.states.admin'))->assertDontSee('payment-element')->assertDontSee('js.stripe.com/v3', false);
        $this->assertSame([], $this->stripe->calls);
        $this->assertNull($request->fresh()->stripe_intent_id);

        // Ссылка страницы оплаты — в карточке заказа клиента в админке; у гостевого заказа её нет (гостю — гостевая ссылка).
        Livewire::actingAs($this->admin)->test(Card::class, ['orderId' => $submitted->order->id])
            ->assertSeeHtml('href="'.$this->url($request).'"');
        $guest = $this->order(null);
        $guestRequest = $this->request($guest);
        Livewire::actingAs($this->admin)->test(Card::class, ['orderId' => $guest->order->id])
            ->assertDontSeeHtml($this->url($guestRequest))->assertSee(__('admin.orders.cancel_request'));

        // Кнопка оплаты — в карточке кабинета владельца.
        $this->actingAs($this->clientA)->get(route('en.account.orders.show', $submitted->meta->number))->assertOk()
            ->assertSee('href="'.$this->url($request).'"', false);
    }

    public function test_return_url_and_paid_parameter_do_not_mark_the_request_paid(): void
    {
        $submitted = $this->order($this->clientA);
        $request = $this->opened($submitted);
        $calls = count($this->stripe->calls);

        foreach (['succeeded', 'processing'] as $status) {
            $this->actingAs($this->clientA)->get($this->url($request).'?payment_intent=pi_fake_1&payment_intent_client_secret=pi_fake_1_secret_fake&redirect_status='.$status)
                ->assertOk()->assertSee(__('checkout.states.processing'))->assertDontSee('payment-element');
        }
        $this->assertCount($calls, $this->stripe->calls, 'возврат браузера не обращается к Stripe');
        $this->get($this->url($request).'?paid=1')->assertOk()->assertSee('payment-element');
        $this->assertSame(PaymentRequest::PENDING, $request->fresh()->state);
        $this->assertSame([], $this->captures($submitted));
        $this->assertSame(0, $this->workflow()->balance($submitted->order->fresh())['paid']);
    }

    public function test_reopening_with_a_succeeded_or_processing_intent_shows_processing_and_records_nothing(): void
    {
        $submitted = $this->order($this->clientA);
        $request = $this->opened($submitted);

        $this->stripe->put('pi_fake_1', ['status' => 'processing']);
        $this->actingAs($this->clientA)->get($this->url($request))->assertOk()
            ->assertSee(__('checkout.states.processing'))->assertDontSee('payment-element');
        $this->stripe->pay('pi_fake_1');
        $this->get($this->url($request))->assertOk()->assertSee(__('checkout.states.processing'))->assertDontSee('payment-element');

        // Оплату записывает webhook или кнопка администратора, а не страница.
        $this->assertSame(['create', 'retrieve', 'retrieve'], $this->stripe->operations());
        $this->assertSame(PaymentRequest::PENDING, $request->fresh()->state);
        $this->assertSame([], $this->captures($submitted));
    }

    public function test_missing_or_live_keys_show_unavailable_without_changing_the_request(): void
    {
        $request = $this->request($this->order($this->clientA));
        foreach ([['services.stripe.key' => null], ['services.stripe.secret' => ''], ['services.stripe.secret' => 'fake_live_secret-not-real']] as $config) {
            config(['services.stripe.key' => 'test-stripe-publishable-not-real', 'services.stripe.secret' => 'test-stripe-secret-not-real']);
            config($config);
            $this->actingAs($this->clientA)->get($this->url($request))->assertOk()
                ->assertSee(__('checkout.states.unavailable'))->assertDontSee('payment-element');
            $this->assertSame(PaymentRequest::PENDING, $request->fresh()->state);
            $this->assertNull($request->fresh()->intent_first_attempt_at);
        }
        $this->assertSame([], $this->stripe->calls);
    }

    public function test_timeout_or_4xx_on_creation_leaves_creating_and_reopening_does_not_call_stripe(): void
    {
        $submitted = $this->order($this->clientA);
        foreach ([FakeStripe::timeout(), FakeStripe::badRequest()] as $failure) {
            $request = $this->request($submitted, 1000);
            $before = count($this->stripe->calls);
            $this->stripe->fail('create', $failure);
            $this->actingAs($this->clientA)->get($this->url($request))->assertOk()
                ->assertSee(__('checkout.states.checking'))->assertDontSee('payment-element');
            $this->assertSame(PaymentRequest::CREATING, $request->fresh()->state);
            $this->assertNull($request->fresh()->stripe_intent_id);
            // Вторая вкладка и повторное открытие — «проверяется» без Stripe, второй intent не создаётся.
            $this->get($this->url($request))->assertOk()->assertSee(__('checkout.states.checking'));
            $this->assertCount($before + 1, $this->stripe->calls);
        }
        $this->assertSame([], $this->stripe->intents);
    }

    public function test_check_status_of_creating_finds_the_intent_or_retries_with_the_same_key_within_24_hours(): void
    {
        $submitted = $this->order($this->clientA);

        // Intent есть в Stripe (ответ потерян) — найден по metadata.request_id: creating → pending, без нового создания.
        $found = $this->creating($submitted);
        $this->stripe->put('pi_lost', ['amount' => 4000, 'currency' => 'eur', 'status' => 'requires_payment_method', 'client_secret' => 'pi_lost_secret_fake', 'metadata' => ['request_id' => $found->public_id, 'order_id' => (string) $submitted->order->id]]);
        $this->assertSame(PaymentRequest::PENDING, $this->payments()->checkStatus($this->admin, $found));
        $this->assertSame('pi_lost', $found->fresh()->stripe_intent_id);
        $this->assertSame(['create', 'search'], $this->stripe->operations());

        // Не найден (Search API отстаёт) в пределах 24 часов — повтор создания с тем же ключом.
        $retry = PaymentRequest::create(['public_id' => (string) Str::uuid(), 'order_id' => $submitted->order->id, 'amount' => 1000, 'currency' => 'EUR', 'state' => PaymentRequest::PENDING]);
        $this->stripe->calls = [];
        $this->stripe->fail('create', FakeStripe::timeout());
        $this->actingAs($this->clientA)->get($this->url($retry))->assertOk()->assertSee(__('checkout.states.checking'));
        $first = $retry->fresh()->intent_first_attempt_at;
        $this->travel(23)->hours();
        $this->assertSame(PaymentRequest::PENDING, $this->payments()->checkStatus($this->admin, $retry));
        $this->assertSame(['create', 'search', 'create'], $this->stripe->operations());
        $keys = array_column(array_column(array_filter($this->stripe->calls, fn ($call) => $call['operation'] === 'create'), 'opts'), 'Idempotency-Key');
        $this->assertSame(['aiqme-request-'.$retry->public_id, 'aiqme-request-'.$retry->public_id], $keys);
        $retry->refresh();
        $this->assertNotNull($retry->stripe_intent_id);
        $this->assertTrue($first->equalTo($retry->intent_first_attempt_at), 'повторами intent_first_attempt_at не меняется');
        // Клиент продолжает оплату тем же intent.
        $this->actingAs($this->clientA)->get($this->url($retry))->assertOk()->assertSee('data-client-secret="'.$retry->stripe_intent_id.'_secret_fake"', false);
    }

    public function test_check_status_of_creating_after_24_hours_voids_it_for_review(): void
    {
        $request = $this->creating($this->order($this->clientA));
        $this->travel(25)->hours();

        $this->assertSame(PaymentRequest::VOID, $this->payments()->checkStatus($this->admin, $request));
        $request->refresh();
        $this->assertSame(PaymentRequest::VOID, $request->state);
        $this->assertTrue($request->needs_review);
        $this->assertSame('intent_not_found', $request->review_reason);
        $this->assertSame(['create', 'search'], $this->stripe->operations(), 'позже 24 часов создание не повторяется');
    }

    public function test_cancel_without_intent_voids_without_stripe_and_with_intent_goes_through_cancelling(): void
    {
        $submitted = $this->order($this->clientA);
        $plain = $this->request($submitted, 1000);
        $this->assertSame(PaymentRequest::VOID, $this->payments()->cancel($this->admin, $plain));
        $this->assertSame(PaymentRequest::VOID, $plain->fresh()->state);
        $this->assertSame([], $this->stripe->calls);

        $request = $this->opened($submitted, 2000);
        $states = [];
        PaymentRequest::updated(function (PaymentRequest $changed) use (&$states, $request): void {
            if ($changed->id === $request->id) {
                $states[] = $changed->state;
            }
        });
        $this->assertSame(PaymentRequest::VOID, $this->payments()->cancel($this->admin, $request));
        $this->assertSame([PaymentRequest::CANCELLING, PaymentRequest::VOID], $states);
        $this->assertSame(['create', 'cancel'], $this->stripe->operations());
        $this->assertSame('canceled', $this->stripe->intents['pi_fake_1']['status']);
        // Событие §5.1 «запрос отменён» — без письма.
        $event = IntegrationEvent::where('type', 'payment_request.cancelled')->where('entity_id', $request->id)->sole();
        $this->assertSame('none', $event->mail_status);
        $this->assertSame(PaymentRequest::VOID, $event->data['state']);
        // Страница оплаты закрыта.
        $this->actingAs($this->clientA)->get($this->url($request))->assertOk()->assertSee(__('checkout.states.closed'))->assertDontSee('payment-element');
    }

    public function test_cancel_event_is_written_in_the_same_transaction_as_the_change(): void
    {
        $request = $this->opened($this->order($this->clientA));
        IntegrationEvent::creating(fn () => throw new RuntimeException('outbox down'));
        try {
            $this->payments()->cancel($this->admin, $request);
            $this->fail('ожидался сбой записи события');
        } catch (RuntimeException) {
        }
        // void откатился вместе с событием: запрос остаётся cancelling до «проверить статус».
        $this->assertSame(PaymentRequest::CANCELLING, $request->fresh()->state);
    }

    public function test_stripe_unavailable_on_cancel_keeps_cancelling_and_check_status_finishes_it(): void
    {
        $submitted = $this->order($this->clientA);

        // Первая отмена — таймаут; затем retrieve → canceled → void.
        $first = $this->opened($submitted, 2000);
        $this->stripe->fail('cancel', FakeStripe::timeout());
        $this->assertSame('error', $this->payments()->cancel($this->admin, $first));
        $this->assertSame(PaymentRequest::CANCELLING, $first->fresh()->state);
        $this->actingAs($this->clientA)->get($this->url($first))->assertOk()->assertSee(__('checkout.states.closed'));
        $this->stripe->put($first->stripe_intent_id, ['status' => 'canceled']);
        $this->stripe->calls = [];
        $this->assertSame(PaymentRequest::VOID, $this->payments()->checkStatus($this->admin, $first));
        $this->assertSame(['retrieve'], $this->stripe->operations(), 'canceled — без повторной отмены');
        $this->assertSame(PaymentRequest::VOID, $first->fresh()->state);

        // Отменяемый статус — повтор отмены.
        $second = $this->opened($submitted, 1000);
        $this->stripe->fail('cancel', FakeStripe::timeout());
        $this->payments()->cancel($this->admin, $second);
        $this->stripe->calls = [];
        $this->assertSame(PaymentRequest::VOID, $this->payments()->checkStatus($this->admin, $second));
        $this->assertSame(['retrieve', 'cancel'], $this->stripe->operations());

        // Неотменяемый статус (processing) — остаётся cancelling, ошибка видна администратору.
        $third = $this->opened($submitted, 1000);
        $this->stripe->fail('cancel', FakeStripe::timeout());
        $this->payments()->cancel($this->admin, $third);
        $this->stripe->put($third->stripe_intent_id, ['status' => 'processing']);
        $this->assertSame('error', $this->payments()->checkStatus($this->admin, $third));
        $this->assertSame(PaymentRequest::CANCELLING, $third->fresh()->state);
    }

    public function test_unexpected_state_on_cancel_of_a_canceled_intent_voids_the_request(): void
    {
        $request = $this->opened($this->order($this->clientA));
        $this->stripe->put($request->stripe_intent_id, ['status' => 'canceled']);

        $this->assertSame(PaymentRequest::VOID, $this->payments()->cancel($this->admin, $request));
        $this->assertSame(['create', 'cancel', 'retrieve'], $this->stripe->operations());
        $this->assertSame(PaymentRequest::VOID, $request->fresh()->state);
    }

    public function test_already_paid_answer_on_cancel_records_the_capture_once(): void
    {
        $submitted = $this->order($this->clientA);
        $request = $this->opened($submitted);
        $this->stripe->pay($request->stripe_intent_id);

        // Stripe отвечает исключением payment_intent_unexpected_state, затем retrieve → succeeded с latest_charge.
        $this->assertSame(PaymentRequest::PAID, $this->payments()->cancel($this->admin, $request));
        $this->assertSame(['create', 'cancel', 'retrieve'], $this->stripe->operations());
        $request->refresh();
        $this->assertSame(PaymentRequest::PAID, $request->state);
        $this->assertFalse($request->needs_review);
        [$capture] = $this->captures($submitted);
        $this->assertSame(4000, $capture->amount->value);
        $this->assertSame('ch_fake_pi_fake_1', $capture->reference);
        $this->assertSame($request->public_id, $capture->meta['request_id']);
        $this->assertTrue($capture->success);
        $this->assertSame(4000, $this->workflow()->balance($submitted->order->fresh())['paid']);
        // Событие §5.1 «оплата прошла» и журнал.
        $event = IntegrationEvent::where('type', 'payment.succeeded')->sole();
        $this->assertSame('40.00', $event->data['amount']);
        $this->assertSame('none', $event->mail_status);
        $this->assertDatabaseHas('activity_log', ['event' => 'payment.succeeded', 'subject_id' => $submitted->order->id]);

        // Уже записанный заряд пропускается: повторная запись — без второго capture.
        $this->assertSame(PaymentRecorder::ALREADY_RECORDED, app(PaymentRecorder::class)->recordCapture($request, PaymentIntent::constructFrom($this->stripe->intents['pi_fake_1'])));
        $this->assertCount(1, $this->captures($submitted));
        $this->assertSame(1, IntegrationEvent::where('type', 'payment.succeeded')->count());
        // Оплаченный запрос не отменяется и не проверяется (недопустимые переходы).
        $this->assertRejected('request_not_cancellable', fn () => $this->payments()->cancel($this->admin, $request));
        $this->assertRejected('request_not_checkable', fn () => $this->payments()->checkStatus($this->admin, $request));
    }

    public function test_payment_event_is_written_in_the_same_transaction_as_the_capture(): void
    {
        $submitted = $this->order($this->clientA);
        $request = $this->opened($submitted);
        $this->stripe->pay($request->stripe_intent_id);
        IntegrationEvent::creating(fn () => throw new RuntimeException('outbox down'));
        try {
            $this->payments()->checkStatus($this->admin, $request);
            $this->fail('ожидался сбой записи события');
        } catch (RuntimeException) {
        }
        $this->assertSame([], $this->captures($submitted));
        $this->assertSame(PaymentRequest::PENDING, $request->fresh()->state);
    }

    public function test_check_status_of_pending_with_intent_records_capture_when_paid(): void
    {
        $submitted = $this->order($this->clientA);
        $request = $this->opened($submitted);

        $this->assertSame('unchanged', $this->payments()->checkStatus($this->admin, $request));
        $this->assertSame(PaymentRequest::PENDING, $request->fresh()->state);

        $this->stripe->pay($request->stripe_intent_id);
        $this->assertSame(PaymentRequest::PAID, $this->payments()->checkStatus($this->admin, $request));
        $this->assertSame(PaymentRequest::PAID, $request->fresh()->state);
        $this->assertCount(1, $this->captures($submitted));
        $this->assertSame('partial', $this->workflow()->balance($submitted->order->fresh())['payment_state']);
        $this->assertSame(ProductionStage::Confirmed, ProductionState::where('order_id', $submitted->order->id)->sole()->stage, 'оплата не меняет стадию');
    }

    public function test_order_cancel_moves_intent_requests_to_cancelling_then_void_without_refund(): void
    {
        $submitted = $this->order($this->clientA);
        $withIntent = $this->opened($submitted, 2000);
        $plain = $this->request($submitted, 1000);
        $states = [];
        PaymentRequest::updated(function (PaymentRequest $changed) use (&$states, $withIntent): void {
            if ($changed->id === $withIntent->id) {
                $states[] = $changed->state;
            }
        });

        $this->workflow()->cancel($this->admin, $submitted->order, 'Client changed plans');

        $this->assertSame([PaymentRequest::CANCELLING, PaymentRequest::VOID], $states);
        $this->assertSame(PaymentRequest::VOID, $withIntent->fresh()->state);
        $this->assertSame(PaymentRequest::VOID, $plain->fresh()->state);
        $this->assertSame(['create', 'cancel'], $this->stripe->operations());
        $this->assertSame(0, Transaction::where('order_id', $submitted->order->id)->count(), 'возврат не создаётся');
        $this->assertSame(2, IntegrationEvent::where('type', 'payment_request.cancelled')->count());
    }

    public function test_order_cancel_with_an_already_paid_intent_records_capture_for_review(): void
    {
        $submitted = $this->order($this->clientA);
        $request = $this->opened($submitted);
        $this->stripe->pay($request->stripe_intent_id);

        $this->workflow()->cancel($this->admin, $submitted->order, 'Client changed plans');

        $request->refresh();
        $this->assertSame(PaymentRequest::PAID, $request->state);
        $this->assertTrue($request->needs_review);
        $this->assertSame('cancelled_order', $request->review_reason);
        $this->assertCount(1, $this->captures($submitted));
    }

    public function test_order_cancel_with_a_creating_request_checks_stripe_after_commit(): void
    {
        $submitted = $this->order($this->clientA);
        $notFound = $this->creating($submitted);
        $found = PaymentRequest::create(['public_id' => (string) Str::uuid(), 'order_id' => $submitted->order->id, 'amount' => 1000, 'currency' => 'EUR', 'state' => PaymentRequest::CREATING, 'idempotency_key' => 'x', 'intent_first_attempt_at' => now()]);
        $this->stripe->put('pi_found', ['amount' => 1000, 'currency' => 'eur', 'status' => 'requires_payment_method', 'metadata' => ['request_id' => $found->public_id, 'order_id' => (string) $submitted->order->id]]);
        $this->stripe->calls = [];

        $this->workflow()->cancel($this->admin, $submitted->order, 'Client changed plans');

        $this->assertSame(PaymentRequest::VOID, $notFound->fresh()->state);
        $this->assertFalse($notFound->fresh()->needs_review);
        $this->assertSame(PaymentRequest::VOID, $found->fresh()->state);
        $this->assertSame('pi_found', $found->fresh()->stripe_intent_id);
        $this->assertSame('canceled', $this->stripe->intents['pi_found']['status']);
        $this->assertSame(['search', 'search', 'cancel'], $this->stripe->operations());

        // Stripe недоступен — запрос остаётся creating до «проверить статус в Stripe».
        $other = $this->order($this->clientA);
        $stuck = $this->creating($other);
        $this->stripe->fail('search', FakeStripe::timeout());
        $this->workflow()->cancel($this->admin, $other->order, 'Client changed plans');
        $this->assertSame(PaymentRequest::CREATING, $stuck->fresh()->state);
        $this->assertSame(ProductionStage::Cancelled, ProductionState::where('order_id', $other->order->id)->sole()->stage);
    }

    public function test_transaction_two_after_order_cancellation_gives_no_client_secret_and_cancels_the_intent(): void
    {
        // Заказ отменён между транзакциями 1 и 2 (отмена не дождалась Stripe): creating → cancelling → void.
        $submitted = $this->order($this->clientA);
        $request = $this->request($submitted);
        $this->stripe->afterCreate = function () use ($submitted): void {
            $this->stripe->afterCreate = null;
            ProductionState::where('order_id', $submitted->order->id)->update(['stage' => ProductionStage::Cancelled->value]);
        };
        $page = $this->actingAs($this->clientA)->get($this->url($request))->assertOk();
        $page->assertSee(__('checkout.states.closed'))->assertDontSee('payment-element')->assertDontSee('_secret_fake');
        $this->assertSame(PaymentRequest::VOID, $request->fresh()->state);
        $this->assertSame('pi_fake_1', $request->fresh()->stripe_intent_id);
        $this->assertSame('canceled', $this->stripe->intents['pi_fake_1']['status']);

        // Запрос отменён (void) между транзакциями: состояние не меняется, intent отменяется в Stripe.
        $other = $this->order($this->clientA);
        $voided = $this->request($other);
        $this->stripe->afterCreate = function () use ($voided): void {
            $this->stripe->afterCreate = null;
            PaymentRequest::whereKey($voided->id)->update(['state' => PaymentRequest::VOID]);
        };
        $this->visitor()->actingAs($this->clientA)->get($this->url($voided))->assertOk()
            ->assertSee(__('checkout.states.closed'))->assertDontSee('_secret_fake');
        $this->assertSame(PaymentRequest::VOID, $voided->fresh()->state);
        $this->assertNull($voided->fresh()->stripe_intent_id);
        $this->assertSame('canceled', $this->stripe->intents['pi_fake_2']['status']);
    }

    public function test_creating_and_cancelling_requests_are_open_and_block_price_and_extra_requests(): void
    {
        $submitted = $this->order($this->clientA);
        $total = $this->workflow()->balance($submitted->order->fresh())['total'];
        $creating = $this->creating($submitted);
        $cancelling = $this->opened($submitted, 2000);
        $this->stripe->fail('cancel', FakeStripe::timeout());
        $this->payments()->cancel($this->admin, $cancelling);
        $this->assertSame(PaymentRequest::CANCELLING, $cancelling->fresh()->state);

        // Остаток = цена − 40 − 20 EUR: больше — отказ; цена не меняется, пока есть открытые запросы.
        $this->assertRejected('request_too_large', fn () => $this->workflow()->requestPayment($this->admin, $submitted->order, $total - 6000 + 1));
        $lines = $submitted->order->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY);
        $prices = $lines->mapWithKeys(fn (OrderLine $line): array => [$line->id => $line->unit_price->value])->all();
        $this->assertRejected('open_request', fn () => $this->workflow()->changePrice($this->admin, $submitted->order, $prices, 3000, 'Agreed', 'By phone'));
        $this->assertSame(PaymentRequest::CREATING, $creating->fresh()->state);
    }

    public function test_only_the_administrator_cancels_or_checks_and_invalid_transitions_are_rejected(): void
    {
        $submitted = $this->order($this->clientA);
        $request = $this->opened($submitted);
        foreach ([$this->clientA, $this->clientB] as $user) {
            foreach (['cancel', 'checkStatus'] as $method) {
                try {
                    $this->payments()->{$method}($user, $request);
                    $this->fail($method.' клиентом');
                } catch (AuthorizationException) {
                }
            }
        }
        $this->assertSame(PaymentRequest::PENDING, $request->fresh()->state);

        $plain = $this->request($submitted, 1000);
        $this->assertRejected('request_not_checkable', fn () => $this->payments()->checkStatus($this->admin, $plain));
        $this->payments()->cancel($this->admin, $plain);
        $this->assertRejected('request_not_cancellable', fn () => $this->payments()->cancel($this->admin, $plain));
        $this->assertRejected('request_not_checkable', fn () => $this->payments()->checkStatus($this->admin, $plain));
        $this->assertSame(['create'], $this->stripe->operations());
    }

    public function test_admin_card_cancels_and_checks_requests_through_stripe(): void
    {
        $submitted = $this->order($this->clientA);
        $request = $this->opened($submitted);
        $this->stripe->fail('cancel', FakeStripe::timeout());

        $card = Livewire::actingAs($this->admin)->test(Card::class, ['orderId' => $submitted->order->id])
            ->assertSee(__('admin.orders.check_stripe'))
            ->call('cancelRequest', $request->id)->assertSee(__('admin.orders.done.stripe_error'))
            ->assertSee(__('admin.orders.request_states.cancelling'));
        $this->assertSame(PaymentRequest::CANCELLING, $request->fresh()->state);

        $card->call('checkRequest', $request->id)->assertSee(__('admin.orders.done.stripe_void'));
        $this->assertSame(PaymentRequest::VOID, $request->fresh()->state);

        // Запрос чужого заказа — 404.
        $other = $this->request($this->order($this->clientB), 1000);
        $card->call('checkRequest', $other->id)->assertNotFound();
    }

    public function test_payment_blocks_of_this_task_are_not_shown_in_the_demo_build(): void
    {
        $submitted = $this->order($this->clientA);
        $request = $this->opened($submitted);
        $guest = $this->order(null);
        $guestRequest = $this->request($guest);

        $this->app['env'] = 'demo';
        $this->actingAs($this->clientA)->get(route('en.account.orders.show', $submitted->meta->number))->assertOk()
            ->assertDontSee('data-checkout-link', false)->assertDontSee($this->url($request), false);
        $this->visitor()->get('/en/order/'.$guest->meta->number.'/'.$guest->guestToken)->assertOk()
            ->assertDontSee('data-checkout-link', false)->assertDontSee($this->url($guestRequest), false);
        Livewire::actingAs($this->admin)->test(Card::class, ['orderId' => $submitted->order->id])
            ->assertDontSeeHtml('data-checkout-link')->assertDontSeeHtml('data-check-request')->assertDontSeeHtml('data-cancel-request');
    }

    public function test_checkout_page_csp_allows_only_the_stripe_domains_of_section_6_6(): void
    {
        $request = $this->request($this->order($this->clientA));
        $header = $this->actingAs($this->clientA)->get($this->url($request))->assertOk()->headers->get('Content-Security-Policy');
        $csp = [];
        foreach (array_filter(array_map('trim', explode(';', $header))) as $directive) {
            $parts = preg_split('/\s+/', $directive);
            $csp[array_shift($parts)] = $parts;
        }
        $stripe = fn (string $directive): array => array_values(preg_grep('/stripe/', $csp[$directive]));
        $this->assertSame(['https://js.stripe.com', 'https://*.js.stripe.com'], $stripe('script-src'));
        // Правка 28.10 П5: в frame-src дополнительно только фрейм карты Google Maps; скрипты и запросы к Google не разрешены.
        $this->assertSame(['https://js.stripe.com', 'https://*.js.stripe.com', 'https://hooks.stripe.com', 'https://www.google.com'], $csp['frame-src']);
        $this->assertSame(["'self'", 'https://api.stripe.com'], $csp['connect-src']);
        $this->assertSame([], preg_grep('/google/', $csp['script-src']));
        $this->assertNotContains("'unsafe-inline'", $csp['script-src']);
        $this->assertSame(["'self'"], $csp['default-src']);
    }
}
