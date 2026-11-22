<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 名前で商品を探して失敗したノード・銘柄(docs/schema.md の「affiliate_collect_failures」。#695)。旧には無い表。
 *
 * affiliates:collect --new は、失敗した件の affiliates の行を作らない。そのままだと毎回同じ件が先頭に選ばれるので、
 * 失敗した件をここに残し、決めた時刻(retry_after)までは選ばない(docs/decisions/affiliate-freshness.md の 7)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_collect_failures', function (Blueprint $table) {
            $table->id();
            // ノードか銘柄を消すと、失敗の記録も要らなくなる
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            // affiliates と同じく、MariaDB の unique は null 同士を重複と見なさないので、null を 0 に置き換えた列で一意にする
            $table->unsignedBigInteger('brand_key')->storedAs('IFNULL(`brand_id`, 0)');
            // 失敗したときに探した名前。名前が変わったら、retry_after を待たずにまた選ぶ
            $table->string('name', 255);
            $table->unsignedInteger('failure_count');
            $table->text('last_message')->nullable();
            $table->dateTime('retry_after')->index();
            $table->timestamps();

            $table->unique(['category_id', 'brand_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_collect_failures');
    }
};
