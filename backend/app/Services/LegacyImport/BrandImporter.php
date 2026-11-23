<?php

namespace App\Services\LegacyImport;

use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * 旧の銘柄(m_brand)を brands に移し、カテゴリ・ミドルのノードに有名な銘柄(famous_brand_id)を付ける(#30)。
 *
 * 銘柄は URL(/detail/brand/{id})を変えないため旧 ID のまま入れる(docs/schema.md の ID の方針)。所属するノードは、
 * カテゴリ木を移した #29 の legacy_id_maps で引くので、カテゴリ木を先に移しておく必要がある。
 *
 * 旧の銘柄はカテゴリとミドルの両方の列を持ち、ミドルの親とカテゴリが合わない行がある(docs/migration-anomalies.md の 4)。
 * #28 でミドルの親に合わせると決めたので、ミドルがあればカテゴリの列は見ずにミドルのノードに付ける。
 *
 * 有名な銘柄にしているミドルの部分木の外にある銘柄(グリーン バナナ)は、そのミドルの下へ移す(#752。
 * docs/migration-anomalies.md の 15)。
 *
 * Eloquent を通さずに書くのは、作成・更新日時を旧の値(null を含む)のまま入れ、ノードの updated_at も動かさないため。
 */
final class BrandImporter implements LegacyImporter
{
    private const LEGACY_TABLE = 'm_brand';

    private const NEW_TABLE = 'brands';

    /** カテゴリ木のノードを書いた新しい表 */
    private const NODE_TABLE = 'categories';

    /** 移した後の件数とは別に出す、ミドルの下へ移した銘柄の数の名前 */
    private const MOVED_TO_MIDDLE_COUNT = 'ミドルの下へ移した銘柄';

    /**
     * 旧の所属のまま移すと、有名な銘柄にしているミドルの部分木の外になる銘柄 => 移す先のミドル(どちらも旧 ID。#752)。
     * 旧のミドル 58(グリーン)の有名な銘柄は銘柄 138(グリーン バナナ)だが、銘柄 138 はミドル 58 の親のカテゴリ 94
     * (バナナ・リキュール)の直下にある。旧の画面は、ミドルの有名な銘柄に親のカテゴリの直下の銘柄も選べた
     * (docs/decisions/strength.md の「旧との差分」)。
     *
     * 旧の行がまだその形(ミドルが無く、カテゴリが移す先のミドルの親)のときだけ移す。移行までに旧で直されていたら、
     * 旧の所属のまま移す。
     *
     * @var array<int, int>
     */
    private const MOVED_TO_MIDDLE = [138 => 58];

    private LegacyCategoryNodes $nodes;

    /** @var array<int, int> 移す先のミドルの旧 ID => その親のカテゴリの旧 ID(旧に無いミドルは入らない) */
    private array $movedToMiddleParents = [];

    private int $movedToMiddle = 0;

    public function import(): array
    {
        DB::transaction(function () {
            $this->nodes = LegacyCategoryNodes::load();
            $this->movedToMiddleParents = self::middleParents(array_values(self::MOVED_TO_MIDDLE));
            $this->movedToMiddle = 0;

            $brandIds = KeptIdCopier::copy(self::LEGACY_TABLE, self::NEW_TABLE, $this->brandAttrs(...));

            foreach ([LegacyCategoryNodes::LEGACY_CATEGORY, LegacyCategoryNodes::LEGACY_MIDDLE] as $legacyTable) {
                $this->linkFamousBrands($legacyTable, array_flip($brandIds));
            }
        });

        return [
            self::NEW_TABLE => DB::table(self::NEW_TABLE)->count(),
            self::MOVED_TO_MIDDLE_COUNT => $this->movedToMiddle,
        ];
    }

    /**
     * 旧のミドルの親のカテゴリを読む。
     *
     * @param  list<int>  $middleIds
     * @return array<int, int> ミドルの旧 ID => 親のカテゴリの旧 ID
     */
    private static function middleParents(array $middleIds): array
    {
        $table = LegacyCategoryNodes::LEGACY_MIDDLE;
        $parents = [];
        foreach (DB::connection('legacy')->table($table)->whereIn('id', $middleIds)->get(['id', 'parent']) as $row) {
            $row = (array) $row;
            $parents[LegacyRows::int($row, 'id', $table)] = LegacyRows::int($row, 'parent', $table);
        }

        return $parents;
    }

    /**
     * 銘柄の所属するノード。ふだんは旧の行のカテゴリ・ミドルの列で引き、MOVED_TO_MIDDLE の銘柄は移す先のミドルにする。
     *
     * @param  array<mixed>  $row
     */
    private function brandNodeId(array $row, int $id): int
    {
        $context = self::LEGACY_TABLE." の id {$id}";
        $middleId = self::MOVED_TO_MIDDLE[$id] ?? null;
        if (
            $middleId !== null
            && LegacyRows::nullableId($row, 'middle_category', self::LEGACY_TABLE) === null
            && isset($this->movedToMiddleParents[$middleId])
            && $this->movedToMiddleParents[$middleId] === LegacyRows::int($row, 'category', self::LEGACY_TABLE)
        ) {
            $this->movedToMiddle++;

            return $this->nodes->nodeId(LegacyCategoryNodes::LEGACY_MIDDLE, $middleId, $context);
        }

        return $this->nodes->forRow($row, self::LEGACY_TABLE, $context);
    }

    /**
     * 旧の銘柄の行を、brands の列にする。
     *
     * @param  array<mixed>  $row
     * @return array<string, mixed>
     */
    private function brandAttrs(array $row): array
    {
        $id = LegacyRows::int($row, 'id', self::LEGACY_TABLE);

        return [
            'id' => $id,
            'category_id' => $this->brandNodeId($row, $id),
            'name' => $row['name'],
            'name_search' => $row['name_search'],
            'name_eng' => $row['name_eng'],
            'volume' => LegacyVolume::fromLegacy($row['volume'] ?? null, self::LEGACY_TABLE." の id {$id}"),
            'description' => $row['description'],
            'is_major' => (bool) $row['is_major'],
            'is_like' => (bool) $row['is_like'],
            'instagram' => $row['instagram'],
            'youtube' => $row['youtube'],
            'youtube_created_at' => $row['youtube_created_at'],
            'youtube_start_seconds' => YoutubeStartSeconds::fromLegacy($row['y_hour'], $row['y_minute'], $row['y_second']),
            'image' => $row['image'],
            'image_txt' => $row['image_txt'],
            'image_url' => $row['image_url'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * 旧のカテゴリ・ミドルの famous_brand_id を、移した先のノードに付ける。前の回に付けた値も、旧の値で書き直す。
     *
     * @param  LegacyCategoryNodes::LEGACY_CATEGORY|LegacyCategoryNodes::LEGACY_MIDDLE  $legacyTable
     * @param  array<int, int>  $brandIds  キーが旧の銘柄の ID(値は使わない)
     */
    private function linkFamousBrands(string $legacyTable, array $brandIds): void
    {
        foreach (LegacyRows::ordered($legacyTable, 'id') as $row) {
            $legacyId = LegacyRows::int($row, 'id', $legacyTable);
            $nodeId = $this->nodes->nodeId($legacyTable, $legacyId, "{$legacyTable} の id {$legacyId}");

            $brandId = LegacyRows::nullableId($row, 'famous_brand_id', $legacyTable);
            if ($brandId !== null && ! isset($brandIds[$brandId])) {
                throw new UnexpectedValueException("{$legacyTable} の id {$legacyId} の有名な銘柄 {$brandId} が旧に無い");
            }

            DB::table(self::NODE_TABLE)->where('id', $nodeId)->update(['famous_brand_id' => $brandId]);
        }
    }
}
