<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\Channel;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lunar\Models\Order;
use Lunar\Models\Transaction;
use Stripe\Event;
use Stripe\PaymentIntent;

/**
 * Проверенное событие Stripe (§3.3 «Проверки события», «Ответ webhook», «Возвраты»; подпись и livemode проверяет
 * контроллер): запись в payment_events и исход в одной транзакции. Повтор event_id — без эффекта (тело при повторе
 * не сравнивается: digest только для журнала), кроме повтора charge.refunded с прежним needs_review без reviewed_at.
 * payment_intent.succeeded: событие старше API 2022-11-15 — needs_review; денег нет — rejected (исход 4); запрос по
 * metadata.request_id не найден — needs_review (исход 3); иначе — PaymentRecorder (исходы 1–3). payment_failed и прочие
 * типы — ignored; «оплата не прошла» §5.1 — только по найденному неоплаченному запросу.
 */
final class StripeWebhook
{
    /** С этой версии API у PaymentIntent есть latest_charge (§3.3, §5.5). */
    public const MINIMUM_API_VERSION = '2022-11-15';

    public function __construct(private readonly PaymentRecorder $recorder) {}

    public function receive(Event $event, string $payload): void
    {
        DB::transaction(function () use ($event, $payload): void {
            $inserted = DB::table('payment_events')->insertOrIgnore([
                'event_id' => $event->id,
                'type' => (string) $event->type,
                'digest' => hash('sha256', $payload),
                'result' => PaymentEvent::IGNORED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $record = PaymentEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $retry = $record->type === 'charge.refunded' && $record->result === PaymentEvent::NEEDS_REVIEW && $record->reviewed_at === null;
            if ($inserted === 0 && ! $retry) {
                return;
            }
            [$result, $request] = match ($event->type) {
                'payment_intent.succeeded' => $this->succeeded($event),
                'payment_intent.payment_failed' => $this->failed($event->data->object),
                'charge.refunded' => $this->refunded($event->data->object),
                default => [PaymentEvent::IGNORED, null],
            };
            $record->update(['result' => $result, 'payment_request_id' => $request?->id]);
        });
    }

    /** @return array{0: string, 1: ?PaymentRequest} */
    private function succeeded(Event $event): array
    {
        $intent = $event->data->object;
        $request = $this->request($intent);
        if (substr((string) $event->api_version, 0, 10) < self::MINIMUM_API_VERSION) {
            // §5.1 «на сверку» — по найденному запросу; без запроса строки outbox нет (её сущность — числовой id,
            // у события — event_id Stripe), событие видно на сверке в Overview. Так же — исход 3 и неизвестный заряд.
            if ($request !== null) {
                $this->recorder->event('payment.needs_review', $request, 'Payment event of an old Stripe API version');
            }

            return [PaymentEvent::NEEDS_REVIEW, $request];
        }
        $charge = $intent->latest_charge;
        if ($intent->status !== 'succeeded' || (int) $intent->amount_received <= 0 || ! is_string($charge) || $charge === '') {
            return [PaymentEvent::REJECTED, $request];
        }
        if ($request === null) {
            return [PaymentEvent::NEEDS_REVIEW, null];
        }

        return [match ($this->recorder->recordCapture($request, $intent)) {
            PaymentRecorder::CAPTURED => PaymentEvent::APPLIED,
            PaymentRecorder::ALREADY_RECORDED => PaymentEvent::IGNORED,
            PaymentRecorder::NO_MONEY => PaymentEvent::REJECTED,
            default => PaymentEvent::NEEDS_REVIEW,
        }, $request];
    }

    /** @return array{0: string, 1: ?PaymentRequest} */
    private function failed(PaymentIntent $intent): array
    {
        $request = $this->request($intent);
        if ($request !== null) {
            $request = PaymentRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
        }
        if ($request !== null && $request->state !== PaymentRequest::PAID) {
            $this->recorder->event('payment.failed', $request, 'Payment failed');
            AuditLog::record('payment.failed', Order::findOrFail($request->order_id), Channel::Stripe, null, ['public_id' => $request->public_id]);
        }

        return [PaymentEvent::IGNORED, $request];
    }

    /**
     * Charge (amount_refunded накопительный): заряд ищется только как capture с reference = id заряда.
     *
     * @return array{0: string, 1: ?PaymentRequest}
     */
    private function refunded(object $charge): array
    {
        $capture = Transaction::where('type', 'capture')->where('reference', (string) $charge->id)->first();
        if ($capture === null) {
            return [PaymentEvent::NEEDS_REVIEW, null];
        }
        $this->recorder->recordRefund($capture, (int) $charge->amount_refunded);

        return [PaymentEvent::APPLIED, PaymentRequest::where('public_id', $capture->meta['request_id'] ?? null)->first()];
    }

    /** Запрос по metadata.request_id (= public_id, UUID). */
    private function request(object $intent): ?PaymentRequest
    {
        $publicId = $intent->metadata['request_id'] ?? null;

        return is_string($publicId) && Str::isUuid($publicId) ? PaymentRequest::where('public_id', $publicId)->first() : null;
    }
}
