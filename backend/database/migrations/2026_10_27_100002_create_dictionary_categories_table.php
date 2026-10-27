<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用語辞典の分類(docs/schema.md の「dictionary_categories」。旧 m_dictionary_category と同じ列)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictionary_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('name_eng', 255);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_categories');
    }
};
