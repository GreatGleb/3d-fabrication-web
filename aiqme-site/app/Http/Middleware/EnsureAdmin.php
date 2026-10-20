<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Админка (§6.1): без входа администратора — 404 для гостя и клиента, без редиректа на вход (существование не раскрывается).
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && Gate::forUser($request->user())->allows('admin'), 404);

        return $next($request);
    }
}
