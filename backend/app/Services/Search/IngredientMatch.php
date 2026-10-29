<?php

namespace App\Services\Search;

use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\CategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * 選んだノードの集合と、レシピの材料が「一致する」かを、閉包表で判定する(docs/search.md の「新しい木での意味」。#161)。
 *
 * 材料のノードは cocktail_ingredients.category_id で見る。条件検索は銘柄を条件に使わない(旧と同じ)ので、銘柄つきの材料も
 * 材料のノードで一致する。銘柄を条件に使うのは、別のカクテルの銘柄を起点にするときだけ(whereMatchesBrand。#174)。
 */
class IngredientMatch
{
    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * 選んだノードの集合 S と一致する材料ノードの ID の集合を、列 id のサブクエリで返す。
     *
     * 旧は、カテゴリを選ぶと子のミドルの材料が、ミドルを選ぶと親のカテゴリしか指定していない材料が一致していた。
     * 任意の深さの木でも同じ意味になるよう、S の各ノードの配下(自分を含む)と祖先の両方を一致に含める。
     * 祖先からは根を除く。根は種別(洋酒・ソフトドリンクなど)にあたり、材料に使わない(#153)ので、
     * 祖先の向きで根まで広げる意味が無い。
     *
     * @param  list<int>  $nodeIds
     */
    public function matchingNodeIds(array $nodeIds): QueryBuilder
    {
        $descendants = DB::table('category_closures')
            ->select('descendant_id as id')
            ->whereIn('ancestor_id', $nodeIds);

        return $descendants->union($this->nonRootAncestorIds($nodeIds));
    }

    /**
     * 選んだノードの集合 S の各ノード自身と、その祖先(根を除く)の ID を、列 id のサブクエリで返す。配下は含めない。
     *
     * 銘柄を起点にした別のカクテル(#174)で使う。旧 getCocktailIds は銘柄を指定すると、銘柄のミドルか、
     * ミドルを指定していない(カテゴリだけの)材料にだけ一致させ、銘柄と関係ないミドル(配下)の材料を混ぜなかった。
     *
     * @param  list<int>  $nodeIds
     */
    public function selfAndNonRootAncestorIds(array $nodeIds): QueryBuilder
    {
        $selves = DB::table('category_closures')
            ->select('descendant_id as id')
            ->whereIn('descendant_id', $nodeIds)
            ->where('depth', 0);

        return $selves->union($this->nonRootAncestorIds($nodeIds));
    }

    /**
     * 銘柄 $brandId(所属するノード $nodeId)と一致する材料に絞る(#174 の「銘柄を起点にするとき」)。
     *
     * 材料のノードが銘柄のノードか、その祖先(根を除く)で、銘柄がその銘柄か、銘柄を指定していないもの(旧 getCocktailIds)。
     * 同じノードでも別の銘柄を指定した材料は一致しない。
     *
     * @param  Builder<CocktailIngredient>  $ingredients
     * @return Builder<CocktailIngredient>
     */
    public function whereMatchesBrand(Builder $ingredients, int $nodeId, int $brandId): Builder
    {
        return $ingredients
            ->whereIn('category_id', $this->selfAndNonRootAncestorIds([$nodeId]))
            ->where(fn (Builder $brand) => $brand->where('brand_id', $brandId)->orWhereNull('brand_id'));
    }

    /**
     * S と一致する材料を 1 つでも持つカクテルに絞る(or)。任意の材料も一致の対象にする(旧の or と同じ)。
     *
     * @param  Builder<Cocktail>  $cocktails
     * @param  list<int>  $nodeIds
     * @return Builder<Cocktail>
     */
    public function whereUsesAny(Builder $cocktails, array $nodeIds): Builder
    {
        return $cocktails->whereHas('ingredients', fn (Builder $ingredients) => $ingredients
            ->whereIn('category_id', $this->matchingNodeIds($nodeIds)));
    }

    /**
     * 条件(ノードの集合)ごとに、その条件と一致する材料を持つカクテルに絞る(and)。
     *
     * 1 つの材料がいくつもの条件を満たしてよい(旧も、カテゴリしか指定していない材料 1 行でそのカテゴリの条件を
     * 満たしていた)。そのため、ある条件のノードの祖先だけからなる条件は、結果を変えない。
     *
     * @param  Builder<Cocktail>  $cocktails
     * @param  list<list<int>>  $conditions
     * @return Builder<Cocktail>
     */
    public function whereUsesEach(Builder $cocktails, array $conditions): Builder
    {
        foreach ($conditions as $nodeIds) {
            $this->whereUsesAny($cocktails, $nodeIds);
        }

        return $cocktails;
    }

    /**
     * 手持ちの材料 S だけで作れるカクテルに絞る(having)。
     *
     * 任意の材料と、役割が other の根の部分木にある材料(ガーニッシュなど)は、手元にあるのが当然として持っていなくて
     * よい(#161 の決まったこと 5)。旧はミドルを指定した「その他」の材料を持っている必要がある扱いだったが、
     * それは引き継がず、深さによらず持っていなくてよいとする。ソフトドリンクは手元に無いことが多いので、
     * 持っている必要がある。
     *
     * @param  Builder<Cocktail>  $cocktails
     * @param  list<int>  $nodeIds
     * @return Builder<Cocktail>
     */
    public function whereMakeableWith(Builder $cocktails, array $nodeIds): Builder
    {
        return $cocktails->whereDoesntHave('ingredients', fn (Builder $ingredients) => $ingredients
            ->where('is_optional', false)
            ->whereNotIn('category_id', $this->matchingNodeIds($nodeIds))
            ->whereNotIn('category_id', $this->tree->otherNodeIds()));
    }

    /**
     * S の各ノードの祖先(自分を除く)のうち、根でないものの ID。根は種別にあたり、材料に使わない(#153)ので除く。
     *
     * @param  list<int>  $nodeIds
     */
    private function nonRootAncestorIds(array $nodeIds): QueryBuilder
    {
        return DB::table('category_closures as closure')
            ->select('closure.ancestor_id as id')
            ->whereIn('closure.descendant_id', $nodeIds)
            ->where('closure.depth', '>', 0)
            ->whereNotIn('closure.ancestor_id', $this->tree->rootIds());
    }
}
