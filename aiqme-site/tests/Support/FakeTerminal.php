<?php

namespace Tests\Support;

use App\Console\Support\Terminal;

/**
 * Подмена терминала для тестов aiqme:make-admin: есть ли TTY и что лежит в stdin.
 */
class FakeTerminal extends Terminal
{
    public int $reads = 0;

    public function __construct(private bool $interactive, private ?string $stdin = null) {}

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    public function readLine(): ?string
    {
        $this->reads++;

        if ($this->stdin === null) {
            return null;
        }

        return rtrim(strtok($this->stdin, "\n") ?: '', "\r");
    }
}
