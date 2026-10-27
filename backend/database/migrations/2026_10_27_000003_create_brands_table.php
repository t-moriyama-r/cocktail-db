<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 銘柄(docs/schema.md の「brands」)。ID は旧 ID のまま入れる(URL の /detail/brand/{id} を変えないため)。
 * 銘柄は木のノードにせず、木のどの深さのノードにもぶら下げる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->index()->constrained('categories')->restrictOnDelete();
            $table->string('name', 255)->index();
            $table->string('name_search', 255)->index();
            $table->string('name_eng', 255)->index();
            $table->decimal('volume', 4, 1);
            $table->text('description')->nullable();
            $table->boolean('is_major')->default(false)->index();
            $table->boolean('is_like')->default(false)->index();
            $table->string('instagram', 255)->nullable();
            $table->string('youtube', 255)->nullable();
            $table->dateTime('youtube_created_at')->nullable()->index();
            $table->unsignedInteger('youtube_start_seconds')->nullable();
            $table->text('image')->nullable();
            $table->text('image_txt')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
