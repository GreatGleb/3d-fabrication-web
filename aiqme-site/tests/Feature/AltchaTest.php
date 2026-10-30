<?php

namespace Tests\Feature;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLibrary;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Forms\Altcha;
use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Forms\Models\TrainingRegistration;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Mail\FormSubmitted;
use App\Models\User;
use Closure;
use Database\Seeders\PrototypeContentSeeder;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\browser\ContentFactory;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Капча ALTCHA (правка 28.10 П3): выдача подписанных задач GET /altcha, проверка решений с защитой от повтора, приём
 * контактной формы, регистрации на обучение и регистрации кабинета только с верным одноразовым решением, разметка виджета.
 */
class AltchaTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = [
        'lt' => 'Patvirtinkite, kad nesate robotas: pažymėkite langelį „Aš nesu robotas“ ir palaukite, kol bus parašyta „Patvirtinta“. Langelis veikia tik įjungus JavaScript.',
        'en' => 'Confirm that you are not a robot: tick “I\'m not a robot” and wait until it says “Verified”. The box needs JavaScript enabled.',
    ];

    public function test_challenge_endpoint_returns_signed_pbkdf2_challenge_without_cookies(): void
    {
        $response = $this->getJson('/altcha')->assertOk();

        $parameters = $response->json('parameters');
        $this->assertSame('PBKDF2/SHA-256', $parameters['algorithm']);
        $this->assertSame(config('aiqme.forms.altcha.cost'), $parameters['cost']);
        $this->assertSame(32, $parameters['keyLength']);
        $this->assertEqualsWithDelta(time() + 1800, $parameters['expiresAt'], 5);
        $this->assertArrayNotHasKey('keySignature', $parameters);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('signature'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertTrue($response->headers->has('Content-Security-Policy'));

        $second = $this->getJson('/altcha')->assertOk();
        $this->assertNotSame($response->json('signature'), $second->json('signature'));
        $this->assertNotSame($parameters['nonce'], $second->json('parameters.nonce'));
    }

    public function test_challenge_endpoint_is_throttled_per_ip(): void
    {
        config(['aiqme.forms.altcha.per_minute' => 2]);

        $this->getJson('/altcha')->assertOk();
        $this->getJson('/altcha')->assertOk();
        $this->getJson('/altcha')->assertStatus(429);
    }

    public function test_challenge_endpoint_without_key_is_unavailable(): void
    {
        config(['aiqme.forms.altcha.hmac_key' => '']);

        $this->getJson('/altcha')->assertStatus(503)->assertJsonMissingPath('signature');
        $this->assertFalse(app(Altcha::class)->configured());
    }

    public function test_solution_is_accepted_once(): void
    {
        $payload = $this->altcha();
        $altcha = app(Altcha::class);

        $this->assertTrue($altcha->verify($payload));
        $this->assertFalse($altcha->verify($payload), 'повтор того же решения');
    }

    public function test_solution_of_endpoint_challenge_is_accepted(): void
    {
        $challenge = $this->getJson('/altcha')->assertOk()->json();

        $this->assertTrue(app(Altcha::class)->verify($this->solve($challenge)));
    }

    /** @return array<string, array{mixed}> */
    public static function garbage(): array
    {
        return [
            'abc' => ['abc'],
            'пустая строка' => [''],
            'base64 {}' => [base64_encode('{}')],
            'тестовый режим виджета' => [base64_encode('{"challenge":null,"solution":null,"test":true}')],
            'массив' => [['challenge' => [], 'solution' => []]],
            'null' => [null],
            '5000 символов' => [str_repeat('A', 5000)],
        ];
    }

    #[DataProvider('garbage')]
    public function test_garbage_is_rejected(mixed $payload): void
    {
        $this->assertFalse(app(Altcha::class)->verify($payload));
    }

    public function test_tampered_counter_is_rejected(): void
    {
        $payload = $this->decoded($this->altcha());
        $payload['solution']['counter']++;

        $this->assertFalse(app(Altcha::class)->verify($this->encoded($payload)));
    }

    public function test_tampered_cost_is_rejected(): void
    {
        $payload = $this->decoded($this->altcha());
        $payload['challenge']['parameters']['cost'] = 2;

        $this->assertFalse(app(Altcha::class)->verify($this->encoded($payload)));
    }

    public function test_challenge_signed_with_another_key_is_rejected(): void
    {
        config(['aiqme.forms.altcha.hmac_key' => 'another-phpunit-key-not-a-secret']);
        $payload = $this->altcha();
        config(['aiqme.forms.altcha.hmac_key' => 'phpunit-only-not-a-secret']);

        $this->assertFalse(app(Altcha::class)->verify($payload));
    }

    public function test_expired_challenge_is_rejected(): void
    {
        config(['aiqme.forms.altcha.expires' => -10]);

        $this->assertFalse(app(Altcha::class)->verify($this->altcha()));
    }

    public function test_solution_is_rejected_without_key(): void
    {
        $payload = $this->altcha();
        config(['aiqme.forms.altcha.hmac_key' => null]);

        $this->assertFalse(app(Altcha::class)->verify($payload));
    }

    public function test_verify_or_fail_reports_localized_message_on_altcha_field(): void
    {
        app()->setLocale('lt');
        $altcha = app(Altcha::class);
        $payload = $this->altcha();

        $altcha->verifyOrFail(Request::create('/', 'POST', [Altcha::FIELD => $payload]));

        try {
            $altcha->verifyOrFail(Request::create('/', 'POST', [Altcha::FIELD => $payload]));
            $this->fail('повтор решения принят');
        } catch (ValidationException $exception) {
            $this->assertSame([Altcha::FIELD => [__('public.forms.altcha')]], $exception->errors());
            $this->assertStringContainsString('Aš nesu robotas', $exception->errors()[Altcha::FIELD][0]);
        }
    }

    public function test_production_values_in_config(): void
    {
        $altcha = (require config_path('aiqme.php'))['forms']['altcha'];

        $this->assertSame(5000, $altcha['cost']);
        $this->assertSame([5000, 10000], $altcha['counter']);
        $this->assertSame(1800, $altcha['expires']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function forms(): array
    {
        return [
            'контакты EN' => ['/en/contact', 'en', 'contact'],
            'контакты LT' => ['/lt/kontaktai', 'lt', 'contact'],
            'обучение EN' => ['/en/training', 'en', 'training'],
            'регистрация LT' => ['/lt/paskyra/register', 'lt', 'register'],
            'регистрация EN' => ['/en/account/register', 'en', 'register'],
        ];
    }

    #[DataProvider('forms')]
    public function test_form_is_accepted_only_with_a_valid_unused_solution(string $url, string $locale, string $form): void
    {
        Mail::fake();
        $this->seed(PrototypeContentSeeder::class);
        $this->training();
        // Двенадцать отправок одной формы с одного IP — больше обычного лимита throttle:forms.
        config(['aiqme.forms.per_hour' => 100]);
        $data = $this->formData($form, $locale);
        $rejected = [
            'без поля' => null,
            'abc' => 'abc',
            'base64 {}' => base64_encode('{}'),
            'тестовый режим виджета' => base64_encode('{"challenge":null,"solution":null,"test":true}'),
            'массив' => ['challenge' => [], 'solution' => []],
            '5000 символов' => str_repeat('A', 5000),
            'подделка counter' => $this->tampered(fn (array &$payload) => $payload['solution']['counter']++),
            'подделка cost' => $this->tampered(fn (array &$payload) => $payload['challenge']['parameters']['cost'] = 2),
            'чужой ключ' => $this->foreignKeyPayload(),
            'просрочка' => $this->expiredPayload(),
        ];
        foreach ($rejected as $case => $payload) {
            $this->from($url)->post($url, [...$data('reject@example.test'), ...($payload === null ? [] : ['altcha' => $payload])])
                ->assertRedirect($url)->assertSessionHasErrors(['altcha' => self::MESSAGE[$locale]]);
            $this->assertStored($form, 0, $case);
        }
        Mail::assertNothingSent();
        $this->assertGuest();

        $payload = $this->altcha();
        $this->from($url)->post($url, [...$data('first@example.test'), 'altcha' => $payload])->assertSessionHasNoErrors();
        $this->assertStored($form, 1, 'верное решение');
        if ($form === 'register') {
            $this->assertAuthenticatedAs(User::where('email', 'first@example.test')->sole());
            auth()->logout();
        } else {
            Mail::assertSent(FormSubmitted::class, 1);
        }

        // Повтор того же решения (для регистрации — с другим e-mail) не принимается.
        $this->from($url)->post($url, [...$data('second@example.test'), 'altcha' => $payload])
            ->assertRedirect($url)->assertSessionHasErrors(['altcha' => self::MESSAGE[$locale]]);
        $this->assertStored($form, 1, 'повтор');
        Mail::assertSent(FormSubmitted::class, $form === 'register' ? 0 : 1);
    }

    public function test_rejected_contact_form_keeps_fields_and_shows_the_message(): void
    {
        $this->seed(PrototypeContentSeeder::class);
        $this->from('/en/contact')->post('/en/contact', $this->formData('contact', 'en')('ona@example.test'));

        $xpath = Html::xpath($this->get('/en/contact')->getContent());
        $this->assertSame('Ona Petraitė', $xpath->query('//form[@id="contact-form"]//input[@name="name"]')->item(0)->getAttribute('value'));
        $this->assertSame(self::MESSAGE['en'], Html::text($xpath->query('//form[@id="contact-form"]//*[@role="alert"]')->item(0)));
    }

    public function test_rejected_training_registration_reopens_its_modal_with_the_message(): void
    {
        $this->seed(PrototypeContentSeeder::class);
        $training = $this->training();
        $this->from('/en/training')->post('/en/training', $this->formData('training', 'en')('jonas@example.test'))->assertRedirect('/en/training');

        $xpath = Html::xpath($this->get('/en/training')->getContent());
        $modal = '//div[@id="training-modal-'.$training->id.'"]';
        $this->assertCount(1, $xpath->query($modal.'[@data-modal-autoopen]'));
        $this->assertSame(self::MESSAGE['en'], Html::text($xpath->query($modal.'//*[@role="alert"]')->item(0)));
    }

    public function test_honeypot_is_checked_before_the_captcha(): void
    {
        Mail::fake();
        $this->seed(PrototypeContentSeeder::class);
        $this->training();
        $this->from('/en/contact')->post('/en/contact', [...$this->formData('contact', 'en')('bot@example.test'), 'website_hp' => 'x', 'altcha' => $this->altcha()])
            ->assertSessionHasErrors('form')->assertSessionDoesntHaveErrors('altcha');
        $this->from('/en/training')->post('/en/training', [...$this->formData('training', 'en')('bot@example.test'), 'website_hp' => 'x', 'altcha' => $this->altcha()])
            ->assertSessionHasErrors('form')->assertSessionDoesntHaveErrors('altcha');

        $this->assertStored('contact', 0);
        $this->assertStored('training', 0);
        Mail::assertNothingSent();
    }

    public function test_forms_are_not_accepted_without_key(): void
    {
        Mail::fake();
        $this->seed(PrototypeContentSeeder::class);
        $contact = $this->altcha();
        $register = $this->altcha();
        config(['aiqme.forms.altcha.hmac_key' => '']);

        $this->from('/en/contact')->post('/en/contact', [...$this->formData('contact', 'en')('ona@example.test'), 'altcha' => $contact])
            ->assertSessionHasErrors(['altcha' => self::MESSAGE['en']]);
        $this->from('/en/account/register')->post('/en/account/register', [...$this->formData('register', 'en')('ona@example.test'), 'altcha' => $register])
            ->assertSessionHasErrors(['altcha' => self::MESSAGE['en']]);

        $this->assertStored('contact', 0);
        $this->assertStored('register', 0);
        $this->assertGuest();
        Mail::assertNothingSent();
    }

    public function test_widget_stands_before_submit_only_on_the_three_forms(): void
    {
        $this->seed(PrototypeContentSeeder::class);
        $training = $this->training();
        $pages = [
            ['/en/contact', '//form[@id="contact-form"]', 'en'],
            ['/lt/kontaktai', '//form[@id="contact-form"]', 'lt'],
            ['/en/training', '//form[@id="training-form-'.$training->id.'"]', 'en'],
            ['/en/services/3d-modelling-training', '//form[@id="training-form-'.$training->id.'"]', 'en'],
            ['/lt/mokymai', '//form[@id="training-form-'.$training->id.'"]', 'lt'],
            ['/lt/paskyra/register', '//form[@id="auth-form"][@data-mode="register"]', 'lt'],
            ['/en/account/register', '//form[@id="auth-form"][@data-mode="register"]', 'en'],
            ['/ru/contact', '//form[@id="contact-form"]', 'en'],
        ];
        foreach ($pages as [$url, $form, $language]) {
            if ($url === '/ru/contact') {
                $this->actingAs(User::where('is_admin', true)->sole());
            }
            $xpath = $this->page($url);
            $this->assertCount(1, $xpath->query($form), $url);
            $widgets = $xpath->query($form.'//altcha-widget');
            $this->assertCount(1, $widgets, $url);
            $this->assertCount(1, $xpath->query($form.'//altcha-widget[@challenge="/altcha"][@auto="onfocus"][@language="'.$language.'"][@configuration=\'{"hideFooter":true}\'][not(@name)]'), $url);
            $this->assertSame(['altcha-widget', 'button'], array_map(fn ($node) => $node->nodeName,
                iterator_to_array($xpath->query($form.'//altcha-widget | '.$form.'//button[@type="submit"]'))), $url);
            $this->assertCount(1, $xpath->query('//altcha-widget'), $url.': виджет только в форме');
        }
        auth()->logout();

        foreach (['/en/account/login', '/lt/paskyra/login', '/en/account/reset', '/lt/paskyra/reset', '/en/quote', '/lt/kalkuliatorius'] as $url) {
            $xpath = $this->page($url);
            $this->assertCount(0, $xpath->query('//altcha-widget'), $url);
        }
        $this->assertCount(1, $this->page('/en/quote')->query('//form[@id="checkout-form"]'));
    }

    private function page(string $url): DOMXPath
    {
        return Html::xpath($this->get($url)->assertOk()->getContent());
    }

    private function training(): ContentItem
    {
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        return ContentFactory::as($admin)->training('cad-basics', '2027-02-10', [
            'lt' => ['CAD pagrindai', 'Nuotoliniu būdu · grupė'],
            'en' => ['CAD basics', 'Online · group'],
        ], 1);
    }

    /** @return Closure(string): array<string, mixed> поля формы без капчи для e-mail */
    private function formData(string $form, string $locale): Closure
    {
        return match ($form) {
            'contact' => fn (string $email): array => ['name' => 'Ona Petraitė', 'email' => $email,
                'service' => $locale === 'lt' ? '3d-spausdinimas' : '3d-printing', 'description' => 'Need ten brackets printed in PETG.'],
            'training' => fn (string $email): array => ['training' => ContentItem::where('key', 'cad-basics')->value('id'),
                'name' => 'Jonas', 'email' => $email, 'format' => $locale === 'lt' ? 'aiqme' : 'online', 'participants' => 2],
            'register' => fn (string $email): array => ['name' => 'Rūta', 'email' => $email, 'password' => 'long-enough-1'],
        };
    }

    private function assertStored(string $form, int $count, string $case = ''): void
    {
        $this->assertSame($form === 'contact' ? $count : 0, ContactRequest::count(), $case);
        $this->assertSame($form === 'training' ? $count : 0, TrainingRegistration::count(), $case);
        $this->assertSame($form === 'register' ? $count : 0, User::where('is_admin', false)->count(), $case);
        $this->assertSame($form === 'register' ? 0 : $count, IntegrationEvent::count(), $case);
    }

    /** @param Closure(array<string, mixed>&): mixed $change */
    private function tampered(Closure $change): string
    {
        $payload = $this->decoded($this->altcha());
        $change($payload);

        return $this->encoded($payload);
    }

    private function foreignKeyPayload(): string
    {
        config(['aiqme.forms.altcha.hmac_key' => 'another-phpunit-key-not-a-secret']);
        $payload = $this->altcha();
        config(['aiqme.forms.altcha.hmac_key' => 'phpunit-only-not-a-secret']);

        return $payload;
    }

    private function expiredPayload(): string
    {
        $expires = config('aiqme.forms.altcha.expires');
        config(['aiqme.forms.altcha.expires' => -10]);
        $payload = $this->altcha();
        config(['aiqme.forms.altcha.expires' => $expires]);

        return $payload;
    }

    /** @param array<string, mixed> $challenge */
    private function solve(array $challenge): string
    {
        $challenge = Challenge::fromArray($challenge);
        $solution = (new AltchaLibrary)->solveChallenge(new SolveChallengeOptions(algorithm: new Pbkdf2, challenge: $challenge));

        return (new Payload($challenge, $solution))->toBase64();
    }

    /** @return array<string, mixed> */
    private function decoded(string $payload): array
    {
        return json_decode(base64_decode($payload), true);
    }

    /** @param array<string, mixed> $payload */
    private function encoded(array $payload): string
    {
        return base64_encode(json_encode($payload));
    }
}
