<?php

namespace App\Models;

use Database\Factories\CocktailAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * カクテルの別名(#82)。同じカクテルの別の呼び名で、名前の横断検索で別名でもカクテルが見つかるようにする。
 *
 * name_search は名前を NameNormalizer でそろえた値(カクテル・ノード・銘柄の name_search と同じ形)。
 */
#[Fillable(['cocktail_id', 'name', 'name_search'])]
class CocktailAlias extends Model
{
    /** @use HasFactory<CocktailAliasFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<Cocktail, $this>
     */
    public function cocktail(): BelongsTo
    {
        return $this->belongsTo(Cocktail::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cocktail_id' => 'integer',
        ];
    }
}
