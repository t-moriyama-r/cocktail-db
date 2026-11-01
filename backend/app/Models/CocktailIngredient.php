<?php

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use Database\Factories\CocktailIngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * カクテルの材料の 1 行。
 *
 * 主キーは (cocktail_id, position) の複合キー。同じカクテルに同じノード・銘柄の行が 2 つあってもよいため、
 * 行はカクテルの中での並び順で特定する。
 */
#[Fillable(['cocktail_id', 'position', 'category_id', 'brand_id', 'amount', 'is_optional'])]
class CocktailIngredient extends Model
{
    use HasCompositePrimaryKey;

    /** @use HasFactory<CocktailIngredientFactory> */
    use HasFactory;

    /**
     * 量(amount)の列の長さ。保存する量(整えた後の値)はこれを超えられない。
     */
    public const AMOUNT_MAX_LENGTH = 20;

    // 複合の主キー(compositeKeyColumns)のうち、Eloquent に 1 列として渡せる方
    protected $primaryKey = 'position';

    public $timestamps = false;

    /**
     * @return BelongsTo<Cocktail, $this>
     */
    public function cocktail(): BelongsTo
    {
        return $this->belongsTo(Cocktail::class);
    }

    /**
     * 材料のノード(いちばん深いノード)。
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * 銘柄。銘柄を決めない材料では null。
     *
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cocktail_id' => 'integer',
            'position' => 'integer',
            'category_id' => 'integer',
            'brand_id' => 'integer',
            'is_optional' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    protected function compositeKeyColumns(): array
    {
        return ['cocktail_id', 'position'];
    }
}
