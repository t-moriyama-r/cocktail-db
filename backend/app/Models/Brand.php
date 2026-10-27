<?php

namespace App\Models;

use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 銘柄。容量・説明・アフィリエイトを持つ「商品」なので木のノードにはせず、木のどの深さのノードにもぶら下げる。
 */
#[Fillable([
    'category_id', 'name', 'name_search', 'name_eng', 'volume', 'description',
    'is_major', 'is_like', 'instagram',
    'youtube', 'youtube_created_at', 'youtube_start_seconds',
    'image', 'image_txt', 'image_url',
])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    /**
     * 所属するノード。
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'volume' => 'decimal:1',
            'is_major' => 'boolean',
            'is_like' => 'boolean',
            'youtube_created_at' => 'datetime',
            'youtube_start_seconds' => 'integer',
        ];
    }
}
