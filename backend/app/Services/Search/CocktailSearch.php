<?php

namespace App\Services\Search;

use App\Models\Cocktail;
use Illuminate\Database\Eloquent\Builder;

/**
 * 条件検索(docs/search.md)の条件から、当たるカクテルを引くクエリを組む。
 *
 * 材料が一致するかの判定は IngredientMatch に任せ、ここでは方式によらない絞り込みと並びを受け持つ。
 */
class CocktailSearch
{
    public function __construct(private readonly IngredientMatch $match) {}

    /**
     * 選んだノードのどれかと一致する材料を使うカクテル(OR 検索)を、ID の昇順で引くクエリ。
     *
     * 下書きは出さない(旧と同じ)。並びは旧で返っていた ID の昇順にそろえる(旧は並びを指定しておらず、
     * 画面の既定の並びも ID の昇順。docs/decisions/search-screen.md の 5)。
     *
     * @param  list<int>  $nodeIds
     * @return Builder<Cocktail>
     */
    public function query(array $nodeIds): Builder
    {
        return $this->match
            ->whereUsesAny(Cocktail::query()->published(), $nodeIds)
            ->orderBy('id');
    }

    /**
     * 手持ちの材料(選んだノード)だけで作れるカクテル(あるもの検索)を、ID の昇順で引くクエリ。
     *
     * 持っていなくてよい材料(任意・「その他」)の判定は IngredientMatch::whereMakeableWith に任せる。下書き・並びは
     * OR 検索と同じ。旧はカクテル ID を 1 から件数までしか見ておらず、ID が件数を超えるカクテルが出なかったが、
     * その不具合は再現しない(docs/search-expected-diffs.md の「[#165] で再現しないもの」)。
     *
     * @param  list<int>  $nodeIds
     * @return Builder<Cocktail>
     */
    public function queryMakeable(array $nodeIds): Builder
    {
        return $this->match
            ->whereMakeableWith(Cocktail::query()->published(), $nodeIds)
            ->orderBy('id');
    }
}
