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
     * まとまりごとに、そのまとまりのどれかと一致する材料を使うカクテル(AND 検索)を、ID の昇順で引くクエリ。
     *
     * まとまりは画面が「同じ親から選んだもの」をまとめて送る(docs/decisions/search-screen.md の 1。#282)。
     * 旧の AND がカテゴリごとに 1 つの条件だったのと同じ結果になる。下書き・並びは OR 検索と同じ。
     *
     * @param  list<list<int>>  $groups
     * @return Builder<Cocktail>
     */
    public function queryEach(array $groups): Builder
    {
        // 同じ内容のまとまりを何度 AND しても結果は変わらないので、重複を除いて条件の数を減らす
        $groups = array_map(static function (array $group): array {
            $group = array_values(array_unique($group));
            sort($group);

            return $group;
        }, $groups);
        $groups = array_values(array_unique($groups, SORT_REGULAR));

        return $this->match
            ->whereUsesEach(Cocktail::query()->published(), $groups)
            ->orderBy('id');
    }
}
