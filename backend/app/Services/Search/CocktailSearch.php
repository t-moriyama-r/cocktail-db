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
    /**
     * かんたん検索で出す、材料の数(recipe_count)の上限。旧と同じ 3(docs/search.md の「3 つに共通の絞り込み」)。
     */
    public const EASY_MAX_RECIPE_COUNT = 3;

    public function __construct(private readonly IngredientMatch $match) {}

    /**
     * 選んだノードのどれかと一致する材料を使うカクテル(OR 検索)を、ID の昇順で引くクエリ。
     *
     * 下書きは出さない(旧と同じ)。並びは旧で返っていた ID の昇順にそろえる(旧は並びを指定しておらず、
     * 画面の既定の並びも ID の昇順。docs/decisions/search-screen.md の 5)。技法とかんたん検索の絞り込みは
     * narrow のとおり。
     *
     * @param  list<int>  $nodeIds
     * @param  list<int>|null  $methodIds
     * @return Builder<Cocktail>
     */
    public function query(array $nodeIds, ?array $methodIds = null, bool $easy = false): Builder
    {
        return $this->match
            ->whereUsesAny($this->narrow(Cocktail::query()->published(), $methodIds, $easy), $nodeIds)
            ->orderBy('id');
    }

    /**
     * 手持ちの材料(選んだノード)だけで作れるカクテル(あるもの検索)を、ID の昇順で引くクエリ。
     *
     * 持っていなくてよい材料(任意・「その他」)の判定は IngredientMatch::whereMakeableWith に任せる。下書き・並び・
     * 技法とかんたん検索の絞り込みは OR 検索と同じ。旧はカクテル ID を 1 から件数までしか見ておらず、ID が件数を
     * 超えるカクテルが出なかったが、その不具合は再現しない(docs/search-expected-diffs.md の「[#165] で再現しないもの」)。
     *
     * @param  list<int>  $nodeIds
     * @param  list<int>|null  $methodIds
     * @return Builder<Cocktail>
     */
    public function queryMakeable(array $nodeIds, ?array $methodIds = null, bool $easy = false): Builder
    {
        return $this->match
            ->whereMakeableWith($this->narrow(Cocktail::query()->published(), $methodIds, $easy), $nodeIds)
            ->orderBy('id');
    }

    /**
     * 方式によらない絞り込み(#166)。旧も、材料で選んだカクテルを最後に同じ条件で絞っていた。
     *
     * - 技法: カクテルの技法が $methodIds のどれかであるもの。null ならすべての技法(絞らない)。
     *   無い技法の ID は何にも一致しない(旧と同じ)
     * - かんたん検索: $easy なら、材料の数(recipe_count)が 3 以下のもの。数え方は RecipeCounter(#154・#156)
     *
     * @param  Builder<Cocktail>  $cocktails
     * @param  list<int>|null  $methodIds
     * @return Builder<Cocktail>
     */
    private function narrow(Builder $cocktails, ?array $methodIds, bool $easy): Builder
    {
        return $cocktails
            ->when($methodIds !== null, fn (Builder $query) => $query->whereIn('method_id', $methodIds ?? []))
            ->when($easy, fn (Builder $query) => $query->where('recipe_count', '<=', self::EASY_MAX_RECIPE_COUNT));
    }
}
