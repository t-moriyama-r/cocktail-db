<?php

namespace App\Services;

use App\Models\CategoryClosure;
use App\Models\LegacyIdMap;
use Illuminate\Support\Facades\DB;

/**
 * 旧サイトがブラウザに保存した検索条件(旧のカテゴリ・ミドル・技法の ID)を、新しいノード・技法の ID に直す(#290)。
 *
 * 旧 ID と新しい ID の対応は legacy_id_maps(#29・#33)で引く。カテゴリ c の直し方は #175 の追記と #282 の決まったこと 2
 * (docs/decisions/search-screen.md)のとおり。c の子のミドル(旧のミドルから移したノード)のうち、
 *
 * - すべてが選ばれている → c を 1 つ
 * - 一部だけ選ばれている → 選ばれたミドルだけ(祖先の一致で、c だけを指定した材料も旧と同じく一致する。#161 の 2)
 * - 1 つも選ばれていない → c を 1 つ(旧より広くなる。docs/decisions/search-screen.md の「旧との差分」)
 *
 * 子のミドルの無いカテゴリは「すべてが選ばれている」に当たり、c を 1 つにする。移行の後に新しく足した子は、旧の保存値に
 * 入りようがないので、「すべて」かどうかを数えるときに含めない。
 *
 * 次のものは捨て、捨てた数を返す。
 *
 * - 対応の無い旧 ID と、対応の先のノード・技法がもう無いもの
 * - 親のカテゴリが選ばれていないミドル。旧の検索はカテゴリの条件(r.category ∈ Category)を満たす材料しか見ないので、
 *   そのミドルは結果に効いていなかった(docs/search.md の「材料の一致」)。旧の画面では、ミドルを選ぶと親のカテゴリにも
 *   チェックが入るので、ふつうは起きない
 *
 * 技法は ID を振り直していない(#33)が、ほかと同じく legacy_id_maps で引き、無くなった技法を捨てる。
 */
final class LegacySearchCondition
{
    private const NODE_TABLE = 'categories';

    /**
     * @param  list<int>  $legacyCategoryIds  旧のカテゴリ(m_category)の ID
     * @param  list<int>  $legacyMiddleIds  旧のミドル(m_middle)の ID
     * @param  list<int>  $legacyMethodIds  旧の技法(m_method)の ID
     * @return array{category_ids: list<int>, method_ids: list<int>, dropped_count: int} ID はどちらも昇順で重なりなし
     */
    public function convert(array $legacyCategoryIds, array $legacyMiddleIds, array $legacyMethodIds): array
    {
        $legacyCategoryIds = array_values(array_unique($legacyCategoryIds));
        $legacyMiddleIds = array_values(array_unique($legacyMiddleIds));
        $legacyMethodIds = array_values(array_unique($legacyMethodIds));

        $categoryNodeIds = $this->existingNewIds('m_category', self::NODE_TABLE, $legacyCategoryIds);
        $middleNodeIds = $this->existingNewIds('m_middle', self::NODE_TABLE, $legacyMiddleIds);
        $methodIds = $this->existingNewIds('m_method', 'methods', $legacyMethodIds);

        $dropped = count($legacyCategoryIds) - count($categoryNodeIds)
            + count($legacyMiddleIds) - count($middleNodeIds)
            + count($legacyMethodIds) - count($methodIds);

        $middlesByCategory = $this->legacyMiddleChildren($categoryNodeIds);
        $selectedMiddles = array_flip($middleNodeIds);

        $nodeIds = [];
        $usedMiddles = [];
        foreach ($categoryNodeIds as $categoryNodeId) {
            $children = $middlesByCategory[$categoryNodeId] ?? [];
            $picked = array_values(array_filter($children, fn (int $child) => isset($selectedMiddles[$child])));

            if ($picked === [] || count($picked) === count($children)) {
                $nodeIds[] = $categoryNodeId;
            } else {
                array_push($nodeIds, ...$picked);
            }
            foreach ($picked as $child) {
                $usedMiddles[$child] = true;
            }
        }

        // 親のカテゴリが選ばれていないミドル
        $dropped += count(array_diff_key($selectedMiddles, $usedMiddles));

        sort($nodeIds);
        sort($methodIds);

        return [
            'category_ids' => array_values(array_unique($nodeIds)),
            'method_ids' => $methodIds,
            'dropped_count' => $dropped,
        ];
    }

    /**
     * 旧 ID を、対応の先の行が今もあるものだけ新しい ID に直す。
     *
     * @param  list<int>  $legacyIds
     * @return list<int> 新しい ID
     */
    private function existingNewIds(string $legacyTable, string $newTable, array $legacyIds): array
    {
        if ($legacyIds === []) {
            return [];
        }

        $newIds = [];
        $maps = LegacyIdMap::query()
            ->where('legacy_table', $legacyTable)
            ->where('new_table', $newTable)
            ->whereIn('legacy_id', $legacyIds)
            ->whereIn('new_id', DB::table($newTable)->select('id'))
            ->get();
        foreach ($maps as $map) {
            $newIds[$map->new_id] = $map->new_id;
        }

        return array_values($newIds);
    }

    /**
     * カテゴリのノードごとに、すぐ下の子のうち旧のミドルから移したノードを引く。閉包表は読むだけ。
     *
     * @param  list<int>  $categoryNodeIds
     * @return array<int, list<int>> カテゴリのノードの ID => 子のミドルのノードの ID
     */
    private function legacyMiddleChildren(array $categoryNodeIds): array
    {
        if ($categoryNodeIds === []) {
            return [];
        }

        $closures = CategoryClosure::query()
            ->whereIn('ancestor_id', $categoryNodeIds)
            ->where('depth', 1)
            ->whereIn('descendant_id', LegacyIdMap::query()
                ->select('new_id')
                ->where('legacy_table', 'm_middle')
                ->where('new_table', self::NODE_TABLE))
            ->get();

        $children = [];
        foreach ($closures as $closure) {
            $children[$closure->ancestor_id][] = $closure->descendant_id;
        }

        return $children;
    }
}
