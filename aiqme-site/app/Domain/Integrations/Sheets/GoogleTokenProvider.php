<?php

namespace App\Domain\Integrations\Sheets;

use Google\Auth\Credentials\ServiceAccountCredentials;
use RuntimeException;

/**
 * Токен сервисного аккаунта Google (google/auth, §5.3): ключ — JSON сервисного аккаунта в base64 из .env
 * (aiqme.sheets.key), отдельного файла нет. Токен живёт в памяти процесса до истечения (минус минута).
 * $httpHandler — обработчик запроса google/auth (по умолчанию его Guzzle); тест подставляет свой без сети.
 */
final class GoogleTokenProvider implements TokenProvider
{
    public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    private ?string $token = null;

    private int $expiresAt = 0;

    /** @param  ?callable  $httpHandler */
    public function __construct(private readonly ?string $keyBase64, private $httpHandler = null) {}

    public function token(): string
    {
        if ($this->token !== null && time() < $this->expiresAt) {
            return $this->token;
        }
        $key = json_decode((string) base64_decode((string) $this->keyBase64, true), true);
        if (! is_array($key)) {
            throw new RuntimeException('Google service account key is not set or is not base64-encoded JSON.');
        }
        $result = (new ServiceAccountCredentials(self::SCOPE, $key))->fetchAuthToken($this->httpHandler);
        if (! isset($result['access_token'])) {
            throw new RuntimeException('Google did not return an access token.');
        }
        $this->token = $result['access_token'];
        $this->expiresAt = time() + (int) ($result['expires_in'] ?? 3600) - 60;

        return $this->token;
    }
}
