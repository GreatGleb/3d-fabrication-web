<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved models кабинета (§1.3, §6.4, W-15): клиент скрывает модель из списка, файл остаётся у заказа.
     */
    public function up(): void
    {
        Schema::table('stl_files', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stl_files', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });
    }
};
