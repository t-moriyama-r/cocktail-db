<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * カテゴリ木の閉包表(docs/schema.md の「category_closures」)。
 * 行を書き換えるのは CategoryTree(#24)だけ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_closures', function (Blueprint $table) {
            // ノードを消したときに、そのノードを祖先・子孫に持つ行も一緒に消えるようにする
            $table->foreignId('ancestor_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('depth');

            $table->primary(['ancestor_id', 'descendant_id']);
            // 祖先を近い順・遠い順に引く(パンくず・根の役割)
            $table->index(['descendant_id', 'depth']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_closures');
    }
};
