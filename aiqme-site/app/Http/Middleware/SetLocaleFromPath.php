<?php

namespace App\Http\Middleware;

use App\Support\Seo\StaticPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Глобальный: локаль lt/en по первому сегменту пути, в том числе для 404 неизвестных адресов.
 * /ru не включает здесь: RU включает только RestrictRussianToAdmin после проверки администратора.
 */
class SetLocaleFromPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->segment(1);
        if (in_array($locale, StaticPages::PUBLIC_LOCALES, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
