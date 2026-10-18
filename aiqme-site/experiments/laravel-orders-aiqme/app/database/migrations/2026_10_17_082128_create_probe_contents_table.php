<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probe_contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('locale', 2);
            $table->unsignedInteger('revision');
            $table->timestamp('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probe_contents');
    }
};
