<?php

namespace App\Domain\Orders;

/**
 * Стадии заказа из прототипа (§3.4), отдельно от оплаты: review → confirmed → in_production → quality_check → ready →
 * shipped (только при доставке) → completed; самовывоз — из ready прямо в completed. Из любой стадии до completed —
 * cancelled.
 */
enum ProductionStage: string
{
    case Review = 'review';
    case Confirmed = 'confirmed';
    case InProduction = 'in_production';
    case QualityCheck = 'quality_check';
    case Ready = 'ready';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Единственная следующая стадия (переходы только вперёд, без пропусков) или null у конечных. */
    public function next(bool $pickup): ?self
    {
        return match ($this) {
            self::Review => self::Confirmed,
            self::Confirmed => self::InProduction,
            self::InProduction => self::QualityCheck,
            self::QualityCheck => self::Ready,
            self::Ready => $pickup ? self::Completed : self::Shipped,
            self::Shipped => self::Completed,
            self::Completed, self::Cancelled => null,
        };
    }
}
