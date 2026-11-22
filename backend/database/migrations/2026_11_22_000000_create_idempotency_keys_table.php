<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理画面の保存の冪等キー(docs/schema.md の「idempotency_keys」。新設。#657)。
 *
 * 保存が済んだ後に応答が失われ、管理者が同じ送信を再送すると、同じ内容の行が 2 件できていた。送信ごとに FE が付ける
 * 識別子(Idempotency-Key ヘッダ)と、そのときの応答をここに残し、同じ識別子の再送には、保存し直さずに残した応答を返す
 * (決まりは docs/decisions/admin-idempotency.md)。行は保存が成功したときだけ、保存と同じトランザクションで作る。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            // キーは管理者ごとに数える。ユーザーを消したら、そのユーザーの記録も要らない
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 送られた Idempotency-Key(UUID)
            $table->char('key', 36);
            // 送信の中身(メソッド・経路・入力。ファイルは中身のハッシュ)の SHA-256。同じキーで違う中身が来たら返さない
            $table->char('fingerprint', 64);
            // 最初に成功した送信の応答。行を作ってから保存するので、保存が終わるまで(コミットの前)だけ null
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_content_type', 255)->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
