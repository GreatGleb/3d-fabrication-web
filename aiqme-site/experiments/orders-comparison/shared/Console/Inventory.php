<?php

namespace Comparison\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Order;

class Inventory extends Command
{
    protected $signature = 'comparison:inventory';

    public function handle(): int
    {
        $tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema='public' ORDER BY table_name");
        $this->line(json_encode(['database' => config('database.connections.pgsql.database'), 'lunar_class_present' => class_exists(Order::class), 'tables' => array_column($tables, 'table_name'), 'table_count' => count($tables), 'column_count' => DB::selectOne("SELECT count(*) AS count FROM information_schema.columns WHERE table_schema='public'")->count], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
