<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * 管理画面の保存の冪等キー 1 件(#657)。管理者ごとのキー 1 つにつき 1 行で、最初に成功した送信の応答を持つ。
 * 書くのは Idempotent のミドルウェアだけ(決まりは docs/decisions/admin-idempotency.md)。
 * 作ってから RETENTION_DAYS 日より古い行は、scheduler の model:prune が毎日消す(#763。routes/console.php)。
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
    use MassPrunable;

    /** 記録を残す日数。これより古い記録は消し、同じキーの再送は新しい保存として扱う(#763) */
    public const RETENTION_DAYS = 7;

    /**
     * model:prune が消す行: 作ってから RETENTION_DAYS 日より古いもの。
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

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
