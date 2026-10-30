<?php

use App\Services\RecipeSignature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 同じレシピのカクテルを引くための、材料の組のハッシュ(docs/schema.md の「recipe_signature」。#82)。
 *
 * 書き込むのは RecipeSignature だけ。材料の無いカクテルは null(どのカクテルとも同じレシピにしない)。
 * 同じ値のカクテルを引くので索引を張る。
 *
 * 移行を済ませた DB(dev・stg)にも値が入るよう、列を足したら、今ある材料から作る。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cocktails', function (Blueprint $table) {
            $table->char('recipe_signature', 64)->nullable()->after('recipe_count')->index();
        });

        $cocktailIds = DB::table('cocktails')->pluck('id')
            ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : throw new UnexpectedValueException('カクテルの ID が数でない'))
            ->all();
        app(RecipeSignature::class)->rewrite(array_values($cocktailIds));
    }

    public function down(): void
    {
        Schema::table('cocktails', function (Blueprint $table) {
            $table->dropIndex(['recipe_signature']);
            $table->dropColumn('recipe_signature');
        });
    }
};
