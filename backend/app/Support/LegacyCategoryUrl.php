<?php

namespace App\Support;

use App\Models\Category;
use App\Models\LegacyIdMap;

/**
 * 旧のカテゴリ・ミドルの URL に入っていた旧 ID から、カテゴリ木を移した(#29)先のノードの詳細のパスを引く。
 *
 * 旧の詳細の URL(/detail/category・/detail/middle。#78)と、旧の SNS カードの URL(/card/category・/card/middle。#113)が
 * 同じ付け替えを使う。旧の管理画面のカテゴリ・ミドルの編集の URL(#817)も、同じ引き方で新 ID を引く。旧 ID と新しいノードの対応は legacy_id_maps で引く。
 */
final class LegacyCategoryUrl
{
    /**
     * 移した先のノードの詳細のパス(/category/{新ID})。
     * 対応が無い・数字でない・対応先のノードが消えた ID は、推測で転送しないよう null を返す。
     *
     * @param  string  $legacyTable  旧の表(LegacyCategoryNodes::LEGACY_CATEGORY・LEGACY_MIDDLE)
     */
    public static function nodePath(string $legacyTable, string $id): ?string
    {
        $nodeId = self::nodeId($legacyTable, $id);

        return $nodeId === null ? null : '/category/'.$nodeId;
    }

    /**
     * 移した先のノードの新 ID。旧の管理画面の編集の URL(/admin/edit/Category・/admin/edit/Middle。#817)も使う。
     * 対応が無い・数字でない・対応先のノードが消えた ID は null を返す。
     *
     * @param  string  $legacyTable  旧の表(LegacyCategoryNodes::LEGACY_CATEGORY・LEGACY_MIDDLE)
     */
    public static function nodeId(string $legacyTable, string $id): ?int
    {
        // int に収まらない桁の ID は、int にすると上限に丸まるので、どの対応にも当たらない(DetailPageController と同じ)
        $nodeId = ctype_digit($id)
            ? LegacyIdMap::newIdOf($legacyTable, (new Category)->getTable(), (int) $id)
            : null;

        if ($nodeId === null || ! Category::query()->whereKey($nodeId)->exists()) {
            return null;
        }

        return $nodeId;
    }
}
