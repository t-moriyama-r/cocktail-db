<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用語辞典(docs/schema.md の「dictionaries」。旧 t_dictionary)。グラスも分類「グラス」の項目としてここに入る。
 * カクテルの glass_dictionary_id が旧 ID のまま指すので、ID は旧 ID のまま入れる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictionaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictionary_category_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('name_eng', 255);
            $table->string('name_search', 255)->index();
            $table->text('description');
            $table->string('youtube', 255)->nullable();
            $table->unsignedInteger('youtube_start_seconds')->nullable();
            $table->string('image', 255)->nullable();
            $table->string('image_txt', 255)->nullable();
            $table->text('image_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionaries');
    }
};
