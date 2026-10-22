<?php

namespace App\Domain\Integrations\Sheets;

use App\Domain\Integrations\IntegrationEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Задание канала sheets события outbox (очередь sheets с одним worker, §5.3, §8.4): вся логика —
 * IntegrationEvents::deliverSheets. Задания одной сущности не идут одновременно (WithoutOverlapping по ключу сущности);
 * занятое задание возвращается в очередь через 10 секунд.
 */
class ProjectSheets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public int $eventId, public string $entityKey)
    {
        $this->onQueue('sheets');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->entityKey))->releaseAfter(10)->expireAfter(300)];
    }

    public function handle(IntegrationEvents $events): void
    {
        $events->deliverSheets($this->eventId);
    }
}
