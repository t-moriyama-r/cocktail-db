<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * カクテルの材料の数(cocktails.recipe_count)を数え、列に書く(docs/decisions/recipe-count.md。#154・#156)。
 *
 * recipe_count を書き換えるのはこのクラスだけにする。旧は保存のときと数え直しのときで数え方が違い、列の値に
 * 2 つの定義が混ざっていた。数え方を 1 か所に置き、数え直しを呼ぶ所(材料の保存・部分木の移動・銘柄の所属の変更・
 * 移行・数え直しのコマンド)はどれもここを通す。
 */
class RecipeCounter
{
    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * $cocktail の材料の数。保存してある材料の行(cocktail_ingredients)から数える。
     *
     * 数えるのは、任意でなく、役割が「その他」の根の部分木に入っていない材料の行。同じ材料が 2 行あれば 2 と数える
     * (旧も行を数えていた。docs/migration-anomalies.md の 2)。
     */
    public function count(Cocktail $cocktail): int
    {
        return $this->countedIngredients()->where('cocktail_id', $cocktail->getKey())->count();
    }

    /**
     * $cocktailIds のカクテルの recipe_count を数え直して書く。材料の無いカクテルは 0 にする。
     *
     * updated_at は変えない。数え直しはカクテルの編集ではなく、移行(#31)では旧の更新日時を残す必要があるため。
     *
     * @param  list<int>  $cocktailIds
     */
    public function recount(array $cocktailIds): void
    {
        $ids = array_values(array_unique($cocktailIds));
        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($ids) {
            $counts = $this->countsByCocktail($ids);

            // 数の種類は材料の行数の上限(旧は 11 行)までしか無いので、同じ数のカクテルをまとめて 1 文で書く
            $idsByCount = [];
            foreach ($ids as $id) {
                $idsByCount[$counts[$id] ?? 0][] = $id;
            }
            foreach ($idsByCount as $count => $sameCountIds) {
                Cocktail::query()->whereKey($sameCountIds)->toBase()->update(['recipe_count' => $count]);
            }
        });
    }

    /**
     * カクテルの ID → 材料の数。何も書かない。$cocktailIds が null なら、すべてのカクテルを数える。
     *
     * 数える材料の行が 1 つも無いカクテルは入らない(材料の数は 0)。カクテルごとに count を呼ぶと
     * カクテルの数だけクエリが増えるので、まとめて 1 文で数える。
     *
     * @param  list<int>|null  $cocktailIds
     * @return array<int, int>
     */
    public function countsByCocktail(?array $cocktailIds = null): array
    {
        $query = $this->countedIngredients();
        if ($cocktailIds !== null) {
            $query->whereIn('cocktail_id', $cocktailIds);
        }

        $counts = [];
        $rows = $query
            ->groupBy('cocktail_id')
            ->selectRaw('cocktail_id, COUNT(*) AS counted')
            ->toBase()
            ->get();
        foreach ($rows as $row) {
            $counts[self::toInt($row->cocktail_id)] = self::toInt($row->counted);
        }

        return $counts;
    }

    /**
     * $node の部分木のノードか、そこにぶら下がる銘柄を材料に使うカクテルを数え直す。
     *
     * 部分木を根をまたいで移し、部分木のノードの根の役割が変わったときに CategoryTree::moveSubtree から呼ぶ。
     * 数えるかどうかは材料のノードで決まるが、銘柄の所属ノードと材料のノードが違う行(docs/schema.md の
     * 「材料が銘柄を持つときのノード」の 62 行)も取りこぼさないよう、銘柄からも引く。
     */
    public function recountSubtree(Category $node): void
    {
        $subtreeIds = $this->tree->subtreeIds($node);

        $cocktailIds = CocktailIngredient::query()
            ->where(fn (Builder $ingredients) => $ingredients
                ->whereIn('category_id', $subtreeIds)
                ->orWhereIn('brand_id', Brand::query()->select('id')->whereIn('category_id', $subtreeIds)))
            ->distinct()
            ->toBase()
            ->pluck('cocktail_id')
            ->map(fn (mixed $id): int => self::toInt($id))
            ->all();

        $this->recount(array_values($cocktailIds));
    }

    /**
     * 材料の数に数える材料の行(任意でなく、「その他」のノードでないもの)。
     *
     * @return Builder<CocktailIngredient>
     */
    private function countedIngredients(): Builder
    {
        return CocktailIngredient::query()
            ->where('is_optional', false)
            ->whereNotIn('category_id', $this->tree->otherNodeIds());
    }

    /**
     * クエリビルダーで読んだ列の値(ドライバーによって int か数字の文字列)を int にする。
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new UnexpectedValueException('数であるはずの列の値が数でない');
    }
}
