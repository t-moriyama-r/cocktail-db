<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * categories.famous_brand_id → brands の外部キー(docs/schema.md の「外部キーと削除」)。
 * categories と brands は互いを指すため、categories を作った時点では brands が無く、列だけが先にある。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->index('famous_brand_id');
            $table->foreign('famous_brand_id')->references('id')->on('brands')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['famous_brand_id']);
            $table->dropIndex(['famous_brand_id']);
        });
    }
};
