<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_admin')->default(false));
        Schema::create('stl_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('original_name');
            $table->string('path')->unique();
            $table->string('sha256', 64);
            $table->json('parameters');
            $table->unsignedBigInteger('estimate');
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('reference')->nullable()->unique();
            $table->string('status');
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('sub_total');
            $table->unsignedBigInteger('total');
            $table->string('state')->default('review');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('stl_part_id')->constrained('stl_parts');
            $table->string('description');
            $table->string('option');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('sub_total');
            $table->unsignedBigInteger('total');
            $table->json('meta');
            $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->string('type');
            $table->boolean('success');
            $table->string('driver');
            $table->unsignedBigInteger('amount');
            $table->string('reference');
            $table->string('status');
            $table->timestamp('captured_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('price_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('actor_id')->constrained('users');
            $table->unsignedInteger('revision');
            $table->json('before');
            $table->json('after');
            $table->text('reason');
            $table->string('agreement_reference');
            $table->timestamp('created_at');
            $table->unique(['order_id', 'revision']);
        });
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained('orders');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('state')->default('pending');
            $table->string('provider_reference')->nullable()->unique();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained('transactions');
            $table->timestamps();
        });
        Schema::create('payment_events', function (Blueprint $table) {
            $table->string('event_id')->primary();
            $table->foreignId('request_id')->constrained('payment_requests');
            $table->string('digest', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['payment_events', 'payment_requests', 'price_revisions', 'transactions', 'order_lines', 'orders', 'stl_parts'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
