<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Telegram-бот контента (§2.5, §4.5, W-19): telegram_bindings — одна строка: администратор, привязанные Telegram
     * user.id и chat.id, SHA-256 действующего кода привязки и последняя ошибка Bot API (без токена); telegram_dialogs —
     * контекст диалога чата с ограниченным сроком; telegram_updates — принятые update_id (дедупликация).
     */
    public function up(): void
    {
        Schema::create('telegram_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->bigInteger('chat_id')->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->char('code_hash', 64)->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_dialogs', function (Blueprint $table) {
            $table->bigInteger('chat_id')->primary();
            $table->json('state');
            $table->timestamp('expires_at');
        });

        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->bigInteger('update_id')->primary();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_dialogs');
        Schema::dropIfExists('telegram_bindings');
    }
};
