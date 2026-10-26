<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * カクテルの材料(docs/schema.md の「cocktail_ingredients」)。
 * 同じカクテルに同じノード・銘柄の行が 2 つあってもよい(量の違う組が旧データに残る)ので、主キーにノードを入れず、
 * カクテルの中での並び順(position)で行を特定する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cocktail_ingredients', function (Blueprint $table) {
            // カクテルを消すと材料も消える(旧は材料を残していた。docs/migration-anomalies.md の 1)
            $table->foreignId('cocktail_id')->constrained('cocktails')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('category_id')->index()->constrained('categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->index()->constrained('brands')->restrictOnDelete();
            $table->string('amount', 20);
            $table->boolean('is_optional')->default(false);

            $table->primary(['cocktail_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cocktail_ingredients');
    }
};
