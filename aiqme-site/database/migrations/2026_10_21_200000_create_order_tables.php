<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Заявка на изготовление (§2.5, §3.1, W-12): приватные STL (stl_files), строки заказа Lunar — StlPart (stl_parts) и
     * служебные OrderCharge (order_charges, вид fee; delivery — W-13), данные заявки (order_meta) и счётчики номеров (counters).
     */
    public function up(): void
    {
        Schema::create('counters', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedBigInteger('value');
        });

        Schema::create('stl_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('order_id')->nullable()->constrained('lunar_orders');
            $table->string('path')->unique();
            $table->string('original_name');
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->unsignedInteger('triangles');
            $table->json('analysis');
            $table->timestamps();
        });

        Schema::create('stl_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stl_file_id')->constrained();
            $table->foreignId('tax_class_id')->constrained('lunar_tax_classes');
            $table->json('parameters');
            $table->json('measurement');
            $table->unsignedBigInteger('estimate');
            $table->timestamps();
        });

        Schema::create('order_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('lunar_orders');
            $table->foreignId('tax_class_id')->constrained('lunar_tax_classes');
            $table->string('kind');
            $table->unsignedBigInteger('amount');
            $table->timestamps();
        });

        Schema::create('order_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('lunar_orders');
            $table->unsignedBigInteger('number')->unique();
            $table->string('guest_token_hash', 64)->nullable()->unique();
            $table->uuid('submission_token')->unique();
            $table->string('submission_digest', 64);
            $table->string('locale', 2);
            $table->json('contact');
            $table->string('delivery');
            $table->json('address')->nullable();
            $table->json('settings');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['order_meta', 'order_charges', 'stl_parts', 'stl_files', 'counters'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
