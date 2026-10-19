<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Контент AIQME (§2.5, §4.1, §4.2): элементы, переводы по локалям со своей публикацией, версии и строки интерфейса.
 * В content_translations — опубликованная версия перевода (что видит сайт), черновик — последняя строка content_revisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('key')->nullable();
            $table->integer('position')->default(0);
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->unique(['type', 'key']);
            $table->index(['type', 'position']);
        });

        Schema::create('content_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('locale', 5);
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->jsonb('fields')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('published_revision')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['content_item_id', 'locale']);
            $table->unique(['locale', 'type', 'slug']);
            $table->index(['type', 'locale', 'published_at']);
        });

        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_translation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->jsonb('fields')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16);
            $table->timestamp('created_at')->nullable();

            $table->unique(['content_translation_id', 'revision']);
        });

        Schema::create('ui_texts', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 5);
            $table->string('key');
            $table->text('value');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['locale', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_texts');
        Schema::dropIfExists('content_revisions');
        Schema::dropIfExists('content_translations');
        Schema::dropIfExists('content_items');
    }
};
