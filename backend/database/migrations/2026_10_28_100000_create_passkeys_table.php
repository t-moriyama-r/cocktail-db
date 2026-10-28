<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理画面にログインするパスキー(docs/schema.md の「passkeys」。新設。#311)。
 *
 * 列は、使うライブラリ laravel/passkeys(docs/decisions/passkey.md)のモデルが読み書きする形に合わせる。
 * 持つのは公開鍵と署名の回数などで、秘密の鍵は端末の外に出ないので持たない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            // ユーザーを消したら、そのユーザーのパスキーでは入れなくてよい
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 管理画面の一覧に出す名前(登録のときに付ける。「仕事の MacBook」など)
            $table->string('name');
            // 資格情報の ID(base64url)。ログインのときに、ブラウザが返した ID からパスキーを引く
            $table->string('credential_id')->unique();
            // 公開鍵・署名の回数・AAGUID などを、webauthn-lib の CredentialRecord の JSON で持つ
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
