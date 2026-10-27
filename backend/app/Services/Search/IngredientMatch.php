<?php

namespace App\Services\Search;

use App\Models\Cocktail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * レシピの材料が、選んだノードと「一致する」かの判定(docs/search.md の「新しい木での意味」。#161)。
 *
 * 条件検索・別のカクテル・材料名からの検索が同じ意味で一致を判定するよう、一致するノードの集合の引き方と、
 * それを使う絞り込みをここに集める。材料のノードは cocktail_ingredients.category_id で見る。銘柄は条件に
 * 使わない(旧と同じ)ので、銘柄つきの材料も材料のノードで一致する。
 */
class IngredientMatch
{
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

        $nonRootAncestors = DB::table('category_closures as closure')
            ->select('closure.ancestor_id as id')
            ->whereIn('closure.descendant_id', $nodeIds)
            ->where('closure.depth', '>', 0)
            ->whereExists(fn (QueryBuilder $parent) => $parent
                ->from('category_closures as parent')
                ->whereColumn('parent.descendant_id', 'closure.ancestor_id')
                ->where('parent.depth', '>', 0));

        return $descendants->union($nonRootAncestors);
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
        return $cocktails->whereExists($this->ingredientsMatching($cocktails, $nodeIds));
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
            $cocktails->whereExists($this->ingredientsMatching($cocktails, $nodeIds));
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
        $missing = $this->ingredientsOf($cocktails)
            ->where('ingredient.is_optional', false)
            ->whereNotIn('ingredient.category_id', $this->matchingNodeIds($nodeIds))
            ->whereNotExists(fn (QueryBuilder $other) => $other
                ->from('category_closures as root_closure')
                ->join('categories as root', 'root.id', '=', 'root_closure.ancestor_id')
                ->whereColumn('root_closure.descendant_id', 'ingredient.category_id')
                ->where('root.role', self::OTHER_ROLE));

        return $cocktails->whereNotExists($missing);
    }

    /**
     * 根の役割のうち「その他」(docs/decisions/category-root-role.md。#153)。
     */
    private const OTHER_ROLE = 'other';

    /**
     * $cocktails の各カクテルの材料のうち、S と一致するもの。
     *
     * @param  Builder<Cocktail>  $cocktails
     * @param  list<int>  $nodeIds
     */
    private function ingredientsMatching(Builder $cocktails, array $nodeIds): QueryBuilder
    {
        return $this->ingredientsOf($cocktails)
            ->whereIn('ingredient.category_id', $this->matchingNodeIds($nodeIds));
    }

    /**
     * $cocktails の各カクテルの材料(相関サブクエリ)。
     *
     * @param  Builder<Cocktail>  $cocktails
     */
    private function ingredientsOf(Builder $cocktails): QueryBuilder
    {
        return DB::table('cocktail_ingredients as ingredient')
            ->whereColumn('ingredient.cocktail_id', $cocktails->getModel()->getQualifiedKeyName());
    }
}
