<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });

        // Администратор один (§2.5): второй строки с is_admin = true база не примет.
        DB::statement('CREATE UNIQUE INDEX users_single_admin ON users (is_admin) WHERE is_admin');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_single_admin');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
