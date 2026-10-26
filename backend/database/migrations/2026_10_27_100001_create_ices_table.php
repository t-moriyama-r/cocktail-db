<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 氷(docs/schema.md の「ices」。旧 m_ice と同じ列)。
 * カクテルの ice_id が旧 ID のまま指すので、ID は旧 ID のまま入れる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('name_eng', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ices');
    }
};
