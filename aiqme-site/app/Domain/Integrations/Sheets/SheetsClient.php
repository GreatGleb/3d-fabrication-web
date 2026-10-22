<?php

namespace App\Domain\Integrations\Sheets;

/**
 * Тонкий клиент Google Sheets API v4 (§5.3, W-18): чтение диапазона и запись values:batchUpdate (RAW). Upsert по ключу —
 * в SheetsProjector, не здесь. Рабочая реализация — HttpSheetsClient, в тестах — фейк сетки листа.
 */
interface SheetsClient
{
    /**
     * Значения диапазона A1 (например «Orders!A:Q»): строки до последней непустой, пустые строки — [].
     *
     * @return list<list<string>>
     */
    public function getValues(string $range): array;

    /**
     * Запись диапазонов одним запросом values:batchUpdate (valueInputOption=RAW).
     *
     * @param  list<array{range: string, values: list<list<string>>}>  $data
     */
    public function batchUpdate(array $data): void;
}
