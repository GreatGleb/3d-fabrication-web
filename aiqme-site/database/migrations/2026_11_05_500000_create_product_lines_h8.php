<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Покупка товаров «Pirkti» (правка 05.11, H-08): строка заказа Lunar «товар» (product_lines — снимок названия LT/EN,
     * количество и цена за единицу в центах на момент оформления) и пометка заказа «Prekės» (order_meta.products).
     */
    public function up(): void
    {
        Schema::create('product_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->nullable()->constrained('content_items')->nullOnDelete();
            $table->foreignId('tax_class_id')->constrained('lunar_tax_classes');
            $table->json('title');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->timestamps();
        });

        Schema::table('order_meta', function (Blueprint $table) {
            $table->boolean('products')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('order_meta', function (Blueprint $table) {
            $table->dropColumn('products');
        });
        Schema::dropIfExists('product_lines');
    }
};
