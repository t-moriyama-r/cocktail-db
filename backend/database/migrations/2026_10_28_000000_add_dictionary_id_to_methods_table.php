<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 技法から、その技法を説明する用語(用語辞典の項目)を指す列(docs/schema.md の「methods」。#40)。
 *
 * 旧は技法の ID と用語の ID がたまたま同じ(1〜6)ことに頼って用語へリンクしていた。ID の一致に頼らず、
 * 対応を列に持つ。用語の無い技法もありうるので null 可。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('methods', function (Blueprint $table) {
            $table->foreignId('dictionary_id')->nullable()->after('description')
                ->constrained('dictionaries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('methods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dictionary_id');
        });
    }
};
