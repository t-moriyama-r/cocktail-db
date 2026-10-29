<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\LegacyIdMap;

/**
 * 移行のテストから、旧のカテゴリ・ミドルと、移した先のカテゴリ木のノードの対応を legacy_id_maps で引く(#489)。
 */
final class LegacyNodeMap
{
    /** カテゴリ木のノードを書いた新しい表 */
    private const NODE_TABLE = 'categories';

    /**
     * 旧の表と ID から、移した先のノードの ID を引く。
     */
    public static function id(string $legacyTable, int $legacyId): int
    {
        return LegacyIdMap::query()
            ->where('legacy_table', $legacyTable)
            ->where('legacy_id', $legacyId)
            ->where('new_table', self::NODE_TABLE)
            ->sole()
            ->new_id;
    }

    /**
     * 旧の表と ID から、移した先のノードを引く。
     */
    public static function node(string $legacyTable, int $legacyId): Category
    {
        return Category::query()->findOrFail(self::id($legacyTable, $legacyId));
    }

    /**
     * 移した先のノードの ID から、旧の表と ID(「m_middle:3」の形)を引く。
     */
    public static function legacyOf(int $nodeId): string
    {
        $map = LegacyIdMap::query()->where('new_table', self::NODE_TABLE)->where('new_id', $nodeId)->sole();

        return "{$map->legacy_table}:{$map->legacy_id}";
    }
}
