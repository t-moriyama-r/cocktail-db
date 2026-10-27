<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 言い換え辞書(docs/schema.md の「paraphrases」。旧 paraphrases の列に id を足したもの)。
 * converted・middle は、ノードの ID ではなく名前のまま持つ(理由は docs/schema.md の「paraphrases」)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paraphrases', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->string('converted', 255);
            $table->string('middle', 255)->nullable();
            $table->text('note')->nullable();
            $table->string('command', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paraphrases');
    }
};
