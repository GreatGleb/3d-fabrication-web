<?php

namespace Tests\Feature;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLibrary;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Domain\Forms\Altcha;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Капча ALTCHA (правка 28.10 П3): выдача подписанных задач GET /altcha и проверка решений с защитой от повтора. */
class AltchaTest extends TestCase
{
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
