<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 銘柄・カクテルの表(#22)と、技法・氷・用語辞典・アフィリエイトの表(#23)のあいだの外部キー
 * (docs/schema.md の「外部キーと削除」)。どちらの表を作る時点でも相手の表が無かったので、列と索引だけが先にある。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cocktails', function (Blueprint $table) {
            // MariaDB は外部キーの列に索引が要り、無ければ外部キーの名前で勝手に作る。名前をほかの索引とそろえるため、先に張る
            $table->index('ice_id');

            $table->foreign('method_id')->references('id')->on('methods')->restrictOnDelete();
            $table->foreign('glass_dictionary_id')->references('id')->on('dictionaries')->restrictOnDelete();
            $table->foreign('ice_id')->references('id')->on('ices')->restrictOnDelete();
        });

        Schema::table('affiliates', function (Blueprint $table) {
            // 生成列 brand_key は brand_id から作るが、CASCADE は行ごと消すので一緒に使える
            $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
        });

        Schema::table('cocktails', function (Blueprint $table) {
            $table->dropForeign(['method_id']);
            $table->dropForeign(['glass_dictionary_id']);
            $table->dropForeign(['ice_id']);
            $table->dropIndex(['ice_id']);
        });
    }
};
