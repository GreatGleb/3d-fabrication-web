<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Papildoma informacija» шага 2 калькулятора (правка 28.10, E-05): текст клиента к заявке, виден в карточке заказа.
     */
    public function up(): void
    {
        Schema::table('order_meta', function (Blueprint $table) {
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_meta', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
