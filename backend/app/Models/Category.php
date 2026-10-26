<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * カテゴリ木のノード(旧の種別・カテゴリ・ミドル)。
 *
 * 親子は列に持たず、閉包表(category_closures)だけで持つ。閉包表の行には自分自身(depth 0)も入るため、
 * 祖先・子孫のリレーションは depth 0 の行を除いて引く。閉包表の書き換えは CategoryTree(#24)が行う。
 */
#[Fillable([
    'name', 'name_search', 'name_eng', 'description', 'role',
    'is_major', 'is_like', 'is_powder', 'famous_brand_id',
    'youtube', 'youtube_created_at', 'youtube_start_seconds',
    'image', 'image_txt', 'image_url', 'sort_order',
])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * すぐ上の親(閉包表の depth 1 の祖先)。根では null。
     *
     * @return HasOneThrough<Category, CategoryClosure, $this>
     */
    public function parent(): HasOneThrough
    {
        return $this->hasOneThrough(Category::class, CategoryClosure::class, 'descendant_id', 'id', 'id', 'ancestor_id')
            ->where('category_closures.depth', 1);
    }

    /**
     * すぐ下の子(閉包表の depth 1 の子孫)。
     *
     * @return BelongsToMany<Category, $this, CategoryClosure>
     */
    public function children(): BelongsToMany
    {
        return $this->closureRelation('ancestor_id', 'descendant_id')->wherePivot('depth', 1);
    }

    /**
     * 自分を除くすべての祖先。pivot の depth で、自分からの段数が分かる。
     *
     * @return BelongsToMany<Category, $this, CategoryClosure>
     */
    public function ancestors(): BelongsToMany
    {
        return $this->closureRelation('descendant_id', 'ancestor_id')->wherePivot('depth', '>', 0);
    }

    /**
     * 自分を除くすべての子孫。pivot の depth で、自分からの段数が分かる。
     *
     * @return BelongsToMany<Category, $this, CategoryClosure>
     */
    public function descendants(): BelongsToMany
    {
        return $this->closureRelation('ancestor_id', 'descendant_id')->wherePivot('depth', '>', 0);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_major' => 'boolean',
            'is_like' => 'boolean',
            'is_powder' => 'boolean',
            'youtube_created_at' => 'datetime',
            'youtube_start_seconds' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * 閉包表を通して、自分の側の列($selfKey)から相手の側の列($otherKey)へ引く。
     *
     * @return BelongsToMany<Category, $this, CategoryClosure>
     */
    private function closureRelation(string $selfKey, string $otherKey): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_closures', $selfKey, $otherKey)
            ->using(CategoryClosure::class)
            ->withPivot('depth');
    }
}
