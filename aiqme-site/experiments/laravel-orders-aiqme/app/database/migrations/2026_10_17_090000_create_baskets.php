<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baskets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('order_id')->nullable()->unique()->constrained();
            $t->timestamps();
        });
        Schema::create('basket_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('basket_id')->constrained();
            $t->foreignId('stl_part_id')->constrained();
            $t->unsignedInteger('quantity');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('basket_lines');
        Schema::dropIfExists('baskets');
    }
};
