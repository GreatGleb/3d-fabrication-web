<?php

namespace App\Domain\Integrations\Sheets;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * SheetsClient на HTTP-клиенте Laravel (§5.3): Sheets API v4 с токеном поставщика. 429 (квота 60 запросов в минуту на
 * пользователя) — повтор с нарастающей задержкой; остальные ошибки и исчерпанные повторы — исключение (канал failed).
 */
final class HttpSheetsClient implements SheetsClient
{
    public const BASE = 'https://sheets.googleapis.com/v4/spreadsheets/';

    /** Задержки повторов при 429, мс. */
    public const RETRY_MS = [2000, 5000, 15000];

    public function __construct(private readonly TokenProvider $tokens, private readonly string $spreadsheetId) {}

    public function getValues(string $range): array
    {
        return $this->request()->get(self::BASE.rawurlencode($this->spreadsheetId).'/values/'.rawurlencode($range))
            ->throw()->json('values', []);
    }

    public function batchUpdate(array $data): void
    {
        $this->request()->post(self::BASE.rawurlencode($this->spreadsheetId).'/values:batchUpdate', [
            'valueInputOption' => 'RAW',
            'data' => $data,
        ])->throw();
    }

    private function request()
    {
        return Http::withToken($this->tokens->token())->acceptJson()->timeout(30)
            ->retry(self::RETRY_MS, when: fn ($exception): bool => $exception instanceof RequestException && $exception->response->status() === 429);
    }
}
