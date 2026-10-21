<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Payments\StripeWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * Webhook Stripe (§3.3 «Ответ webhook», §6.3, §6.5): маршрут вне группы web — без сессии, cookie и CSRF. Подпись
 * Stripe-Signature по сырому телу с допуском по времени (Webhook::constructEvent SDK) секретом STRIPE_WEBHOOK_SECRET,
 * livemode — по среде (live только в production). Неподписанное, с чужим секретом, live вне production — 400 без
 * записи; подписанное — всегда 200 с исходом по факту денег (StripeWebhook).
 */
class StripeController extends Controller
{
    public function __invoke(Request $request, StripeWebhook $webhook): Response
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            return response('', 400);
        }
        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('', 400);
        }
        if ($event->livemode !== app()->isProduction() || ! is_string($event->id) || $event->id === '') {
            return response('', 400);
        }
        $webhook->receive($event, $request->getContent());

        return response('', 200);
    }
}
