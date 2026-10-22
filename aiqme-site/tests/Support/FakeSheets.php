<?php

namespace Tests\Support;

use App\Domain\Integrations\Sheets\SheetsClient;
use RuntimeException;

/**
 * Фейк Google Таблицы (W-18, §9.2 «Sheets»): сетка листов (лист → строки сверху вниз, строка 1 — индекс 0) с семантикой
 * Sheets API v4 для диапазонов «Лист!A:Q» (чтение до последней непустой строки, пустая строка — [], хвостовые пустые
 * ячейки обрезаются) и «Лист!A<n>» (запись блока строк с колонки A). Тест может переставлять и удалять строки, ронять
 * запросы ($fail) и смотреть журнал чтений и записей.
 */
final class FakeSheets implements SheetsClient
{
    /** @var array<string, list<list<string>>> */
    public array $grid = [];

    /** @var list<string> */
    public array $reads = [];

    /** @var list<list<array{range: string, values: list<list<string>>}>> */
    public array $writes = [];

    public ?string $fail = null;

    public function getValues(string $range): array
    {
        $this->failIfAsked();
        $this->reads[] = $range;
        $rows = array_map(fn (array $row): array => $this->trim($row), $this->grid[explode('!', $range)[0]] ?? []);
        while ($rows !== [] && end($rows) === []) {
            array_pop($rows);
        }

        return $rows;
    }

    public function batchUpdate(array $data): void
    {
        $this->failIfAsked();
        $this->writes[] = $data;
        foreach ($data as $range) {
            preg_match('/^(\w+)!A(\d+)$/', $range['range'], $match) || throw new RuntimeException('Unexpected range '.$range['range']);
            foreach ($range['values'] as $offset => $row) {
                $index = (int) $match[2] - 1 + $offset;
                for ($i = count($this->grid[$match[1]] ?? []); $i < $index; $i++) {
                    $this->grid[$match[1]][$i] = [];
                }
                $this->grid[$match[1]][$index] = $row;
            }
        }
    }

    /**
     * Непустые строки листа без заголовка.
     *
     * @return list<list<string>>
     */
    public function rows(string $sheet): array
    {
        return array_values(array_filter(array_slice($this->getValues($sheet.'!A:Z'), 1), fn (array $row): bool => $row !== []));
    }

    /**
     * Строка листа по ключу в колонке A как «заголовок => значение».
     *
     * @return array<string, string>|null
     */
    public function row(string $sheet, string $key): ?array
    {
        $header = $this->grid[$sheet][0];
        foreach ($this->rows($sheet) as $row) {
            if ($row[0] === $key) {
                return array_combine($header, array_pad($row, count($header), ''));
            }
        }

        return null;
    }

    /** Ручное удаление строки листа (номер строки с 1), строки ниже сдвигаются вверх. */
    public function deleteRow(string $sheet, int $number): void
    {
        array_splice($this->grid[$sheet], $number - 1, 1);
    }

    /** Ручная перестановка строк листа (номера с 1). */
    public function swapRows(string $sheet, int $a, int $b): void
    {
        [$this->grid[$sheet][$a - 1], $this->grid[$sheet][$b - 1]] = [$this->grid[$sheet][$b - 1], $this->grid[$sheet][$a - 1]];
    }

    /**
     * @param  list<string>  $row
     * @return list<string>
     */
    private function trim(array $row): array
    {
        while ($row !== [] && end($row) === '') {
            array_pop($row);
        }

        return $row;
    }

    private function failIfAsked(): void
    {
        if ($this->fail !== null) {
            throw new RuntimeException($this->fail);
        }
    }
}
