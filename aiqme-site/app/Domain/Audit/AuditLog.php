<?php

namespace App\Domain\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Простой журнал (§1.4 Audit) поверх spatie/activitylog: событие, объект, канал, автор, данные. */
final class AuditLog
{
    public const LOG_NAME = 'audit';

    /** @param  array<string, mixed>  $properties */
    public static function record(string $event, Model $subject, Channel $channel, ?User $causer, array $properties = []): void
    {
        $logger = activity(self::LOG_NAME)
            ->performedOn($subject)
            ->event($event)
            ->withProperties(['channel' => $channel->value] + $properties);

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        $logger->log($event);
    }
}
