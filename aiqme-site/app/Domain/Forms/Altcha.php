<?php

namespace App\Domain\Forms;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLibrary;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Капча ALTCHA форм (правка 28.10 П3): свои задачи proof-of-work PBKDF2/SHA-256, подписанные ключом ALTCHA_HMAC_KEY,
 * без внешнего сервиса. Без ключа задачи не выдаются и решения не принимаются (отказ закрытый). Решение принимается
 * один раз: подпись задачи запоминается в кэше до её срока. Быстрая проверка keySignature не используется.
 */
final class Altcha
{
    /** Поле формы с решением (base64 JSON виджета). */
    public const FIELD = 'altcha';

    public function configured(): bool
    {
        return (string) config('aiqme.forms.altcha.hmac_key') !== '';
    }

    public function challenge(): Challenge
    {
        [$min, $max] = config('aiqme.forms.altcha.counter');

        return $this->library()->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2,
            cost: config('aiqme.forms.altcha.cost'),
            counter: random_int($min, $max),
            expiresAt: time() + config('aiqme.forms.altcha.expires'),
        ));
    }

    public function verify(mixed $payload): bool
    {
        if (! $this->configured() || ! is_string($payload) || $payload === '' || strlen($payload) > 4096) {
            return false;
        }

        try {
            $options = new VerifySolutionOptions(payload: $payload, algorithm: new Pbkdf2);
            $result = $this->library()->verifySolution($options);
        } catch (Throwable) {
            return false;
        }

        $challenge = $options->payload->challenge;
        $expiresAt = $challenge->parameters->expiresAt;
        if (! $result->verified || ! $expiresAt) {
            return false;
        }

        // Повтор того же решения: подпись уже использована.
        return Cache::add('altcha:'.$challenge->signature, true, max(1, (int) ceil($expiresAt - time())));
    }

    /**
     * @throws ValidationException
     */
    public function verifyOrFail(Request $request): void
    {
        if (! $this->verify($request->input(self::FIELD))) {
            throw ValidationException::withMessages([self::FIELD => __('public.forms.altcha')]);
        }
    }

    private function library(): AltchaLibrary
    {
        return new AltchaLibrary(hmacSignatureSecret: (string) config('aiqme.forms.altcha.hmac_key'));
    }
}
