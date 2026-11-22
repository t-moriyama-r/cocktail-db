<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * カクテルの別名(docs/schema.md の「cocktail_aliases」。#82)。同じカクテルが別の名前で呼ばれるとき(レゲエ・パンチ =
 * ピーチウーロン)に、別名でも名前の横断検索で見つかるようにする。旧には無い表。
 *
 * 同じカクテルに、検索用の名前が同じ別名を 2 つ持たせない((cocktail_id, name_search) の unique)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cocktail_aliases', function (Blueprint $table) {
            $table->id();
            // カクテルを消すと別名も消える
            $table->foreignId('cocktail_id')->constrained('cocktails')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('name_search', 255)->index();

            $table->unique(['cocktail_id', 'name_search']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cocktail_aliases');
    }
};
