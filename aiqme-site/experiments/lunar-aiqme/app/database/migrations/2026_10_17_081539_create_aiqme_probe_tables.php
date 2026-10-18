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
            $table->foreignId('tax_class_id')->constrained('lunar_tax_classes');
            $table->string('original_name');
            $table->string('path')->unique();
            $table->string('sha256', 64);
            $table->json('parameters');
            $table->unsignedBigInteger('estimate');
            $table->timestamps();
        });
        Schema::create('production_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('lunar_orders');
            $table->string('state')->default('review');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('price_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('lunar_orders');
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
            $table->foreignId('order_id')->constrained('lunar_orders');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('state')->default('pending');
            $table->string('provider_reference')->nullable()->unique();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained('lunar_transactions');
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
        foreach (['payment_events', 'payment_requests', 'price_revisions', 'production_states', 'stl_parts'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
