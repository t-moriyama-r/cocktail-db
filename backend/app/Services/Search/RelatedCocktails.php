<?php

namespace App\Services\Search;

use App\Models\Brand;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 詳細ページの「別のカクテル」(旧の「ランダムカクテル」。旧 CocktailFactory::RandomByGetRandom。#174)を選ぶ。
 *
 * どの起点でも、下書きは出さず、当たるカクテルからランダムに $limit 件まで返す。何が当たるかは
 * docs/decisions/related-cocktails.md。材料の一致は IngredientMatch(#161・#162)で判定する。
 */
class RelatedCocktails
{
    /**
     * カクテルを起点にするとき、最初に使う材料の数(旧 RandomByCocktail は最初の 2 つの材料で探した)。
     */
    private const FIRST_INGREDIENTS = 2;

    public function __construct(private readonly IngredientMatch $match) {}

    /**
     * ノードを起点にする(旧の category・middle)。そのノードか配下か、より大まかなノード(祖先。根を除く)を
     * 材料に使うカクテル。
     *
     * @return Collection<int, Cocktail>
     */
    public function usingNode(int $nodeId, int $limit): Collection
    {
        return $this->pick(
            Cocktail::query()->whereHas('ingredients', fn (Builder $rows) => $this->whereMatches($rows, $nodeId, null)),
            $limit,
        );
    }

    /**
     * 銘柄を起点にする(旧の brand)。銘柄のノードか祖先(根を除く)の材料で、その銘柄か、銘柄を指定していないもの。
     *
     * @return Collection<int, Cocktail>
     */
    public function usingBrand(Brand $brand, int $limit): Collection
    {
        return $this->pick(
            Cocktail::query()->whereHas('ingredients', fn (Builder $rows) => $this->whereMatches($rows, $brand->category_id, $brand->id)),
            $limit,
        );
    }

    /**
     * ベース・技法・グラスを起点にする(旧の base・method・glass)。その列が同じカクテル。
     *
     * @param  'base_category_id'|'method_id'|'glass_dictionary_id'  $column
     * @return Collection<int, Cocktail>
     */
    public function withSame(string $column, int $id, int $limit): Collection
    {
        return $this->pick(Cocktail::query()->where($column, $id), $limit);
    }

    /**
     * カクテルを起点にする(旧の cocktail。旧 RandomByCocktail)。
     *
     * 最初の 2 つの材料のどちらかに一致する材料を使うカクテル(和集合)から選ぶ。それで $limit 件に足りなければ、
     * 3 つめから材料を 1 つずつ順に足し、$limit 件以上になったところで止める。起点のカクテル自身も、自分の材料を
     * 使うので当たる(旧と同じ)。
     *
     * @return Collection<int, Cocktail>
     */
    public function similarTo(Cocktail $cocktail, int $limit): Collection
    {
        $ingredients = $cocktail->ingredients()->get();
        if ($ingredients->isEmpty()) {
            return new Collection;
        }

        $used = min(self::FIRST_INGREDIENTS, $ingredients->count());
        $candidates = $this->usingAnyOf($ingredients->take($used));
        while ($used < $ingredients->count() && $candidates->clone()->published()->count() < $limit) {
            $used++;
            $candidates = $this->usingAnyOf($ingredients->take($used));
        }

        return $this->pick($candidates, $limit);
    }

    /**
     * 材料の行のどれかと一致する材料を使うカクテル(旧 getCocktailIds に材料のカテゴリ・ミドル・銘柄を渡していた)。
     *
     * @param  Collection<int, CocktailIngredient>  $ingredients
     * @return Builder<Cocktail>
     */
    private function usingAnyOf(Collection $ingredients): Builder
    {
        return Cocktail::query()->whereHas('ingredients', function (Builder $rows) use ($ingredients): void {
            $rows->where(function (Builder $any) use ($ingredients): void {
                foreach ($ingredients as $ingredient) {
                    $any->orWhere(fn (Builder $row) => $this->whereMatches($row, $ingredient->category_id, $ingredient->brand_id));
                }
            });
        });
    }

    /**
     * 起点(ノードと、銘柄があれば銘柄)と一致する材料に絞る。銘柄が無ければノードを起点にしたとき(配下・祖先が一致)、
     * 銘柄があれば銘柄を起点にしたとき(銘柄のノードと祖先で、その銘柄か銘柄の無い材料)の意味。
     *
     * @param  Builder<CocktailIngredient>  $rows
     * @return Builder<CocktailIngredient>
     */
    private function whereMatches(Builder $rows, int $nodeId, ?int $brandId): Builder
    {
        return $brandId === null
            ? $rows->whereIn('category_id', $this->match->matchingNodeIds([$nodeId]))
            : $this->match->whereMatchesBrand($rows, $nodeId, $brandId);
    }

    /**
     * 下書きを除き、ランダムの順で $limit 件まで取る。
     *
     * @param  Builder<Cocktail>  $cocktails
     * @return Collection<int, Cocktail>
     */
    private function pick(Builder $cocktails, int $limit): Collection
    {
        return $cocktails->published()
            ->inRandomOrder()
            ->limit($limit)
            ->with(['baseCategory', 'method'])
            ->get();
    }
}
