<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Входящее событие Stripe (§2.5, §3.3 «Ответ webhook»): event_id — первичный ключ (повтор — без эффекта, кроме повтора
 * charge.refunded с прежним needs_review), digest тела — только для журнала, запрос — nullable, result — исход;
 * needs_review снимается действием администратора «разобрано» (reviewed_at).
 */
#[Fillable(['event_id', 'type', 'digest', 'payment_request_id', 'result', 'reviewed_at'])]
class PaymentEvent extends Model
{
    public const APPLIED = 'applied';

    public const IGNORED = 'ignored';

    public const REJECTED = 'rejected';

    public const NEEDS_REVIEW = 'needs_review';

    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }
}
