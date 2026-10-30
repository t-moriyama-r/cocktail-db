<?php

namespace App\Models;

use Database\Factories\CocktailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * カクテル。
 *
 * recipe_count は材料から数えた値で、書き込むのは RecipeCounter(#156)なので fillable に入れない。
 * recipe_signature も材料から作る値で、書き込むのは RecipeSignature(#82)なので fillable に入れない。
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
     * 別名。登録した順(ID の昇順)に返す。
     *
     * @return HasMany<CocktailAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(CocktailAlias::class)->orderBy('id');
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
     * 技法。
     *
     * @return BelongsTo<Method, $this>
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(Method::class);
    }

    /**
     * グラス(用語辞典の分類「グラス」の項目)。決めていないカクテルでは null。
     *
     * @return BelongsTo<Dictionary, $this>
     */
    public function glass(): BelongsTo
    {
        return $this->belongsTo(Dictionary::class, 'glass_dictionary_id');
    }

    /**
     * 氷。決めていないカクテルでは null。
     *
     * @return BelongsTo<Ice, $this>
     */
    public function ice(): BelongsTo
    {
        return $this->belongsTo(Ice::class);
    }

    /**
     * 公開しているカクテル(下書きでないもの)に絞る。下書きは公開の画面・API に出さない。
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_draft', false);
    }

    /**
     * 材料の組がまったく同じ、ほかの公開しているカクテル(#82)を ID の順で返す。材料の組は recipe_signature で比べる。
     * 材料の無いカクテル(recipe_signature が null)は、どのカクテルとも同じレシピにしない。
     *
     * @return Collection<int, self>
     */
    public function sameRecipeCocktails(): Collection
    {
        if ($this->recipe_signature === null) {
            return new Collection;
        }

        return self::query()
            ->published()
            ->where('recipe_signature', $this->recipe_signature)
            ->whereKeyNot($this->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * 技法。外部キー(NOT NULL・RESTRICT)で必ずあるので、無ければデータの壊れとして例外にする。
     */
    public function requiredMethod(): Method
    {
        return $this->method ?? throw new LogicException("カクテル {$this->id} の技法が無い");
    }

    /**
     * 度数(%)。列の volume は小数 1 桁の文字列で読まれるので、数にして返す(旧の API・画面は strength と呼んでいた)。
     */
    public function strength(): float
    {
        return (float) $this->volume;
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
