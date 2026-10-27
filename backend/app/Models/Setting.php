<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 画面から変える設定の 1 項目(#69)。
 *
 * 値には外部サービスの鍵などの秘密が入るので、DB には APP_KEY で暗号化して保存する。
 * DB のダンプやバックアップが漏れても、APP_KEY が無ければ値を読めない。
 */
#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /**
     * 最後に値を変えた人。そのユーザーを消すと null になる。
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }
}
