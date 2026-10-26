<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * カテゴリ木のノード(docs/schema.md の「categories」)。
 * 親子は category_closures だけで持ち、parent_id の列は持たない(同じ事実を 2 か所に持つと食い違うため)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('name_search', 255)->index();
            $table->string('name_eng', 255)->index();
            $table->text('description')->nullable();
            // 値を持つのは根だけで、根の数しか値が無いため索引を張らない
            $table->string('role', 20)->nullable();
            $table->boolean('is_major')->default(false)->index();
            $table->boolean('is_like')->default(false)->index();
            $table->boolean('is_powder')->default(false);
            // brands を指すが、brands はまだ無い。外部キーは brands を作るマイグレーション(#22)で足す
            $table->foreignId('famous_brand_id')->nullable();
            $table->string('youtube', 255)->nullable();
            $table->dateTime('youtube_created_at')->nullable()->index();
            $table->unsignedInteger('youtube_start_seconds')->nullable();
            $table->string('image', 255)->nullable();
            $table->string('image_txt', 255)->nullable();
            $table->text('image_url')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
