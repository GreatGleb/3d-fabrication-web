<?php

namespace Tests\Feature\Scenarios;

use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Mail\FormSubmitted;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Сценарий 1 §9.3 (W-30, уровень L): посетитель отправляет контактную форму с 2 вложениями (W-09) → письмо на
 * info@aiqme.com (Mail::fake) → строки Requests, Contacts и Events фейка сетки (W-18) → заявка с вложениями видна
 * администратору в Overview (W-14), файлы скачиваются только им.
 */
class ContactRequestScenarioTest extends TestCase
{
    use RefreshDatabase;
    use ScenarioSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeContentSeeder::class);
        Storage::fake('local');
        Mail::fake();
        $this->connectSheets();
    }

    public function test_contact_form_with_two_attachments_reaches_mail_sheets_and_admin(): void
    {
        $admin = $this->makeAdmin();
        $pdf = "%PDF-1.4\n%drawing\n";
        $stl = "solid part\nendsolid part\n";

        $this->get('/en/contact')->assertOk();
        $this->post('/en/contact', [
            'name' => 'Jonas TEST', 'company' => 'UAB TEST', 'email' => 'jonas@example.test', 'phone' => '+370 611 11111',
            'service' => '3d-printing', 'description' => 'Need ten brackets printed in PETG.', 'deadline' => '2027-03-01',
            'altcha' => $this->altcha(),
            'attachments' => [UploadedFile::fake()->createWithContent('drawing.pdf', $pdf), UploadedFile::fake()->createWithContent('part.stl', $stl)],
        ])->assertRedirect('/en/contact')->assertSessionHasNoErrors();
        $result = Html::xpath($this->get('/en/contact')->getContent())->query('//*[@id="contact-result"]/div')->item(0);
        $this->assertSame('AIQME has received your enquiry. Files attached: 2.', Html::text($result));

        // Письмо — только на info@aiqme.com, ответ — посетителю, со ссылкой на заявку в админке; файлы не вкладываются.
        $request = ContactRequest::sole();
        Mail::assertSent(FormSubmitted::class, 1);
        Mail::assertSent(FormSubmitted::class, fn (FormSubmitted $mail): bool => $mail->hasTo('info@aiqme.com') && ! $mail->hasTo('jonas@example.test')
            && $mail->hasReplyTo('jonas@example.test') && $mail->attachments === []
            && str_contains($mail->render(), route('lt.admin', ['request' => $request->id])));

        // Строки фейка сетки: Requests, Contacts и Events; оба канала события доставлены.
        $event = IntegrationEvent::sole();
        $this->assertSame(['contact_request.created', 'sent', 'sent'], [$event->type, $event->mail_status, $event->sheets_status]);
        $row = $this->sheets->row('Requests', 'contact-'.$request->id);
        $this->assertSame(['contact', 'Jonas TEST', 'jonas@example.test', '+370 611 11111', 'UAB TEST', 'printing', 'Need ten brackets printed in PETG.', '2', route('lt.admin', ['request' => $request->id])],
            [$row['Type'], $row['Name'], $row['Email'], $row['Phone'], $row['Company'], $row['Service/Training'], $row['Summary'], $row['Attachments'], $row['Admin link']]);
        $this->assertCount(1, $this->sheets->rows('Requests'));
        $contact = $this->sheets->row('Contacts', 'jonas@example.test');
        $this->assertSame(['Jonas TEST', '+370 611 11111', 'UAB TEST', 'no'], [$contact['Name'], $contact['Phone'], $contact['Company'], $contact['Registered']]);
        $this->assertSame(['contact_request.created'], $this->eventTypes());
        $this->assertSame('Request contact-'.$request->id, $this->sheets->row('Events', (string) $event->id)['Entity']);
        $this->assertSame([], $this->sheets->rows('Orders'));

        // Админка: заявка в списке Overview и открывается по ссылке из письма; вложения — только администратору.
        $files = [route('en.admin.request-file', ['contactRequest' => $request->id, 'index' => 0]), route('en.admin.request-file', ['contactRequest' => $request->id, 'index' => 1])];
        foreach ($files as $file) {
            $this->get($file)->assertNotFound();
        }
        $this->actingAs($admin);
        $overview = Html::xpath($this->get('/en/admin')->assertOk()->getContent());
        $this->assertSame(['Jonas TESTjonas@example.test', '3D printing', '2', 'Sent'],
            array_slice(Html::texts($overview, '//table[thead/tr/th[.="Service"]]/tbody/tr[1]/td'), 1, 4));
        $this->get(route('lt.admin', ['request' => $request->id]))->assertOk();
        $shown = Html::xpath($this->get('/en/admin?request='.$request->id)->assertOk()->getContent());
        $this->assertStringContainsString('Need ten brackets printed in PETG.', Html::text($shown->query('//div[@id="request-'.$request->id.'"]')->item(0)));
        $this->assertSame([$pdf, $stl], array_map(fn (string $file): string => $this->get($file)->assertOk()->streamedContent(), $files));
    }
}
