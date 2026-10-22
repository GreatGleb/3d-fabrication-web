<?php

namespace App\Domain\Integrations\Sheets;

/** Поставщик токена доступа Google для HttpSheetsClient (§5.3): рабочий — GoogleTokenProvider, в тестах — заглушка. */
interface TokenProvider
{
    public function token(): string;
}
