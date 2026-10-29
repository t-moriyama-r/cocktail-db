<?php

namespace App\Models;

use Database\Factories\DictionaryCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 用語辞典の分類(製法・グラスなど)。旧 m_dictionary_category と同じ列で、作成・更新の時刻を持たない。
 */
#[Fillable(['name', 'name_eng'])]
class DictionaryCategory extends Model
{
    /** @use HasFactory<DictionaryCategoryFactory> */
    use HasFactory;

    /**
     * 分類「グラス」の ID(旧 ID のまま。docs/schema.md の「dictionary_categories」)。カクテルのグラス
     * (`cocktails.glass_dictionary_id`)は、この分類の用語を指す。
     */
    public const GLASS_ID = 2;

    public $timestamps = false;

    /**
     * @return HasMany<Dictionary, $this>
     */
    public function dictionaries(): HasMany
    {
        return $this->hasMany(Dictionary::class);
    }
}
