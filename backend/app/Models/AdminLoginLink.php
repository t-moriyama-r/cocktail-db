<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 発行した非常用のログイン URL の 1 件(#115)。
 *
 * URL に載せるトークンはハッシュ(token_hash)でだけ持ち、使った時刻(used_at)で 1 回きりにする。
 *
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
#[Fillable(['user_id', 'token_hash', 'expires_at'])]
class AdminLoginLink extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
