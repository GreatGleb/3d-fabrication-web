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
 * style="…" прототипа и Livewire. Домены Stripe добавляет W-16. Dev-сервер Vite (`npm run dev`) политикой не разрешён:
 * страницы проверяются на собранных ресурсах (`npm run build`).
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
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'nonce-{$nonce}'",
            "style-src-elem 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data:",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
