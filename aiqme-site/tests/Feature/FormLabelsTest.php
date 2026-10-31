<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use DOMElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\browser\ContentFactory;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Правка 30.10 (Г4, F-04): в публичных формах и формах кабинета нет звёздочек; поле, которое можно оставить пустым
 * (текст или файл без required), подписано «(neprivaloma)» / «(optional)», обязательное — без пометки; строка
 * form_note («* — būtini laukai.») над контактной формой не выводится.
 */
class FormLabelsTest extends TestCase
{
    use RefreshDatabase;

    private const OPTIONAL = ['lt' => ' (neprivaloma)', 'en' => ' (optional)'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeContentSeeder::class);
    }

    /** @return array<string, array{label: string, required: bool, select: bool}> поле => подпись (текст до поля) */
    private function fields(string $html, string $form): array
    {
        $xpath = Html::xpath($html);
        $nodes = $xpath->query($form);
        $this->assertGreaterThan(0, $nodes->length, $form);
        $fields = [];
        foreach ($xpath->query('.//label[@class="field"]', $nodes->item(0)) as $label) {
            $control = $xpath->query('./input|./select|./textarea', $label)->item(0);
            $this->assertInstanceOf(DOMElement::class, $control);
            $fields[$control->getAttribute('name')] = [
                'label' => trim((string) preg_replace('/\s+/u', ' ', $xpath->evaluate('string(./text()[1])', $label))),
                'required' => $control->hasAttribute('required'),
                'select' => $control->tagName === 'select',
            ];
        }

        return $fields;
    }

    /** @param  array<string, string>  $expected  поле => подпись */
    private function assertLabels(string $locale, string $html, string $form, array $expected): void
    {
        $fields = $this->fields($html, $form);
        $this->assertSame($expected, array_map(fn (array $field): string => $field['label'], $fields), $form);
        foreach ($fields as $name => $field) {
            $this->assertStringNotContainsString('*', $field['label'], "$form $name");
            if (! $field['select']) {
                $this->assertSame(! $field['required'], str_ends_with($field['label'], self::OPTIONAL[$locale]), "$form $name: пометка ↔ required");
            }
        }
    }

    public function test_contact_form_has_no_asterisks_or_form_note_and_marks_optional_fields(): void
    {
        $labels = [
            'lt' => ['/lt/kontaktai', ['name' => 'Vardas', 'company' => 'Įmonė (neprivaloma)', 'email' => 'El. paštas', 'phone' => 'Telefonas (neprivaloma)',
                'service' => 'Paslauga', 'description' => 'Užduoties aprašymas', 'attachments[]' => 'Failai ir nuotraukos (neprivaloma)',
                'deadline' => 'Pageidaujamas terminas (neprivaloma)'], '* — būtini laukai.'],
            'en' => ['/en/contact', ['name' => 'Name', 'company' => 'Company (optional)', 'email' => 'Email', 'phone' => 'Phone (optional)',
                'service' => 'Service', 'description' => 'Describe your task', 'attachments[]' => 'Files & photos (optional)',
                'deadline' => 'Target date (optional)'], '* — required fields.'],
        ];
        foreach ($labels as $locale => [$path, $expected, $note]) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertLabels($locale, $html, '//form[@id="contact-form"]', $expected);
            $this->assertStringNotContainsString($note, $html, $path);
            $this->assertCount(0, Html::xpath($html)->query('//form[@id="contact-form"]/p'), "$path: строка под заголовком формы");
            $this->assertSame(['name', 'email', 'service', 'description'], array_keys(array_filter($this->fields($html, '//form[@id="contact-form"]'),
                fn (array $field): bool => $field['required'])), "$path: required не изменён");
        }
    }

    public function test_training_registration_labels_have_no_asterisks(): void
    {
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        $training = ContentFactory::as($admin)->training('cad-basics', '2027-02-10', [
            'lt' => ['CAD pagrindai', 'Nuotoliniu būdu · grupė'],
            'en' => ['CAD basics', 'Online · group'],
        ], 1);
        $labels = [
            'lt' => ['/lt/paslaugos/3d-modeliavimo-mokymai', ['name' => 'Vardas', 'email' => 'El. paštas', 'format' => 'Dalyvavimo būdas', 'participants' => 'Dalyviai']],
            'en' => ['/en/training', ['name' => 'Name', 'email' => 'Email', 'format' => 'Participation', 'participants' => 'Participants']],
        ];
        foreach ($labels as $locale => [$path, $expected]) {
            $this->assertLabels($locale, $this->get($path)->assertOk()->getContent(), '//form[@id="training-form-'.$training->id.'"]', $expected);
        }
    }

    public function test_calculator_contact_details_have_no_asterisks(): void
    {
        $labels = [
            'lt' => ['/lt/kalkuliatorius', ['name' => 'Vardas, pavardė', 'email' => 'El. paštas', 'company' => 'Įmonė (neprivaloma)',
                'company_code' => 'Įmonės kodas (neprivaloma)', 'vat_code' => 'PVM kodas (neprivaloma)', 'phone' => 'Telefonas',
                'delivery' => 'Pristatymo būdas', 'country' => 'Šalis', 'city' => 'Miestas', 'address' => 'Gatvė ir namo numeris', 'postcode' => 'Pašto kodas']],
            'en' => ['/en/quote', ['name' => 'Full name', 'email' => 'Email', 'company' => 'Company (optional)',
                'company_code' => 'Company number (optional)', 'vat_code' => 'VAT number (optional)', 'phone' => 'Phone',
                'delivery' => 'Delivery method', 'country' => 'Country', 'city' => 'City', 'address' => 'Street address', 'postcode' => 'Postal code']],
        ];
        foreach ($labels as $locale => [$path, $expected]) {
            $this->assertLabels($locale, $this->get($path)->assertOk()->getContent(), '//form[@id="checkout-form"]', $expected);
        }
    }

    public function test_account_forms_have_no_asterisks_and_profile_fields_are_marked_optional(): void
    {
        $auth = [
            'lt' => ['lt.account.register' => ['name' => 'Vardas', 'email' => 'El. paštas', 'password' => 'Slaptažodis'],
                'lt.account.login' => ['email' => 'El. paštas', 'password' => 'Slaptažodis'], 'lt.account.reset' => ['email' => 'El. paštas']],
            'en' => ['en.account.register' => ['name' => 'Name', 'email' => 'Email', 'password' => 'Password'],
                'en.account.login' => ['email' => 'Email', 'password' => 'Password'], 'en.account.reset' => ['email' => 'Email']],
        ];
        foreach ($auth as $locale => $routes) {
            foreach ($routes as $route => $expected) {
                $this->assertLabels($locale, $this->get(route($route))->assertOk()->getContent(), '//form[@id="auth-form"]', $expected);
            }
        }

        $client = User::factory()->create();
        $this->assertLabels('lt', $this->actingAs($client)->get(route('lt.account.addresses'))->assertOk()->getContent(), '//form[@id="address-form"]', [
            'name' => 'Vardas (neprivaloma)', 'company' => 'Įmonė (neprivaloma)', 'company_code' => 'Įmonės kodas (neprivaloma)',
            'vat_code' => 'PVM kodas (neprivaloma)', 'address' => 'Adresas (neprivaloma)', 'city' => 'Miestas (neprivaloma)', 'postcode' => 'Pašto kodas (neprivaloma)',
        ]);
        $this->assertLabels('en', $this->actingAs($client)->get(route('en.account.addresses'))->assertOk()->getContent(), '//form[@id="address-form"]', [
            'name' => 'Name (optional)', 'company' => 'Company (optional)', 'company_code' => 'Company number (optional)',
            'vat_code' => 'VAT number (optional)', 'address' => 'Address (optional)', 'city' => 'City (optional)', 'postcode' => 'Postal code (optional)',
        ]);
    }
}
