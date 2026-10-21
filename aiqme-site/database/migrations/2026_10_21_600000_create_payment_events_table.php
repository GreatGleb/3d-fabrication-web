<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Webhook Stripe (§2.5, §3.3, W-28): входящие события payment_events (PK — event_id Stripe, digest тела только для
     * журнала, ссылка на запрос nullable, result, reviewed_at) и частичный уникальный индекс заряда на транзакциях
     * Lunar capture (§3.3 «Запись»; vendor/ не меняется).
     */
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->string('event_id')->primary();
            $table->string('type');
            $table->string('digest', 64);
            $table->foreignId('payment_request_id')->nullable()->constrained();
            $table->string('result');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        DB::statement("CREATE UNIQUE INDEX lunar_transactions_capture_reference_unique ON lunar_transactions (reference) WHERE type = 'capture'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS lunar_transactions_capture_reference_unique');
        Schema::dropIfExists('payment_events');
    }
};
