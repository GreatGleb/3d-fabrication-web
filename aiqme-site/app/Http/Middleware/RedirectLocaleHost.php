<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Глобальный (правка 11.11, J-02): хосты со своим языком корня (config('aiqme.locale_hosts'), aiqme.lt → lt).
 * Корень ведёт на главную этого языка канонического адреса APP_URL, остальные пути — 301 на тот же путь APP_URL,
 * чтобы у поисковиков не было дублей страниц. Хост самого APP_URL не перенаправляется.
 */
class RedirectLocaleHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $locale = config('aiqme.locale_hosts')[$host] ?? null;
        if ($locale === null || $host === parse_url(config('app.url'), PHP_URL_HOST)) {
            return $next($request);
        }

        $canonical = rtrim(config('app.url'), '/');
        $target = $canonical.($request->getPathInfo() === '/' ? '/'.$locale : $request->getRequestUri());

        return redirect()->away($target, 301);
    }
}
