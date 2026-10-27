<?php

namespace App\Models;

use Database\Factories\MethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * カクテルの技法(ビルド・シェイクなど)。旧 m_method と同じ列で、作成・更新の時刻を持たない。
 */
#[Fillable(['name', 'name_eng', 'description', 'dictionary_id'])]
class Method extends Model
{
    /** @use HasFactory<MethodFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * この技法を説明する用語(用語辞典の項目)。用語の無い技法では null。
     *
     * @return BelongsTo<Dictionary, $this>
     */
    public function dictionary(): BelongsTo
    {
        return $this->belongsTo(Dictionary::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dictionary_id' => 'integer',
        ];
    }
}
