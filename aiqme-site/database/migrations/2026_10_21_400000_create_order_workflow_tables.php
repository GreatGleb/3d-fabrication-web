<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Правила заказа (§2.5, §3.2–§3.4, W-13): стадия производства с треком (production_states), ревизии цены
     * (price_revisions), платёжные запросы (payment_requests; заряды — на транзакциях Lunar capture, §3.3 «Запись») и
     * сообщения по заказу (order_messages, §1.3).
     */
    public function up(): void
    {
        Schema::create('production_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('lunar_orders');
            $table->string('stage')->default('review');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('price_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('lunar_orders');
            $table->foreignId('actor_id')->constrained('users');
            $table->unsignedInteger('revision');
            $table->json('before');
            $table->json('after');
            $table->text('reason')->nullable();
            $table->string('agreement')->nullable();
            $table->timestamp('created_at');
            $table->unique(['order_id', 'revision']);
        });

        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained('lunar_orders');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('state')->default('pending');
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->string('stripe_intent_id')->nullable()->unique();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('intent_first_attempt_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('lunar_orders');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('kind');
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['order_messages', 'payment_requests', 'price_revisions', 'production_states'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
