<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 技法(docs/schema.md の「methods」。旧 m_method と同じ列)。
 * ID は旧 m_method と同じ値を持つ(docs/schema.md の ID の方針)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('name_eng', 255);
            $table->text('description');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('methods');
    }
};
