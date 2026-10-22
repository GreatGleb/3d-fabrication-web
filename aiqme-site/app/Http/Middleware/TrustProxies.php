<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Доверенные прокси из config('aiqme.trusted_proxies') в момент запроса (W-20, §6.6): не через env() в bootstrap/app.php —
 * замыкание withMiddleware выполняется до загрузки .env в PHP-FPM.
 */
class TrustProxies extends Middleware
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        return config('aiqme.trusted_proxies');
    }
}
