<?php

namespace Tests\Feature;

use App\Domain\Content\Models\ContentItem;
use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Forms\Models\TrainingRegistration;
use App\Domain\Integrations\DeliverMail;
use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Mail\FormSubmitted;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\browser\ContentFactory;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Формы W-09 (§1.2, §2.7, §5.1, §6.4): контактная форма с вложениями, регистрация на обучение, предзаполнение, honeypot и
 * частота, письмо на info@aiqme.com через outbox integration_events, сбой почты и повтор каналов, заявки в админке.
 */
class FormsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeContentSeeder::class);
        Storage::fake('local');
    }

    private function admin(): User
    {
        if ($admin = User::where('is_admin', true)->first()) {
            return $admin;
        }
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        return $admin;
    }

    private function training(): ContentItem
    {
        return ContentFactory::as($this->admin())->training('cad-basics', '2027-02-10', [
            'lt' => ['CAD pagrindai', 'Nuotoliniu būdu · grupė'],
            'en' => ['CAD basics', 'Online · group'],
        ], 1);
    }

    /** @param  array<string, mixed>  $override */
    private function contact(array $override = []): array
    {
        return [
            'name' => 'Ona Petraitė',
            'company' => 'UAB Detalė',
            'email' => 'ona@example.test',
            'phone' => '+370 600 00000',
            'service' => '3d-printing',
            'description' => 'Need ten brackets printed in PETG.',
            'deadline' => '2027-03-01',
            ...$override,
        ];
    }

    /** Транспорт, который всегда падает, как недоступный SMTP. */
    private function failingMail(): void
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
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, ContactRequest::count());
        $this->assertSame(0, TrainingRegistration::count());
        $this->assertSame(0, IntegrationEvent::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_valid_contact_form_stores_request_event_private_files_and_mails_aiqme_only(): void
    {
        Mail::fake();
        $pdf = UploadedFile::fake()->createWithContent('drawing.pdf', "%PDF-1.4\n%test\n");
        $stl = UploadedFile::fake()->createWithContent('part.stl', "solid x\nendsolid x\n");

        $response = $this->post('/en/contact', [...$this->contact(), 'attachments' => [$pdf, $stl]]);

        $response->assertRedirect('/en/contact')->assertSessionHasNoErrors();
        $request = ContactRequest::sole();
        $this->assertSame(['Ona Petraitė', 'ona@example.test', 'printing', 'en', '2027-03-01'],
            [$request->name, $request->email, $request->service, $request->locale, $request->deadline->format('Y-m-d')]);
        $this->assertSame(['drawing.pdf', 'part.stl'], array_column($request->attachments, 'name'));
        foreach ($request->attachments as $file) {
            $this->assertMatchesRegularExpression('~^contact-requests/[0-9a-f-]{36}$~', $file['path'], 'имя — UUID сервера, без расширения клиента');
            Storage::disk('local')->assertExists($file['path']);
            $this->assertSame(hash('sha256', Storage::disk('local')->get($file['path'])), $file['sha256']);
        }

        $event = IntegrationEvent::sole();
        $this->assertSame(['contact_request.created', 'contact_requests', $request->id, 'sent', 'pending', 1],
            [$event->type, $event->entity_type, $event->entity_id, $event->mail_status, $event->sheets_status, $event->mail_attempts]);
        Mail::assertSent(FormSubmitted::class, 1);
        Mail::assertSent(FormSubmitted::class, function (FormSubmitted $mail) use ($request): bool {
            $html = $mail->render();

            return $mail->hasTo('info@aiqme.com') && ! $mail->hasTo('ona@example.test') && $mail->hasReplyTo('ona@example.test')
                && $mail->attachments === [] && str_contains($html, 'Ona Petraitė') && str_contains($html, '3D printing')
                && str_contains($html, route('lt.admin', ['request' => $request->id]));
        });

        $page = $this->get('/en/contact')->assertOk()->getContent();
        $this->assertSame('AIQME has received your enquiry. Files attached: 2.', Html::text(Html::xpath($page)->query('//*[@id="contact-result"]/div')->item(0)));
        $this->assertStringNotContainsStringIgnoringCase('demo', Html::text(Html::xpath($page)->query('//*[@id="contact-result"]')->item(0)));
    }

    public function test_mail_is_rendered_by_the_real_mailer_with_minimal_data(): void
    {
        $this->post('/lt/kontaktai', [...$this->contact(['service' => '3d-spausdinimas', 'description' => str_repeat('Labai ilgas aprašymas. ', 30)]),
            'attachments' => [UploadedFile::fake()->createWithContent('a.png', "\x89PNG\r\n\x1A\nrest")]])->assertSessionHasNoErrors();

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertSame('Nauja projekto užklausa', $email->getSubject());
        $this->assertSame(['info@aiqme.com'], array_map(fn ($address) => $address->getAddress(), $email->getTo()));
        $this->assertSame([], $email->getAttachments(), 'вложения не прикладываются к письму');
        $body = $email->getHtmlBody();
        $this->assertStringContainsString('<td>1</td>', $body, 'число вложений');
        $this->assertStringNotContainsString(str_repeat('Labai ilgas aprašymas. ', 30), $body, 'полный текст — только в админке');
    }

    public function test_invalid_contact_form_stores_nothing_and_sends_nothing(): void
    {
        Mail::fake();
        foreach ([
            'name' => $this->contact(['name' => '']),
            'email' => $this->contact(['email' => 'not-an-email']),
            'service' => $this->contact(['service' => 'unknown-service']),
            'description' => $this->contact(['description' => 'short']),
        ] as $field => $data) {
            $this->post('/en/contact', [...$data, 'attachments' => [UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4')]])
                ->assertSessionHasErrors($field);
        }
        $this->assertNothingStored();
        Mail::assertNothingSent();
    }

    public function test_attachments_follow_section_6_4_limits_and_signatures(): void
    {
        Mail::fake();
        $cases = [
            'too many' => [array_map(fn (int $i) => UploadedFile::fake()->createWithContent("p$i.stl", 'solid'), range(1, 6)), 'attachments'],
            'too large' => [[UploadedFile::fake()->create('big.step', 15361)], 'attachments.0'],
            'extension' => [[UploadedFile::fake()->createWithContent('run.exe', 'MZ')], 'attachments.0'],
            'svg' => [[UploadedFile::fake()->createWithContent('logo.svg', '<svg/>')], 'attachments.0'],
            'fake pdf' => [[UploadedFile::fake()->createWithContent('drawing.pdf', '<html>not a pdf')], 'attachments.0'],
            'fake jpeg' => [[UploadedFile::fake()->createWithContent('photo.jpg', "\x89PNG\r\n\x1A\n")], 'attachments.0'],
            'fake png' => [[UploadedFile::fake()->createWithContent('photo.png', 'GIF89a')], 'attachments.0'],
        ];
        foreach ($cases as $case => [$files, $error]) {
            $this->post('/en/contact', [...$this->contact(), 'attachments' => $files])->assertSessionHasErrors($error);
            $this->assertNothingStored();
        }
        $this->from('/en/contact')->post('/en/contact', [...$this->contact(), 'attachments' => [UploadedFile::fake()->create('big.step', 15361)]]);
        $this->assertSame('File exceeds the 15 MB limit', Html::text(Html::xpath($this->get('/en/contact')->getContent())->query('//*[@id="field-attachments---error"]')->item(0)));

        // 5 файлов на пределе; STL/STEP/OBJ не разбираются — произвольные байты принимаются.
        $files = [
            UploadedFile::fake()->create('max.stl', 15360),
            UploadedFile::fake()->createWithContent('model.step', random_bytes(64)),
            UploadedFile::fake()->createWithContent('mesh.obj', '<script>alert(1)</script>'),
            UploadedFile::fake()->createWithContent('photo.JPG', "\xFF\xD8\xFF\xE0rest"),
            UploadedFile::fake()->createWithContent('scan.stp', ''),
        ];
        $this->post('/en/contact', [...$this->contact(), 'attachments' => $files])->assertSessionHasNoErrors();
        $this->assertCount(5, ContactRequest::sole()->attachments);
        $this->assertCount(5, Storage::disk('local')->allFiles());
    }

    public function test_attachment_is_downloaded_only_by_admin_as_opaque_attachment(): void
    {
        Mail::fake();
        $this->post('/en/contact', [...$this->contact(), 'attachments' => [UploadedFile::fake()->createWithContent('mesh.obj', '<script>alert(1)</script>')]]);
        $request = ContactRequest::sole();
        $url = route('en.admin.request-file', ['contactRequest' => $request->id, 'index' => 0]);

        $this->get($url)->assertNotFound();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();

        $response = $this->actingAs($this->admin())->get($url)->assertOk();
        $this->assertSame('attachment; filename=mesh.obj', $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame('<script>alert(1)</script>', $response->streamedContent());
        $this->get(route('en.admin.request-file', ['contactRequest' => $request->id, 'index' => 1]))->assertNotFound();
    }

    public function test_honeypot_rejects_both_forms_without_a_false_success(): void
    {
        Mail::fake();
        // Сообщение сессии проверяется на странице: assertSessionHasErrors здесь состарил бы flash до следующего запроса.
        $this->from('/en/contact')->post('/en/contact', [...$this->contact(), 'website_hp' => 'http://spam.test'])->assertRedirect('/en/contact');
        $this->assertSame('The request was not accepted. Please try again.', Html::text(Html::xpath($this->get('/en/contact')->getContent())
            ->query('//*[@id="contact-result"]/div')->item(0)));
        $training = $this->training();
        $this->post('/en/training', ['training' => $training->id, 'name' => 'Bot', 'email' => 'bot@example.test', 'format' => 'online',
            'participants' => 1, 'website_hp' => 'x'])->assertSessionHasErrors('form');
        $this->assertNothingStored();
        Mail::assertNothingSent();
    }

    public function test_forms_are_throttled_per_ip_across_both_forms(): void
    {
        Mail::fake();
        config(['aiqme.forms.per_hour' => 2]);
        $training = $this->training();
        $this->post('/en/contact', $this->contact())->assertRedirect();
        $this->post('/lt/mokymai', ['training' => $training->id, 'name' => 'Jonas', 'email' => 'jonas@example.test', 'format' => 'aiqme', 'participants' => 2])->assertRedirect();
        $this->post('/en/contact', $this->contact())->assertStatus(429)->assertSee('Too many requests. Please try again later.');
        $this->post('/en/training', ['training' => $training->id])->assertStatus(429);
        $this->assertSame(1, ContactRequest::count());
        $this->assertSame(1, TrainingRegistration::count());
        $this->post('/en/contact', $this->contact(), ['REMOTE_ADDR' => '10.0.0.2'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_prefill_by_section_1_2_contract_is_escaped_trimmed_and_ignores_unknown_slugs(): void
    {
        ContentFactory::as($this->admin())->catalog();
        $field = fn (string $url, string $query): string => (string) Html::xpath($this->get($url)->assertOk()->getContent())->query($query)->item(0)?->textContent;
        $selected = '//select[@name="service"]/option[@selected]/@value';
        $textarea = '//textarea[@name="description"]';

        $this->assertSame('3d-printing', $field('/en/contact?service=3d-printing', $selected));
        $this->assertSame('3d-spausdinimas', $field('/lt/kontaktai?service=3d-spausdinimas', $selected));
        $this->assertSame('', $field('/en/contact?service=no-such-service', $selected));
        $this->assertSame('', $field('/en/contact?service=3d-spausdinimas', $selected), 'slug другой локали не подходит');

        $html = $this->get('/en/contact?service=3d-printing&message='.urlencode('<script>alert(1)</script> & "q"'))->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;q&quot;', $html);

        $long = str_repeat('a', 1990).str_repeat('ž', 20);
        $this->get('/en/contact?message='.urlencode($long))->assertOk();
        $this->assertSame(str_repeat('a', 1990).str_repeat('ž', 10), $field('/en/contact?message='.urlencode($long), $textarea), 'обрезка до 2000 символов, не 4xx');

        $this->assertSame('equipment', $field('/en/contact?product=norda-s1-scanner', $selected));
        $this->assertSame("NORDA S1 scanner\nQuote please", $field('/en/contact?product=norda-s1-scanner&message=Quote+please', $textarea));
        $this->assertSame('', $field('/en/contact?product=no-such-product', $selected));
        $this->assertSame('', $field('/en/contact?product=no-such-product', $textarea));
        $this->assertSame('', $field('/en/contact?product[]=x&service[]=y&message[]=z', $textarea), 'массивы в GET игнорируются');

        // Файлы ссылкой не передаются: поле файлов всегда пустое, параметры с путями не выводятся.
        $html = $this->get('/en/contact?attachments[]=/etc/passwd&file=/etc/passwd')->getContent();
        $this->assertStringNotContainsString('/etc/passwd', $html);
        $this->assertSame('', Html::xpath($html)->query('//input[@type="file"]')->item(0)->getAttribute('value'));
    }

    public function test_training_registration_from_the_modal_stores_snapshot_event_and_mail(): void
    {
        Mail::fake();
        $training = $this->training();
        foreach (['/en/training', '/en/services/3d-modelling-training', '/lt/mokymai', '/lt/paslaugos/3d-modeliavimo-mokymai'] as $url) {
            $xpath = Html::xpath($this->get($url)->assertOk()->getContent());
            $modal = '//div[@id="training-modal-'.$training->id.'"]';
            $this->assertSame('training-modal-'.$training->id, $xpath->query('//a[@data-training="cad-basics"]')->item(0)->getAttribute('data-modal-open'), $url);
            $this->assertCount(1, $xpath->query($modal.'[@data-modal][@hidden]'), $url);
            $this->assertSame(['_token', 'training', 'name', 'email', 'format', 'participants', 'website_hp'],
                array_map(fn ($node) => $node->getAttribute('name'), iterator_to_array($xpath->query($modal.'//form//*[@name]'))), $url);
            $this->assertSame(['online', 'aiqme'], array_map(fn ($node) => $node->getAttribute('value'), iterator_to_array($xpath->query($modal.'//select[@name="format"]/option'))));
            $this->assertSame(['1', '20'], [$xpath->query($modal.'//input[@name="participants"]')->item(0)->getAttribute('min'),
                $xpath->query($modal.'//input[@name="participants"]')->item(0)->getAttribute('max')]);
        }
        $this->assertSame(['Online', 'At AIQME'], Html::texts(Html::xpath($this->get('/en/training')->getContent()), '//select[@name="format"]/option'));

        $this->from('/en/services/3d-modelling-training')->post('/en/training', ['training' => $training->id, 'name' => 'Jonas', 'email' => 'jonas@example.test',
            'format' => 'aiqme', 'participants' => 3])->assertRedirect('/en/services/3d-modelling-training')->assertSessionHasNoErrors();

        $registration = TrainingRegistration::sole();
        $this->assertSame(['Jonas', 'aiqme', 3, 'CAD basics', '2027-02-10', $training->id, 'en'],
            [$registration->name, $registration->format, $registration->participants, $registration->training_title,
                $registration->training_date, $registration->content_item_id, $registration->locale]);
        $event = IntegrationEvent::sole();
        $this->assertSame(['training_registration.created', 'sent', 'pending'], [$event->type, $event->mail_status, $event->sheets_status]);
        Mail::assertSent(FormSubmitted::class, fn (FormSubmitted $mail): bool => $mail->hasTo('info@aiqme.com') && ! $mail->hasTo('jonas@example.test')
            && str_contains($mail->render(), 'CAD basics'));

        $xpath = Html::xpath($this->get('/en/services/3d-modelling-training')->getContent());
        $confirmation = $xpath->query('//div[@id="training-confirmation"][@data-modal-autoopen]')->item(0);
        $this->assertNotNull($confirmation);
        $this->assertSame('training-modal-'.$training->id, $confirmation->getAttribute('data-modal-return'));
        $this->assertSame('Registration received', Html::text($xpath->query('//*[@id="training-confirmation-title"]')->item(0)));
        $this->assertSame('AIQME has received your registration.', Html::text($xpath->query('//div[@id="training-confirmation"]//div[@role="status"]')->item(0)));
    }

    public function test_invalid_training_registration_reopens_its_modal_and_stores_nothing(): void
    {
        Mail::fake();
        $training = $this->training();
        foreach ([['participants' => 21], ['participants' => 0], ['format' => 'onsite'], ['email' => 'x'], ['training' => 999999]] as $override) {
            $this->from('/en/training')->post('/en/training', [...['training' => $training->id, 'name' => 'Jonas', 'email' => 'jonas@example.test',
                'format' => 'online', 'participants' => 1], ...$override])->assertRedirect('/en/training')->assertSessionHasErrors();
        }
        $this->assertNothingStored();
        Mail::assertNothingSent();

        $this->from('/en/training')->post('/en/training', ['training' => $training->id, 'name' => 'Jonas', 'email' => 'jonas@example.test', 'format' => 'online', 'participants' => 21]);
        $xpath = Html::xpath($this->get('/en/training')->getContent());
        $this->assertCount(1, $xpath->query('//div[@id="training-modal-'.$training->id.'"][@data-modal-autoopen]'));
        $this->assertSame('Participants: from 1 to 20.', Html::text($xpath->query('//*[@id="training-modal-'.$training->id.'-participants-error"]')->item(0)));
    }

    public function test_mail_failure_keeps_the_request_marks_the_channel_failed_and_shows_it_in_admin(): void
    {
        $this->failingMail();
        $training = $this->training();
        $this->post('/en/contact', $this->contact())->assertRedirect('/en/contact')->assertSessionHasNoErrors();
        $this->post('/en/training', ['training' => $training->id, 'name' => 'Jonas', 'email' => 'jonas@example.test', 'format' => 'online', 'participants' => 1])
            ->assertSessionHasNoErrors();

        $request = ContactRequest::sole();
        $this->assertSame(1, TrainingRegistration::count());
        foreach (IntegrationEvent::all() as $event) {
            $this->assertSame(['failed', 1, 'pending'], [$event->mail_status, $event->mail_attempts, $event->sheets_status]);
            $this->assertStringContainsString('Connection refused', $event->mail_error);
        }

        $this->actingAs($this->admin());
        $overview = Html::xpath($this->get('/en/admin')->assertOk()->getContent());
        $this->assertSame(['Ona Petraitėona@example.test', '3D printing', '0', 'Failed'],
            array_slice(Html::texts($overview, '//table[thead/tr/th[.="Service"]]/tbody/tr[1]/td'), 1, 4));
        $shown = Html::xpath($this->get('/en/admin?request='.$request->id)->assertOk()->getContent());
        $this->assertStringContainsString('Connection refused', Html::text($shown->query('//div[@id="request-'.$request->id.'"]')->item(0)));
        $this->assertStringContainsString('Need ten brackets printed in PETG.', Html::text($shown->query('//div[@id="request-'.$request->id.'"]')->item(0)));
        $training = Html::xpath($this->get('/en/admin/training')->assertOk()->getContent());
        $this->assertStringContainsString('Failed', Html::text($training->query('//div[@id="training-registrations"]//tbody/tr[1]')->item(0)));
        $this->assertStringContainsString('Connection refused', Html::text($training->query('//div[@id="training-registrations"]//tbody/tr[1]')->item(0)));
        $this->get('/en/admin?request=999999')->assertNotFound();
    }

    public function test_retry_command_is_scheduled_every_five_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'aiqme:integration-retry'));
        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
    }

    public function test_retry_picks_old_undelivered_and_stuck_mail_channels_only_and_leaves_sheets_alone(): void
    {
        $now = Carbon::parse('2027-01-10 12:00:00');
        Carbon::setTestNow($now);
        $make = function (string $status, int $createdAgo, ?int $claimedAgo) use ($now): int {
            return DB::table('integration_events')->insertGetId([
                'type' => 'contact_request.created', 'entity_type' => 'contact_requests', 'entity_id' => 1,
                'data' => json_encode(['kind' => 'contact', 'name' => 'A', 'email' => 'a@example.test', 'subject' => 'S', 'summary' => 'x',
                    'attachments' => 0, 'admin_url' => 'http://localhost/lt/administravimas']),
                'mail_status' => $status, 'sheets_status' => 'pending',
                'mail_claimed_at' => $claimedAgo === null ? null : $now->copy()->subMinutes($claimedAgo),
                'created_at' => $now->copy()->subMinutes($createdAgo), 'updated_at' => $now,
            ]);
        };
        $picked = [$make('pending', 6, null), $make('failed', 30, 6), $make('sending', 30, 6)];
        $skipped = [$make('pending', 2, null), $make('failed', 30, 2), $make('sending', 30, 2), $make('sent', 30, 20)];

        Queue::fake();
        $this->artisan('aiqme:integration-retry')->assertSuccessful();
        Queue::assertPushed(DeliverMail::class, 3);
        $this->assertEqualsCanonicalizing($picked, Queue::pushed(DeliverMail::class)->map(fn (DeliverMail $job) => $job->eventId)->all());
        Queue::assertPushedOn('mail', DeliverMail::class);

        Mail::fake();
        foreach ([...$picked, $skipped[2], $skipped[3]] as $id) {
            app(IntegrationEvents::class)->deliverMail($id);
        }
        Mail::assertSent(FormSubmitted::class, 3);
        $this->assertSame(array_fill(0, 3, 'sent'), IntegrationEvent::whereIn('id', $picked)->orderBy('id')->pluck('mail_status')->all());
        $this->assertSame(['pending', 'failed', 'sending', 'sent'], IntegrationEvent::whereIn('id', $skipped)->orderBy('id')->pluck('mail_status')->all(),
            'недавно захваченный sending и sent задание не трогает');
        $this->assertSame(['pending'], IntegrationEvent::distinct()->pluck('sheets_status')->all(), 'канал sheets до W-18 не трогается');
        $this->assertSame([null], IntegrationEvent::distinct()->pluck('sheets_claimed_at')->all());
        Carbon::setTestNow();
    }

    public function test_two_jobs_of_one_event_send_one_mail_and_failed_channel_is_redelivered_by_retry(): void
    {
        $this->failingMail();
        $this->post('/en/contact', $this->contact())->assertSessionHasNoErrors();
        $event = IntegrationEvent::sole();
        $this->assertSame('failed', $event->mail_status);

        Mail::fake();
        $this->artisan('aiqme:integration-retry')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertSame('failed', $event->fresh()->mail_status, 'failed моложе 5 минут повтор не берёт');

        $this->travel(6)->minutes();
        $this->artisan('aiqme:integration-retry')->assertSuccessful();
        DeliverMail::dispatch($event->id);
        DeliverMail::dispatchSync($event->id);
        Mail::assertSent(FormSubmitted::class, 1);
        $event->refresh();
        $this->assertSame(['sent', 2, null, 'pending', 0], [$event->mail_status, $event->mail_attempts, $event->mail_error, $event->sheets_status, $event->sheets_attempts]);
    }

    public function test_claim_is_one_atomic_update_and_skips_a_channel_taken_by_another_job(): void
    {
        Mail::fake();
        Queue::fake();
        $this->post('/en/contact', $this->contact())->assertSessionHasNoErrors();
        $event = IntegrationEvent::sole();
        // Другое задание захватило канал только что: это задание выходит без письма.
        DB::table('integration_events')->where('id', $event->id)->update(['mail_status' => 'sending', 'mail_claimed_at' => now()]);

        DB::enableQueryLog();
        app(IntegrationEvents::class)->deliverMail($event->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        Mail::assertNothingSent();
        $this->assertCount(1, $queries, 'при 0 строк захвата — выход без чтения и отправки');
        $this->assertStringStartsWith('update "integration_events" set "mail_status" = ?', $queries[0]['query']);
        $this->assertStringContainsString('"mail_status" in (?, ?) or ("mail_status" = ? and "mail_claimed_at" < ?)', $queries[0]['query']);
        $this->assertSame('sending', $event->fresh()->mail_status);

        // Зависший sending (захват больше 5 минут назад) подбирается.
        $this->travel(6)->minutes();
        app(IntegrationEvents::class)->deliverMail($event->id);
        Mail::assertSent(FormSubmitted::class, 1);
        $this->assertSame('sent', $event->fresh()->mail_status);
    }
}
