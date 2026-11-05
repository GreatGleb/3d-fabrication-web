<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Прежний список материалов фрезеровки (миграция 2026_11_03_100000, правка 02.11, Г6). */
    public const OLD_MILLING_MATERIALS = [
        ['lt' => 'Aliuminis', 'en' => 'Aluminium'],
        ['lt' => 'Plienas', 'en' => 'Steel'],
        ['lt' => 'Nerūdijantis plienas', 'en' => 'Stainless steel'],
        ['lt' => 'Žalvaris', 'en' => 'Brass'],
        ['lt' => 'Plastikas', 'en' => 'Plastic'],
    ];

    /** Материалы фрезеровки по правке 05.11, H-05 (п. 11 PDF рев.4). */
    public const MILLING_MATERIALS = [
        ['lt' => 'Spalvotieji metalai', 'en' => 'Non-ferrous metals'],
        ['lt' => 'Mediena', 'en' => 'Wood'],
        ['lt' => 'Plastikas', 'en' => 'Plastic'],
        ['lt' => 'Reikia konsultacijos', 'en' => 'Consultation needed'],
    ];

    /** Списки, которые убираются: толщина и глубина вводятся числом (H-05). down возвращает их значения миграции Г6. */
    public const LISTS = [
        'milling_thicknesses_mm' => [1, 2, 3, 4, 5, 6, 8, 10],
        'engraving_depths_mm' => [0.1, 0.2, 0.3, 0.5],
    ];

    /**
     * Правка 05.11, H-05: материалы фрезеровки меняются, только если список прежний (иначе его изменил администратор);
     * списки толщин и глубин удаляются из записи настроек.
     */
    public function up(): void
    {
        $this->change(function (array $value): array {
            if (($value['milling_materials'] ?? null) === self::OLD_MILLING_MATERIALS) {
                $value['milling_materials'] = self::MILLING_MATERIALS;
            }

            return array_diff_key($value, self::LISTS);
        });
    }

    /** Возвращает прежние материалы (только если список остался списком H-05) и списки толщин и глубин, если их нет. */
    public function down(): void
    {
        $this->change(function (array $value): array {
            if (($value['milling_materials'] ?? null) === self::MILLING_MATERIALS) {
                $value['milling_materials'] = self::OLD_MILLING_MATERIALS;
            }

            // Списки — на прежние места: после материалов фрезеровки и гравировки.
            $restored = [];
            foreach ($value as $key => $item) {
                $restored[$key] = $item;
                $list = ['milling_materials' => 'milling_thicknesses_mm', 'engraving_materials' => 'engraving_depths_mm'][$key] ?? null;
                if ($list !== null && ! array_key_exists($list, $value)) {
                    $restored[$list] = self::LISTS[$list];
                }
            }

            return $restored + self::LISTS;
        });
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
