<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Платёжный запрос (§2.5, §3.3): сумма в центах EUR на заказ, состояние по автомату §3.3 (creating/pending/cancelling —
 * открытые, void, paid), флаг сверки needs_review с причиной, PaymentIntent и ключ идемпотентности (ставит W-16 при первом
 * открытии страницы оплаты). Заряды не хранятся — они на транзакциях Lunar capture.
 */
#[Fillable(['public_id', 'order_id', 'amount', 'currency', 'state', 'needs_review', 'review_reason', 'stripe_intent_id', 'idempotency_key', 'intent_first_attempt_at'])]
class PaymentRequest extends Model
{
    public const CREATING = 'creating';

    public const PENDING = 'pending';

    public const CANCELLING = 'cancelling';

    public const VOID = 'void';

    public const PAID = 'paid';

    /** Открытые запросы: резервируют остаток и блокируют изменение цены (§2.5, §3.2). */
    public const OPEN = [self::CREATING, self::PENDING, self::CANCELLING];

    /** Минимальная сумма запроса и ненулевого остатка после него — минимум Stripe для EUR (§3.3), центы. */
    public const MINIMUM = 50;

    protected function casts(): array
    {
        return ['needs_review' => 'boolean', 'intent_first_attempt_at' => 'datetime'];
    }
}
