<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 非常用のログイン URL(docs/schema.md の「admin_login_links」。新設。#115)。
 *
 * URL の署名だけでは、期限内なら何度でも使えてしまう。1 回しか使えないようにするため、発行した URL ごとに
 * 使ったかどうかを記録する。URL に載せる乱数(トークン)そのものは持たず、ハッシュだけを持つ。
 * DB のダンプやバックアップが漏れても、そこから期限内の URL を組み立てられないようにするため。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_login_links', function (Blueprint $table) {
            $table->id();
            // ユーザーを消したら、そのユーザーの URL も使えなくてよい
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_login_links');
    }
};
