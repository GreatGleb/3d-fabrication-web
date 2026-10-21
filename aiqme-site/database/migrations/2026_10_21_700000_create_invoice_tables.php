<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Счета (§2.5, §3.5, W-17): выпущенный счёт со снимком реквизитов продавца и покупателя, суммами и путём PDF; строки
     * счёта с VAT, выделенным из брутто. Основание выпуска — итоговый счёт заказа (D-04): один на заказ. Номер — серия +
     * счётчик counters «invoices:<серия>» (W-17). Настройки продавца — ключ settings «invoices» (без значений по умолчанию).
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('lunar_orders');
            $table->string('series');
            $table->unsignedBigInteger('number');
            $table->string('kind');
            $table->string('locale', 2);
            $table->json('seller');
            $table->json('buyer');
            $table->unsignedBigInteger('net_total');
            $table->unsignedBigInteger('vat_total');
            $table->unsignedBigInteger('total');
            $table->string('pdf_path')->unique();
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->unique(['series', 'number']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('net');
            $table->unsignedBigInteger('vat');
            $table->unsignedBigInteger('gross');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
