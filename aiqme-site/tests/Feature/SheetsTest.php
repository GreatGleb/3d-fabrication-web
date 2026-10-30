<?php

namespace Tests\Feature;

use App\Domain\Content\Models\ContentItem;
use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Integrations\Sheets\GoogleTokenProvider;
use App\Domain\Integrations\Sheets\HttpSheetsClient;
use App\Domain\Integrations\Sheets\ProjectSheets;
use App\Domain\Integrations\Sheets\RebuildSheets;
use App\Domain\Integrations\Sheets\SheetsClient;
use App\Domain\Integrations\Sheets\SheetsProjector;
use App\Domain\Integrations\Sheets\TokenProvider;
use App\Domain\Invoices\SellerSettings;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderSubmission;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Orders\ProductionStage;
use App\Domain\Orders\SubmittedOrder;
use App\Livewire\Admin\Customers;
use App\Livewire\Admin\Integrations;
use App\Mail\FormSubmitted;
use App\Models\OrderCharge;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\browser\ContentFactory;
use Tests\Support\FakeSheets;
use Tests\Support\SignedStripeEvents;
use Tests\TestCase;

/**
 * Google Таблицы (W-18, §5.1, §5.3, §9.2 «Sheets»): HttpSheetsClient через Http::fake (адреса, RAW, тело, 429) с
 * заглушкой токена; поставщик токена google/auth на сгенерированном тестом ключе без сети; SheetsProjector на фейке
 * сетки — все события §5.1 штатных потоков W-09, W-12, W-13, W-16, W-28, W-17, upsert без дублей при повторе, ручной
 * перестановке и удалении строк, перечитывание БД, Contacts из БД, формулы как текст; канал sheets независим от mail;
 * без подключения события копятся; пересборка через очередь sheets пакетами ≤5000 строк; ссылка на таблицу в админке.
 */
class SheetsTest extends TestCase
{
    use RefreshDatabase;
    use SignedStripeEvents;

    private const TOKEN = 'test-google-token-not-real';

    private FakeSheets $sheets;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        $this->useWebhookSecret();
        $this->seed(PrototypeContentSeeder::class);
        $this->connect();
    }

    /** Sheets подключены: фиктивные ключ и ID, заглушка токена, фейк сетки вместо HTTP-клиента. */
    private function connect(): void
    {
        config(['aiqme.sheets.key' => 'test-key-not-real', 'aiqme.sheets.spreadsheet_id' => 'test-spreadsheet-not-real']);
        $this->app->instance(TokenProvider::class, new class implements TokenProvider
        {
            public function token(): string
            {
                return 'test-google-token-not-real';
            }
        });
        $this->sheets ??= new FakeSheets;
        $this->app->instance(SheetsClient::class, $this->sheets);
    }

    private function flow(): OrderWorkflow
    {
        return app(OrderWorkflow::class);
    }

    /** Заявка W-12 (детали 30 + 30 EUR, плата 5 EUR, доставка 30 EUR после подтверждения). */
    private function order(string $email = 'ona@example.test', string $name = 'Ona TEST'): SubmittedOrder
    {
        $cube = file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl'));

        return app(OrderSubmission::class)->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => 'en',
            'contact' => ['name' => $name, 'email' => $email, 'phone' => '+370 600 00000', 'company' => 'UAB TEST', 'company_code' => 'TEST-123', 'vat_code' => null],
            'delivery' => 'ship',
            'address' => ['country' => 'LT', 'city' => 'Vilnius', 'address' => 'Gedimino pr. 1', 'postcode' => 'LT-01103'],
            'material' => 'PLA',
            'infill' => 100,
            'preferences' => ['color' => 'black', 'purpose' => 'visual', 'service' => 'none', 'surface' => 'standard', 'term' => 'standard'],
            'parts' => array_map(fn (string $file): array => [
                'file' => UploadedFile::fake()->createWithContent($file, $cube), 'unit' => 'mm', 'scale' => 100, 'quantity' => 2,
            ], ['bracket.stl', 'cover.stl']),
        ], null);
    }

    /** @return array<int, int> цены строк без доставки: детали по $part, плата (последняя строка) $fee */
    private function prices(Order $order, int $part, int $fee): array
    {
        $ids = $order->lines()->orderBy('id')->get()
            ->reject(fn (OrderLine $line): bool => $line->purchasable instanceof OrderCharge && $line->purchasable->kind === OrderCharge::DELIVERY)
            ->modelKeys();
        $prices = array_fill_keys($ids, $part);
        $prices[end($ids)] = $fee;

        return $prices;
    }

    private function contactRequest(string $email = 'jonas@example.test', string $name = 'Jonas Petraitis'): ContactRequest
    {
        $this->post('/en/contact', [
            'name' => $name, 'company' => 'UAB Detalė', 'email' => $email, 'phone' => '+370 611 11111',
            'service' => '3d-printing', 'description' => 'Need ten brackets printed in PETG.', 'deadline' => '2027-03-01',
            'altcha' => $this->altcha(),
        ])->assertSessionHasNoErrors();

        return ContactRequest::latest('id')->firstOrFail();
    }

    private function training(): ContentItem
    {
        return ContentFactory::as($this->admin)->training('cad-basics', '2027-02-10', [
            'lt' => ['CAD pagrindai', 'Nuotoliniu būdu · grupė'],
            'en' => ['CAD basics', 'Online · group'],
        ], 1);
    }

    public function test_http_client_reads_and_writes_values_batch_update_raw_with_the_provider_token(): void
    {
        Http::fake([
            'sheets.googleapis.com/v4/spreadsheets/test-spreadsheet-not-real/values/*' => Http::response(['range' => 'Orders!A1:Q2', 'values' => [['Order No'], ['1']]]),
            'sheets.googleapis.com/v4/spreadsheets/test-spreadsheet-not-real/values:batchUpdate' => Http::response(['totalUpdatedRows' => 1]),
        ]);
        $client = new HttpSheetsClient(app(TokenProvider::class), 'test-spreadsheet-not-real');

        $this->assertSame([['Order No'], ['1']], $client->getValues('Orders!A:Q'));
        $data = [['range' => 'Orders!A2', 'values' => [['1', '=HYPERLINK("http://evil.test")', '12.50']]]];
        $client->batchUpdate($data);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://sheets.googleapis.com/v4/spreadsheets/test-spreadsheet-not-real/values/Orders%21A%3AQ'
            && $request->header('Authorization') === ['Bearer '.self::TOKEN]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sheets.googleapis.com/v4/spreadsheets/test-spreadsheet-not-real/values:batchUpdate'
            && $request->header('Authorization') === ['Bearer '.self::TOKEN]
            && $request->data() === ['valueInputOption' => 'RAW', 'data' => $data]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com'));
    }

    public function test_http_client_retries_429_with_growing_delay_and_gives_up_after_the_last_retry(): void
    {
        Sleep::fake();
        Http::fakeSequence('sheets.googleapis.com/*')->push([], 429)->push([], 429)->push(['totalUpdatedRows' => 1])
            ->push([], 429)->push([], 429)->push([], 429)->push([], 429);
        $client = new HttpSheetsClient(app(TokenProvider::class), 'test-spreadsheet-not-real');

        $client->batchUpdate([['range' => 'Events!A1', 'values' => [['Event ID']]]]);
        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(2000)->milliseconds(), Sleep::for(5000)->milliseconds()]);

        $this->expectException(RequestException::class);
        $client->batchUpdate([['range' => 'Events!A1', 'values' => [['Event ID']]]]);
    }

    public function test_http_client_does_not_retry_other_errors(): void
    {
        Sleep::fake();
        Http::fake(['sheets.googleapis.com/*' => Http::response(['error' => ['message' => 'The caller does not have permission']], 403)]);
        $client = new HttpSheetsClient(app(TokenProvider::class), 'test-spreadsheet-not-real');

        try {
            $client->getValues('Orders!A:Q');
            $this->fail('403 must throw');
        } catch (RequestException $exception) {
            $this->assertSame(403, $exception->response->status());
        }
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_google_token_provider_signs_a_jwt_with_the_base64_key_and_caches_the_token_without_network(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $json = json_encode(['type' => 'service_account', 'client_email' => 'sheets@test-project.iam.gserviceaccount.test', 'private_key' => $pem, 'private_key_id' => 'test-key-id']);
        $requests = [];
        $handler = function (RequestInterface $request) use (&$requests): Response {
            $requests[] = $request;

            return new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'test-access-token-not-real', 'expires_in' => 3600, 'token_type' => 'Bearer']));
        };
        $provider = new GoogleTokenProvider(base64_encode($json), $handler);

        $this->assertSame('test-access-token-not-real', $provider->token());
        $this->assertSame('test-access-token-not-real', $provider->token());
        $this->assertCount(1, $requests);
        parse_str((string) $requests[0]->getBody(), $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        $claims = json_decode(base64_decode(strtr(explode('.', $form['assertion'])[1], '-_', '+/')), true);
        $this->assertSame('sheets@test-project.iam.gserviceaccount.test', $claims['iss']);
        $this->assertSame(GoogleTokenProvider::SCOPE, $claims['scope']);

        $this->expectException(RuntimeException::class);
        (new GoogleTokenProvider('not base64 json', $handler))->token();
    }

    public function test_every_section_5_1_event_of_the_order_lifecycle_lands_in_the_sheets_with_english_headers(): void
    {
        app(SellerSettings::class)->save(['name' => 'TEST UAB', 'company_code' => 'TEST-000000', 'vat_code' => 'TEST-LT000000', 'address' => 'TEST g. 1',
            'vat_payer' => true, 'vat_rate' => 9, 'vat_note' => null, 'series' => 'TEST', 'first_number' => 7]);
        $order = $this->order()->order;
        $this->flow()->confirmPrice($this->admin, $order, $this->prices($order, 2000, 500), 3000);
        $this->flow()->changePrice($this->admin, $order, $this->prices($order, 3000, 500), 3000, 'Material change', 'By phone');
        $void = $this->flow()->requestPayment($this->admin, $order, 1000);
        $this->flow()->cancelPaymentRequest($this->admin, $void);
        $request = $this->flow()->requestPayment($this->admin, $order, 9500);
        $request->update(['stripe_intent_id' => 'pi_test_'.$request->id]);
        $this->sendStripe($this->eventPayload('evt_test_failed', 'payment_intent.payment_failed', $this->intentObject($request, ['status' => 'requires_payment_method', 'amount_received' => 0, 'latest_charge' => null])))->assertOk();
        $this->sendStripe($this->succeededPayload($request, 'evt_test_paid'))->assertOk();
        $this->flow()->startProduction($this->admin, $order);
        $this->flow()->setTracking($this->admin, $order, 'DPD', 'TEST-TRACK-1');
        $this->flow()->advance($this->admin, $order, ProductionStage::QualityCheck);

        $types = IntegrationEvent::pluck('type')->all();
        foreach (['order.submitted', 'order.price_confirmed', 'order.price_changed', 'payment_request.created', 'payment_request.cancelled',
            'payment.failed', 'payment.succeeded', 'invoice.issued', 'order.stage_changed', 'order.tracking_set'] as $type) {
            $this->assertContains($type, $types);
        }
        $this->assertSame([], IntegrationEvent::where('sheets_status', '!=', 'sent')->pluck('type')->all());
        foreach (SheetsProjector::SHEETS as $sheet => $headers) {
            if ($sheet !== 'Requests') {
                $this->assertSame($headers, $this->sheets->grid[$sheet][0], $sheet);
            }
        }
        $projected = array_column($this->sheets->rows('Events'), 0);
        sort($projected, SORT_NUMERIC);
        $this->assertSame(IntegrationEvent::orderBy('id')->pluck('id')->map(fn (int $id): string => (string) $id)->all(), $projected);

        $number = (string) DB::table('order_meta')->where('order_id', $order->id)->value('number');
        $this->assertCount(1, $this->sheets->rows('Orders'));
        $row = $this->sheets->row('Orders', $number);
        $this->assertSame(['Ona TEST', 'ona@example.test', 'UAB TEST', '2', 'quality_check', '95.00', '95.00', '0.00', 'paid', 'ship', 'DPD TEST-TRACK-1', 'TEST-7'],
            [$row['Customer'], $row['Email'], $row['Company'], $row['Files'], $row['Production stage'], $row['Confirmed price EUR'], $row['Paid EUR'],
                $row['Due EUR'], $row['Payment state'], $row['Delivery'], $row['Tracking'], $row['Invoice No']]);
        $this->assertSame(route('lt.admin', ['order' => $order->id]), $row['Admin link']);
        $this->assertSame(IntegrationEvent::latest('id')->firstOrFail()->created_at->utc()->format('Y-m-d H:i:s'), $row['Updated']);

        $this->assertCount(2, $this->sheets->rows('Payments'));
        $paid = $this->sheets->row('Payments', $request->public_id);
        $this->assertSame([$number, '95.00', 'paid', 'ch_test_'.$request->public_id], [$paid['Order No'], $paid['Amount EUR'], $paid['State'], $paid['Stripe reference']]);
        $this->assertNotSame('', $paid['Paid at']);
        $cancelled = $this->sheets->row('Payments', $void->public_id);
        $this->assertSame(['10.00', 'void', ''], [$cancelled['Amount EUR'], $cancelled['State'], $cancelled['Paid at']]);

        $contact = $this->sheets->row('Contacts', 'ona@example.test');
        $this->assertSame(['Ona TEST', '+370 600 00000', 'UAB TEST', 'TEST-123', '', 'no'], [$contact['Name'], $contact['Phone'], $contact['Company'], $contact['Company code'], $contact['VAT code'], $contact['Registered']]);

        $invoiceEvent = IntegrationEvent::where('type', 'invoice.issued')->firstOrFail();
        $this->assertSame([(string) $invoiceEvent->id, $invoiceEvent->created_at->utc()->format('Y-m-d H:i:s'), 'Order '.$number, 'invoice.issued', 'Invoice issued: TEST-7', '95.00'],
            array_values($this->sheets->row('Events', (string) $invoiceEvent->id) ?? []));
        $stage = IntegrationEvent::where('type', 'order.stage_changed')->firstOrFail();
        $this->assertSame('', $this->sheets->row('Events', (string) $stage->id)['Amount EUR'], 'unknown amount is not written as 0');
        $unconfirmed = $this->order('mia@example.test', 'Mia TEST')->order;
        $fresh = $this->sheets->row('Orders', (string) DB::table('order_meta')->where('order_id', $unconfirmed->id)->value('number'));
        $this->assertSame(['', '0.00', '', 'unpaid', 'review'], [$fresh['Confirmed price EUR'], $fresh['Paid EUR'], $fresh['Due EUR'], $fresh['Payment state'], $fresh['Production stage']]);
    }

    public function test_cancel_refund_and_form_events_are_projected(): void
    {
        $order = $this->order()->order;
        $this->flow()->confirmPrice($this->admin, $order, $this->prices($order, 3000, 500), 3000);
        $request = $this->flow()->requestPayment($this->admin, $order, 4000);
        $request->update(['stripe_intent_id' => 'pi_test_'.$request->id]);
        $this->sendStripe($this->succeededPayload($request, 'evt_test_paid'))->assertOk();
        $this->sendStripe($this->eventPayload('evt_test_refund', 'charge.refunded', [
            'id' => 'ch_test_'.$request->public_id, 'object' => 'charge', 'amount' => 4000, 'amount_refunded' => 1000, 'currency' => 'eur', 'refunded' => false,
        ]))->assertOk();
        $this->flow()->cancel($this->admin, $order, 'Customer request');
        $contact = $this->contactRequest();
        $registration = $this->post('/en/training', ['training' => $this->training()->id, 'altcha' => $this->altcha(), 'name' => 'Rūta TEST', 'email' => 'ruta@example.test', 'format' => 'online', 'participants' => 2]);
        $registration->assertSessionHasNoErrors();

        foreach (['payment.refunded', 'order.cancelled', 'contact_request.created', 'training_registration.created'] as $type) {
            $event = IntegrationEvent::where('type', $type)->firstOrFail();
            $this->assertSame('sent', $event->sheets_status, $type);
            $this->assertNotNull($this->sheets->row('Events', (string) $event->id), $type);
        }
        $number = (string) DB::table('order_meta')->where('order_id', $order->id)->value('number');
        $row = $this->sheets->row('Orders', $number);
        $this->assertSame(['cancelled', '30.00', '65.00', 'partial'], [$row['Production stage'], $row['Paid EUR'], $row['Due EUR'], $row['Payment state']]);

        $this->assertSame(['Request ID', 'Type', 'Created', 'Name', 'Email', 'Phone', 'Company', 'Service/Training', 'Summary', 'Attachments', 'Admin link'], $this->sheets->grid['Requests'][0]);
        $request = $this->sheets->row('Requests', 'contact-'.$contact->id);
        $this->assertSame(['contact', 'Jonas Petraitis', 'jonas@example.test', '+370 611 11111', 'UAB Detalė', 'printing', 'Need ten brackets printed in PETG.', '0'],
            [$request['Type'], $request['Name'], $request['Email'], $request['Phone'], $request['Company'], $request['Service/Training'], $request['Summary'], $request['Attachments']]);
        $training = $this->sheets->rows('Requests')[1];
        $this->assertSame(['training', 'Rūta TEST', 'ruta@example.test', 'CAD basics · 2027-02-10', 'Format: online; participants: 2'], [$training[1], $training[3], $training[4], $training[7], $training[8]]);
        $this->assertNotNull($this->sheets->row('Contacts', 'ruta@example.test'));
        $this->assertNotNull($this->sheets->row('Contacts', 'jonas@example.test'));
    }

    public function test_repeated_job_does_not_duplicate_and_two_jobs_of_one_entity_write_one_row_with_the_latest_state(): void
    {
        Queue::fake();
        $order = $this->order()->order;
        $this->flow()->confirmPrice($this->admin, $order, $this->prices($order, 3000, 500), 3000);
        $jobs = Queue::pushed(ProjectSheets::class)->values();
        $this->assertCount(2, $jobs);
        $key = 'sheets:'.$order->getTable().':'.$order->id;
        foreach ($jobs as $job) {
            $this->assertSame('sheets', $job->queue);
            $this->assertTrue($job->afterCommit);
            $this->assertSame($key, $job->entityKey);
            $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
            $this->assertSame($key, $job->middleware()[0]->key);
        }

        $jobs[0]->handle(app(IntegrationEvents::class));
        ProductionState::where('order_id', $order->id)->update(['carrier' => 'DPD', 'tracking_number' => 'CHANGED-1']);
        $jobs[1]->handle(app(IntegrationEvents::class));
        $number = (string) DB::table('order_meta')->where('order_id', $order->id)->value('number');
        $this->assertCount(1, $this->sheets->rows('Orders'));
        $this->assertSame('DPD CHANGED-1', $this->sheets->row('Orders', $number)['Tracking']);
        $this->assertSame('95.00', $this->sheets->row('Orders', $number)['Confirmed price EUR']);

        // Повтор: задание того же события — канал уже sent, захвата нет; даже при сброшенном статусе строк не прибавляется.
        $writes = count($this->sheets->writes);
        $jobs[1]->handle(app(IntegrationEvents::class));
        $this->assertCount($writes, $this->sheets->writes);
        IntegrationEvent::query()->update(['sheets_status' => 'pending']);
        $jobs[0]->handle(app(IntegrationEvents::class));
        $jobs[1]->handle(app(IntegrationEvents::class));
        $this->assertCount(1, $this->sheets->rows('Orders'));
        $this->assertCount(2, $this->sheets->rows('Events'));
        $this->assertCount(1, $this->sheets->rows('Contacts'));
    }

    public function test_manual_sorting_and_deleting_rows_do_not_break_the_upsert_and_formulas_stay_text(): void
    {
        $first = $this->contactRequest('a@example.test', '=HYPERLINK("http://evil.test","x")');
        $second = $this->contactRequest('b@example.test', 'Bob TEST');
        $third = $this->contactRequest('c@example.test', 'Cid TEST');
        $this->assertSame(['contact-'.$first->id, 'contact-'.$second->id, 'contact-'.$third->id], array_column($this->sheets->rows('Requests'), 0));
        $this->assertSame('=HYPERLINK("http://evil.test","x")', $this->sheets->row('Requests', 'contact-'.$first->id)['Name']);

        $this->sheets->swapRows('Requests', 2, 4);
        $this->sheets->deleteRow('Requests', 3);
        $first->update(['phone' => '+370 699 99999']);
        $second->update(['phone' => '+370 688 88888']);
        IntegrationEvent::query()->update(['sheets_status' => 'failed']);
        foreach ([$first, $second] as $request) {
            app(IntegrationEvents::class)->deliverSheets(IntegrationEvent::where('entity_id', $request->id)->where('entity_type', 'contact_requests')->value('id'));
        }

        $this->assertSame(['contact-'.$third->id, 'contact-'.$first->id, 'contact-'.$second->id], array_column($this->sheets->rows('Requests'), 0));
        $this->assertSame('+370 699 99999', $this->sheets->row('Requests', 'contact-'.$first->id)['Phone']);
        $this->assertSame('+370 688 88888', $this->sheets->row('Requests', 'contact-'.$second->id)['Phone']);
    }

    public function test_contacts_registered_and_last_activity_are_computed_from_the_database(): void
    {
        $this->contactRequest('Lina@Example.test', 'Lina TEST');
        $this->assertSame('no', $this->sheets->row('Contacts', 'lina@example.test')['Registered']);

        User::factory()->create(['email' => 'lina@example.test', 'name' => 'Lina Registered']);
        $this->travel(2)->hours();
        $this->post('/en/training', ['training' => $this->training()->id, 'altcha' => $this->altcha(), 'name' => 'Lina T.', 'email' => 'lina@example.test', 'format' => 'aiqme', 'participants' => 1])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $this->sheets->rows('Contacts'));
        $row = $this->sheets->row('Contacts', 'lina@example.test');
        $this->assertSame(['Lina T.', '+370 611 11111', 'UAB Detalė', 'yes'], [$row['Name'], $row['Phone'], $row['Company'], $row['Registered']]);
        $this->assertSame(now()->utc()->format('Y-m-d H:i:s'), $row['Last activity']);

        $this->sheets->grid = [];
        (new RebuildSheets)->handle(app(SheetsProjector::class));
        $this->assertSame($row, $this->sheets->row('Contacts', 'lina@example.test'));
    }

    public function test_without_a_key_events_wait_pending_admin_shows_not_connected_and_are_sent_once_connected(): void
    {
        config(['aiqme.sheets.key' => null]);
        $this->contactRequest();
        $this->assertSame('pending', IntegrationEvent::firstOrFail()->sheets_status);
        $this->assertSame([], $this->sheets->reads);
        $this->travel(6)->minutes();
        Queue::fake();
        $this->artisan('aiqme:integration-retry')->assertSuccessful();
        Queue::assertNotPushed(ProjectSheets::class);

        $this->actingAs($this->admin);
        Livewire::test(Integrations::class)->assertSeeHtml('id="integration-sheets"')
            ->assertSeeInOrder(['NOT CONNECTED', 'Google Sheets', 'The service account key and spreadsheet ID are not set on the server.']);

        $this->connect();
        $this->artisan('aiqme:integration-retry')->assertSuccessful();
        Queue::assertPushedOn('sheets', ProjectSheets::class);
        Queue::pushed(ProjectSheets::class)->each(fn (ProjectSheets $job) => $job->handle(app(IntegrationEvents::class)));
        $this->assertSame('sent', IntegrationEvent::firstOrFail()->sheets_status);
        $this->assertCount(1, $this->sheets->rows('Requests'));
        Livewire::test(Integrations::class)->assertSeeInOrder(['CONNECTED', 'Google Sheets'])
            ->assertDontSee('test-key-not-real')->assertDontSee('test-spreadsheet-not-real');
    }

    public function test_sheets_failure_and_its_retry_do_not_resend_the_mail_and_a_mail_failure_does_not_block_sheets(): void
    {
        Mail::fake();
        $this->sheets->fail = 'Sheets API unavailable (test)';
        $this->contactRequest();
        $event = IntegrationEvent::firstOrFail();
        $this->assertSame(['sent', 'failed', 'Sheets API unavailable (test)'], [$event->mail_status, $event->sheets_status, $event->sheets_error]);
        Mail::assertSent(FormSubmitted::class, 1);

        $this->actingAs($this->admin);
        Livewire::test(Integrations::class)->assertSee('Sheets API unavailable (test)');

        $this->sheets->fail = null;
        $this->travel(6)->minutes();
        $this->artisan('aiqme:integration-retry')->assertSuccessful();
        $this->assertSame(['sent', 'sent', 2, 1], [$event->fresh()->mail_status, $event->fresh()->sheets_status, $event->fresh()->sheets_attempts, $event->fresh()->mail_attempts]);
        Mail::assertSent(FormSubmitted::class, 1);
        $this->assertCount(1, $this->sheets->rows('Requests'));

    }

    public function test_a_failing_mail_transport_does_not_block_sheets_and_the_mail_retry_does_not_touch_the_sheet(): void
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

        $this->contactRequest();
        $event = IntegrationEvent::firstOrFail();
        $this->assertSame(['failed', 'sent'], [$event->mail_status, $event->sheets_status]);
        $writes = count($this->sheets->writes);

        $this->travel(6)->minutes();
        $this->artisan('aiqme:integration-retry')->assertSuccessful();
        $event->refresh();
        $this->assertSame(['failed', 2, 'sent', 1], [$event->mail_status, $event->mail_attempts, $event->sheets_status, $event->sheets_attempts]);
        $this->assertCount($writes, $this->sheets->writes);
        $this->assertCount(1, $this->sheets->rows('Requests'));
    }

    public function test_rebuild_command_only_queues_the_job_and_the_job_rewrites_sheets_in_batches_and_clears_extra_rows_without_mail(): void
    {
        Mail::fake();
        $this->contactRequest();
        Mail::assertSent(FormSubmitted::class, 1);
        $reads = count($this->sheets->reads);
        $writes = count($this->sheets->writes);

        Queue::fake();
        $this->artisan('aiqme:sheets-rebuild')->assertSuccessful();
        Queue::assertPushedOn('sheets', RebuildSheets::class);
        $this->assertCount($reads, $this->sheets->reads);
        $this->assertCount($writes, $this->sheets->writes);

        $now = now();
        foreach (array_chunk(range(1, 5100), 1000) as $chunk) {
            DB::table('integration_events')->insert(array_map(fn (int $i): array => [
                'type' => 'test.bulk', 'entity_type' => 'contact_requests', 'entity_id' => 1, 'data' => json_encode(['summary' => 'Bulk '.$i]),
                'mail_status' => 'none', 'sheets_status' => 'sent', 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
        $this->sheets->batchUpdate([['range' => 'Requests!A2', 'values' => [['manual', 'row']]], ['range' => 'Requests!A6', 'values' => [['', 'stray value in column B']]]]);
        $this->sheets->grid['Orders'] = [['Order No'], ['999', 'deleted order']];
        $this->sheets->writes = [];

        (new RebuildSheets)->handle(app(SheetsProjector::class));

        foreach ($this->sheets->writes as $request) {
            $this->assertCount(1, $request);
            $this->assertLessThanOrEqual(SheetsProjector::BATCH_ROWS, count($request[0]['values']));
        }
        $this->assertCount(5101, $this->sheets->rows('Events'));
        $this->assertSame(['Events!A1', 'Events!A5001'], array_values(array_filter(array_map(fn (array $request): string => $request[0]['range'], $this->sheets->writes), fn (string $range): bool => str_starts_with($range, 'Events'))));
        $this->assertSame(['contact-'.ContactRequest::firstOrFail()->id], array_column($this->sheets->rows('Requests'), 0));
        $this->assertSame([], $this->sheets->rows('Orders'));
        $this->assertSame(SheetsProjector::SHEETS['Orders'], $this->sheets->grid['Orders'][0]);
        Mail::assertSent(FormSubmitted::class, 1);

        config(['aiqme.sheets.spreadsheet_id' => null]);
        $this->artisan('aiqme:sheets-rebuild')->assertFailed();
    }

    public function test_sheet_link_is_saved_in_settings_and_shown_in_integrations_and_customers(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(Integrations::class)->set('sheetsUrl', 'http://docs.google.com/spreadsheets/d/x')->call('saveSheetsUrl')
            ->assertHasErrors('sheetsUrl');
        $this->assertNull(Setting::find(Integrations::SHEETS_URL));
        $this->get('/en/admin/customers')->assertOk()->assertDontSee('Open Google Sheet ↗');

        $url = 'https://docs.google.com/spreadsheets/d/test-sheet-not-real/edit';
        Livewire::test(Integrations::class)->set('sheetsUrl', $url)->call('saveSheetsUrl')->assertHasNoErrors()->assertSee('Saved.');
        $this->assertSame(['url' => $url], Setting::find(Integrations::SHEETS_URL)->value);
        $this->get('/en/admin/integrations')->assertOk()->assertSee('href="'.$url.'"', false)->assertSee('Open Google Sheet ↗');
        $this->get('/lt/administravimas/customers')->assertOk()->assertSee('href="'.$url.'"', false)->assertSee('Atidaryti Google Sheets ↗');
        Livewire::test(Customers::class)->assertSeeHtml('id="customers-sheet"');

        Livewire::test(Integrations::class)->set('sheetsUrl', '')->call('saveSheetsUrl')->assertHasNoErrors();
        $this->assertNull(Setting::find(Integrations::SHEETS_URL));

        $this->actingAs(User::factory()->create());
        $this->get('/en/admin/integrations')->assertNotFound();
    }
}
