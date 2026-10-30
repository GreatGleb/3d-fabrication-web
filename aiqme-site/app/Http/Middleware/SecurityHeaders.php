<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Заголовки безопасности §6.6 на всех ответах: CSP (enforce) с nonce на каждый ответ, nosniff, Referrer-Policy.
 * Nonce получают теги Vite и Livewire (Vite::useCspNonce); <script>/<style> без nonce браузер блокирует.
 * 'unsafe-eval' — признанный компромисс §6.6 (Alpine в Livewire 3.8.10); style-src-attr 'unsafe-inline' — атрибуты
 * style="…" прототипа и Livewire. Домены Stripe Payment Element (W-16) — официальный список Stripe.js §6.6: скрипты
 * js.stripe.com, фреймы (и 3D Secure hooks.stripe.com), запросы к api.stripe.com. Dev-сервер Vite (`npm run dev`) политикой не разрешён:
 * страницы проверяются на собранных ресурсах (`npm run build`). Встроенная карта Google Maps на «Kontaktai» (правка 28.10 П5,
 * узкое исключение из D-20) — дополнительно только https://www.google.com в frame-src; скрипты и запросы к Google не разрешены.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(18));
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', self::policy($nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }

    private static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' https://js.stripe.com https://*.js.stripe.com",
            "style-src 'self' 'nonce-{$nonce}'",
            "style-src-elem 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data:",
            'frame-src https://js.stripe.com https://*.js.stripe.com https://hooks.stripe.com https://www.google.com',
            "connect-src 'self' https://api.stripe.com",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
