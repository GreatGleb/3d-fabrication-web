<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Калькулятор с параметрами у каждого файла (правка 02.11, Г6): файлы заказа — STL, STEP, STP, OBJ, DXF, DWG.
     * Разбирается только STL, поэтому у остальных нет triangles, analysis и measurement; estimate NULL — цену позиции
     * назначит специалист при подтверждении. Формат — расширение пути хранения (uuid.<ext>), отдельного поля нет.
     * down возможен, только пока таких строк нет.
     */
    public function up(): void
    {
        Schema::table('stl_files', function (Blueprint $table) {
            $table->unsignedInteger('triangles')->nullable()->change();
            $table->json('analysis')->nullable()->change();
        });
        Schema::table('stl_parts', function (Blueprint $table) {
            $table->json('measurement')->nullable()->change();
            $table->unsignedBigInteger('estimate')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('stl_files')->whereNull('triangles')->orWhereNull('analysis')->exists()
            || DB::table('stl_parts')->whereNull('measurement')->orWhereNull('estimate')->exists()) {
            throw new RuntimeException('Есть файлы или позиции без разбора или оценки: откат схемы невозможен.');
        }
        Schema::table('stl_files', function (Blueprint $table) {
            $table->unsignedInteger('triangles')->nullable(false)->change();
            $table->json('analysis')->nullable(false)->change();
        });
        Schema::table('stl_parts', function (Blueprint $table) {
            $table->json('measurement')->nullable(false)->change();
            $table->unsignedBigInteger('estimate')->nullable(false)->change();
        });
    }
};
