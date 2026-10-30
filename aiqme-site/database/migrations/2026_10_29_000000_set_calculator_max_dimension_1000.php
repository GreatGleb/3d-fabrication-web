<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Габарит оборудования 1000 мм подтверждён заказчиком 28.10 (D-24, правка П7): исходные 250 мм миграции W-11 меняются
     * на 1000 и снимается пометка «не подтверждено». Значение, которое администратор уже изменил, сохраняется.
     */
    public function up(): void
    {
        $this->replace(250, 1000, fn (array $unconfirmed): array => array_values(array_diff($unconfirmed, ['max_dimension_mm'])));
    }

    public function down(): void
    {
        $this->replace(1000, 250, fn (array $unconfirmed): array => array_values(array_unique([...$unconfirmed, 'max_dimension_mm'])));
    }

    /** @param  callable(list<string>): list<string>  $unconfirmed */
    private function replace(int $from, int $to, callable $unconfirmed): void
    {
        $row = DB::table('settings')->where('key', 'calculator')->first();
        $value = $row ? json_decode($row->value, true) : null;
        if (($value['max_dimension_mm'] ?? null) !== $from) {
            return;
        }
        $value['max_dimension_mm'] = $to;
        $value['unconfirmed'] = $unconfirmed($value['unconfirmed'] ?? []);
        DB::table('settings')->where('key', 'calculator')->update(['value' => json_encode($value), 'updated_at' => now()]);
    }
};
