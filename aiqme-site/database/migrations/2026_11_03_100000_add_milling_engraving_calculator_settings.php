<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Настройки фрезеровки и гравировки калькулятора (правка 02.11, Г6): списки материалов (названия LT и EN), толщин и
     * глубин в мм, предел размеров 2000 × 4000 мм. Значения — заглушки README плана до ответа заказчика; ключ добавляется,
     * только если его нет. down удаляет ключ, только если значение осталось прежним.
     */
    public const VALUES = [
        'milling_materials' => [
            ['lt' => 'Aliuminis', 'en' => 'Aluminium'],
            ['lt' => 'Plienas', 'en' => 'Steel'],
            ['lt' => 'Nerūdijantis plienas', 'en' => 'Stainless steel'],
            ['lt' => 'Žalvaris', 'en' => 'Brass'],
            ['lt' => 'Plastikas', 'en' => 'Plastic'],
        ],
        'milling_thicknesses_mm' => [1, 2, 3, 4, 5, 6, 8, 10],
        'engraving_materials' => [
            ['lt' => 'Aliuminis', 'en' => 'Aluminium'],
            ['lt' => 'Nerūdijantis plienas', 'en' => 'Stainless steel'],
            ['lt' => 'Plastikas', 'en' => 'Plastic'],
            ['lt' => 'Mediena', 'en' => 'Wood'],
        ],
        'engraving_depths_mm' => [0.1, 0.2, 0.3, 0.5],
        'milling_max_mm' => [2000, 4000],
    ];

    public function up(): void
    {
        $this->change(fn (array $value): array => $value + self::VALUES);
    }

    public function down(): void
    {
        $this->change(fn (array $value): array => array_filter($value, fn (mixed $item, string $key): bool => ! array_key_exists($key, self::VALUES)
            || $item !== self::VALUES[$key], ARRAY_FILTER_USE_BOTH));
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private function change(callable $change): void
    {
        $row = DB::table('settings')->where('key', 'calculator')->first();
        if ($row === null) {
            return;
        }
        DB::table('settings')->where('key', 'calculator')->update(['value' => json_encode($change(json_decode($row->value, true))), 'updated_at' => now()]);
    }
};
