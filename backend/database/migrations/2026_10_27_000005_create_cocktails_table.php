<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * カクテル(docs/schema.md の「cocktails」)。ID は旧 ID のまま入れる(URL の /detail/cocktail/{id} を変えないため)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cocktails', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->index();
            $table->string('name_search', 255)->index();
            $table->string('name_eng', 255)->nullable()->index();
            $table->foreignId('base_category_id')->index()->constrained('categories')->restrictOnDelete();
            // methods・dictionaries・ices を指すが、この時点ではどれも無い(#23 で作る)。外部キーは両方の後のマイグレーション(#325)で足す
            $table->foreignId('method_id')->index();
            $table->foreignId('glass_dictionary_id')->nullable()->index();
            $table->foreignId('ice_id')->nullable();
            $table->decimal('volume', 4, 1);
            $table->tinyInteger('sweet_degree')->nullable();
            $table->unsignedTinyInteger('rate')->nullable()->index();
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_draft')->default(false)->index();
            $table->boolean('is_major')->default(false)->index();
            // 数えて書き込むのは RecipeCounter(#156)だけなので、数える前の行は 0 にしておく
            $table->unsignedTinyInteger('recipe_count')->default(0)->index();
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
        Schema::dropIfExists('cocktails');
    }
};
