<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * スキップ語(docs/schema.md の「skip_words」。旧 skip_db の列に id を足したもの)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skip_words', function (Blueprint $table) {
            $table->id();
            $table->string('name', 256)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skip_words');
    }
};
