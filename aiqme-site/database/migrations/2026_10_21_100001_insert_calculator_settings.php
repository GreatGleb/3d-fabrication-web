<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Начальные настройки калькулятора (W-11): примерные тарифы D-09 (материалы и плотности г/см³, одна цена за грамм
     * десятичной строкой, плата за заказ и за файл в центах, D-08), заполнение по умолчанию 20 % и габарит
     * оборудования D-24 (мм). Тарифы и габарит помечены «не подтверждено» (unconfirmed), пока администратор их не изменит.
     * Запись добавляется, только если ключа ещё нет.
     */
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'calculator',
            'value' => json_encode([
                'materials' => ['PLA' => 1.24, 'PETG' => 1.27, 'ABS' => 1.04],
                'rate_per_gram' => '0.10',
                'order_fee_cents' => 500,
                'file_fee_cents' => 0,
                'default_infill' => 20,
                'max_dimension_mm' => 250,
                'unconfirmed' => ['materials', 'rate_per_gram', 'order_fee_cents', 'file_fee_cents', 'max_dimension_mm'],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'calculator')->delete();
    }
};
