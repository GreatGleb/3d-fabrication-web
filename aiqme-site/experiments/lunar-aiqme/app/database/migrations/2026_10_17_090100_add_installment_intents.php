<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', fn (Blueprint $t) => $t->string('stripe_intent_id')->nullable()->unique());
    }

    public function down(): void
    {
        Schema::table('payment_requests', fn (Blueprint $t) => $t->dropColumn('stripe_intent_id'));
    }
};
