<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 旧 DB の ID と新しい DB の ID の対応(docs/schema.md の「legacy_id_maps」)。
 * 指す先の表が行ごとに違うため、外部キーは張らない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_id_maps', function (Blueprint $table) {
            $table->string('legacy_table', 64);
            $table->unsignedInteger('legacy_id');
            $table->string('new_table', 64);
            $table->unsignedBigInteger('new_id');

            $table->primary(['legacy_table', 'legacy_id']);
            // 新から旧へ戻す検証(#35)と、旧 URL の転送に使う
            $table->index(['new_table', 'new_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_id_maps');
    }
};
