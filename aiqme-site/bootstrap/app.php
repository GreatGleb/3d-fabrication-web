<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocaleFromPath;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SetLocaleFromPath::class);
        $middleware->append(SecurityHeaders::class);
        // Гость на действии кабинета и сессия, завершённая после смены пароля (AuthenticateSession), — на вход своей локали.
        $middleware->redirectGuestsTo(fn (Request $request): string => route(($request->segment(1) === 'lt' ? 'lt' : 'en').'.account.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
