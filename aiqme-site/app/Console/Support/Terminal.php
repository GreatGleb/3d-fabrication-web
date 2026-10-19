<?php

namespace App\Console\Support;

/**
 * Доступ к терминалу и stdin процесса. Отдельный класс, чтобы тесты могли подменить
 * наличие терминала и содержимое stdin (в PHPUnit у процесса нет TTY).
 */
class Terminal
{
    public function isInteractive(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }

    /**
     * Одна строка из stdin без завершающего перевода строки; null — stdin пуст или закрыт.
     */
    public function readLine(): ?string
    {
        if (! defined('STDIN')) {
            return null;
        }

        $line = fgets(STDIN);

        return $line === false ? null : rtrim($line, "\r\n");
    }
}
