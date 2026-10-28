<?php

namespace App\Models;

use Database\Factories\DictionaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * 用語辞典の項目。カクテルのグラスも、分類「グラス」の項目としてここに入る。
 */
#[Fillable([
    'dictionary_category_id', 'name', 'name_eng', 'name_search', 'description',
    'youtube', 'youtube_start_seconds', 'image', 'image_txt', 'image_url',
])]
class Dictionary extends Model
{
    /** @use HasFactory<DictionaryFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<DictionaryCategory, $this>
     */
    public function dictionaryCategory(): BelongsTo
    {
        return $this->belongsTo(DictionaryCategory::class);
    }

    /**
     * 分類。外部キー(NOT NULL・RESTRICT)で必ずあるので、無ければデータの壊れとして例外にする。
     */
    public function requiredCategory(): DictionaryCategory
    {
        return $this->dictionaryCategory ?? throw new LogicException("用語 {$this->id} の分類が無い");
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'youtube_start_seconds' => 'integer',
        ];
    }
}
