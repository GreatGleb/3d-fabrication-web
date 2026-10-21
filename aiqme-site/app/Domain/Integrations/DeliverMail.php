<?php

namespace App\Domain\Integrations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Задание канала mail события outbox (очередь mail, §8.4): вся логика — IntegrationEvents::deliverMail. */
class DeliverMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $eventId)
    {
        $this->onQueue('mail');
    }

    public function handle(IntegrationEvents $events): void
    {
        $events->deliverMail($this->eventId);
    }
}
