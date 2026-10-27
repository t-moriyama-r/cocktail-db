<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アフィリエイトの商品(docs/schema.md の「affiliates」)。ノード 1 つ、または銘柄 1 つにつき 1 行。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            // brands を指すが、brands はまだ無い(#22)。外部キー(CASCADE)は brands と両方がそろってから足す
            $table->foreignId('brand_id')->nullable()->index();
            // MariaDB の unique は null 同士を重複と見なさないため、(category_id, brand_id) の unique では
            // 同じノードの「ノードの行」(brand_id が null)が 2 行入ってしまう。null を 0 に置き換えた列で一意にする
            $table->unsignedBigInteger('brand_key')->storedAs('IFNULL(`brand_id`, 0)');

            $table->string('amazon_asin', 10)->nullable();
            $table->text('amazon_title')->nullable();
            $table->unsignedInteger('amazon_price')->nullable();
            $table->text('amazon_image_large_url')->nullable();
            $table->text('amazon_image_medium_url')->nullable();
            $table->text('amazon_image_small_url')->nullable();
            $table->text('amazon_detail_url')->nullable();
            $table->text('amazon_search_url')->nullable();
            $table->dateTime('amazon_fetched_at')->nullable();

            $table->string('rakuten_item_code', 255)->nullable();
            $table->text('rakuten_title')->nullable();
            $table->unsignedInteger('rakuten_price')->nullable();
            $table->text('rakuten_image_url')->nullable();
            $table->text('rakuten_detail_url')->nullable();
            $table->text('rakuten_search_url')->nullable();
            $table->dateTime('rakuten_fetched_at')->nullable();

            $table->string('human_title', 256)->nullable();
            $table->unsignedInteger('human_price')->nullable();
            $table->text('human_image_url')->nullable();
            $table->text('human_amazon_detail_url')->nullable();
            $table->text('human_amazon_search_url')->nullable();

            // 古いものから取り直す順を決めるのに使う
            $table->dateTime('last_viewed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['category_id', 'brand_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
