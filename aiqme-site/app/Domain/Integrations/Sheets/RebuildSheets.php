<?php

namespace App\Domain\Integrations\Sheets;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Пересборка листов из БД (aiqme:sheets-rebuild, §5.3) в той же очереди sheets — единственный писатель таблицы. */
class RebuildSheets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('sheets');
    }

    public function handle(SheetsProjector $projector): void
    {
        $projector->rebuild();
    }
}
