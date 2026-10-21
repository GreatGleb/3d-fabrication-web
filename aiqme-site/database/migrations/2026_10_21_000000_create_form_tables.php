<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Заявки форм и outbox (§2.5, W-09): contact_requests (вложения — список приватных файлов в attachments),
     * training_registrations (снимок названия и даты обучения на момент регистрации) и integration_events — событие
     * с доставкой по каналам mail и sheets, у каждого свой статус, попытки, последняя ошибка и время захвата (§2.7).
     */
    public function up(): void
    {
        Schema::create('contact_requests', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 2);
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('service');
            $table->text('description');
            $table->date('deadline')->nullable();
            $table->json('attachments');
            $table->timestamps();
        });

        Schema::create('training_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 2);
            $table->foreignId('content_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('training_title');
            $table->string('training_date')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('format');
            $table->unsignedSmallInteger('participants');
            $table->timestamps();
        });

        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->json('data');
            foreach (['mail', 'sheets'] as $channel) {
                $table->string($channel.'_status');
                $table->unsignedInteger($channel.'_attempts')->default(0);
                $table->text($channel.'_error')->nullable();
                $table->timestamp($channel.'_claimed_at')->nullable();
            }
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('training_registrations');
        Schema::dropIfExists('contact_requests');
    }
};
