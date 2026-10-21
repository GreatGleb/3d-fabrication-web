<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocaleFromPath;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
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
        // Тело больше post_max_size PHP отбрасывает целиком (до маршрута и локали): заявке калькулятора (W-12) — понятное
        // сообщение о лимите файлов на языке адреса вместо пустой ошибки 413.
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }
            app()->setLocale($request->segment(1) === 'lt' ? 'lt' : 'en');

            return response()->json(['message' => __('calculator.submit.too_large_request', [
                'mb' => intdiv(config('aiqme.calculator.max_file_bytes'), 1024 * 1024),
            ])], 413);
        });
    })->create();
