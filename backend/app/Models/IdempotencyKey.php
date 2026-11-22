<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 管理画面の保存の冪等キー 1 件(#657)。管理者ごとのキー 1 つにつき 1 行で、最初に成功した送信の応答を持つ。
 * 書くのは Idempotent のミドルウェアだけ(決まりは docs/decisions/admin-idempotency.md)。
 *
 * @property int $user_id
 * @property string $key
 * @property string $fingerprint
 * @property int|null $response_status 保存が終わるまで(コミットの前)だけ null
 * @property string|null $response_content_type
 * @property string|null $response_body
 */
#[Fillable(['user_id', 'key', 'fingerprint', 'response_status', 'response_content_type', 'response_body'])]
class IdempotencyKey extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'response_status' => 'integer',
        ];
    }
}
