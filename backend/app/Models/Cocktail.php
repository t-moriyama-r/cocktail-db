<?php

namespace App\Models;

use Database\Factories\CocktailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * カクテル。
 *
 * recipe_count は材料から数えた値で、書き込むのは RecipeCounter(#156)なので fillable に入れない。
 */
#[Fillable([
    'name', 'name_search', 'name_eng', 'base_category_id',
    'method_id', 'glass_dictionary_id', 'ice_id',
    'volume', 'sweet_degree', 'rate', 'description', 'note',
    'is_draft', 'is_major', 'instagram',
    'youtube', 'youtube_created_at', 'youtube_start_seconds',
    'image', 'image_txt', 'image_url',
])]
class Cocktail extends Model
{
    /** @use HasFactory<CocktailFactory> */
    use HasFactory;

    /**
     * 材料。レシピに書いた順(position の昇順)に返す。
     *
     * @return HasMany<CocktailIngredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(CocktailIngredient::class)->orderBy('position');
    }

    /**
     * ベースのノード。
     *
     * @return BelongsTo<Category, $this>
     */
    public function baseCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'base_category_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_category_id' => 'integer',
            'method_id' => 'integer',
            'glass_dictionary_id' => 'integer',
            'ice_id' => 'integer',
            'volume' => 'decimal:1',
            'sweet_degree' => 'integer',
            'rate' => 'integer',
            'is_draft' => 'boolean',
            'is_major' => 'boolean',
            'recipe_count' => 'integer',
            'youtube_created_at' => 'datetime',
            'youtube_start_seconds' => 'integer',
        ];
    }
}
