<?php

namespace App\Services\LegacyImport;

use App\Models\LegacyIdMap;
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
 * Eloquent を通さずに書くのは、作成・更新日時を旧の値(null を含む)のまま入れ、ノードの updated_at も動かさないため。
 */
final class BrandImporter implements LegacyImporter
{
    private const LEGACY_TABLE = 'm_brand';

    private const NEW_TABLE = 'brands';

    /** カテゴリ木のノードを書いた新しい表 */
    private const NODE_TABLE = 'categories';

    /** カテゴリ木のノードに移した旧の表のうち、銘柄の所属と有名な銘柄の列を持つもの */
    private const LEGACY_CATEGORY = 'm_category';

    private const LEGACY_MIDDLE = 'm_middle';

    /** @var array<string, array<int, int>> 旧の表 => (旧 ID => ノードの ID) */
    private array $nodeIds = [];

    public function import(): array
    {
        DB::transaction(function () {
            foreach ([self::LEGACY_CATEGORY, self::LEGACY_MIDDLE] as $legacyTable) {
                $this->nodeIds[$legacyTable] = LegacyIdMap::newIdsByLegacyId($legacyTable, self::NODE_TABLE);
            }

            $brandIds = KeptIdCopier::copy(self::LEGACY_TABLE, self::NEW_TABLE, $this->brandAttrs(...));

            foreach ([self::LEGACY_CATEGORY, self::LEGACY_MIDDLE] as $legacyTable) {
                $this->linkFamousBrands($legacyTable, array_flip($brandIds));
            }
        });

        return [self::NEW_TABLE => DB::table(self::NEW_TABLE)->count()];
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
            'category_id' => $this->brandNodeId($id, $row),
            'name' => $row['name'],
            'name_search' => $row['name_search'],
            'name_eng' => $row['name_eng'],
            'volume' => $this->volume($id, $row),
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
     * 銘柄が属するノード。旧は middle_category が無いとき、銘柄をカテゴリの直下に置いていた。
     *
     * @param  array<mixed>  $row
     */
    private function brandNodeId(int $id, array $row): int
    {
        $context = self::LEGACY_TABLE." の id {$id}";
        $middleId = LegacyRows::nullableId($row, 'middle_category', self::LEGACY_TABLE);

        return $middleId !== null
            ? $this->nodeId(self::LEGACY_MIDDLE, $middleId, $context)
            : $this->nodeId(self::LEGACY_CATEGORY, LegacyRows::int($row, 'category', self::LEGACY_TABLE), $context);
    }

    /**
     * 度数。旧の列は float なので、2 進で表せない値(1.7 など)が 1.7000000476837158 のように読めることがある。
     * 新の列(小数 1 桁)に合わせて丸める。丸めで値が変わるほど桁の多い値は、黙って丸めずに止める。
     *
     * @param  array<mixed>  $row
     */
    private function volume(int $id, array $row): string
    {
        if (! is_numeric($row['volume'] ?? null)) {
            throw new UnexpectedValueException(self::LEGACY_TABLE." の id {$id} の volume が数でない");
        }

        $volume = (float) $row['volume'];
        $rounded = round($volume, 1);
        if (abs($volume - $rounded) > 0.0001) {
            throw new UnexpectedValueException(self::LEGACY_TABLE." の id {$id} の volume({$volume})が小数 1 桁に収まらない");
        }

        return number_format($rounded, 1, '.', '');
    }

    /**
     * 旧のカテゴリ・ミドルの famous_brand_id を、移した先のノードに付ける。前の回に付けた値も、旧の値で書き直す。
     *
     * @param  array<int, int>  $brandIds  キーが旧の銘柄の ID(値は使わない)
     */
    private function linkFamousBrands(string $legacyTable, array $brandIds): void
    {
        foreach (LegacyRows::ordered($legacyTable, 'id') as $row) {
            $legacyId = LegacyRows::int($row, 'id', $legacyTable);
            $nodeId = $this->nodeId($legacyTable, $legacyId, "{$legacyTable} の id {$legacyId}");

            $brandId = LegacyRows::nullableId($row, 'famous_brand_id', $legacyTable);
            if ($brandId !== null && ! isset($brandIds[$brandId])) {
                throw new UnexpectedValueException("{$legacyTable} の id {$legacyId} の有名な銘柄 {$brandId} が旧に無い");
            }

            DB::table(self::NODE_TABLE)->where('id', $nodeId)->update(['famous_brand_id' => $brandId]);
        }
    }

    /**
     * 旧のカテゴリ・ミドルの ID から、移した先のノードの ID を引く。カテゴリ木をまだ移していなければ見つからない。
     *
     * @param  string  $context  見つからないときの例外に添える、どの行から引いたか
     */
    private function nodeId(string $legacyTable, int $legacyId, string $context): int
    {
        return $this->nodeIds[$legacyTable][$legacyId]
            ?? throw new UnexpectedValueException("{$context} が指す {$legacyTable} の id {$legacyId} を移した先のノードが無い(カテゴリ木を先に移す)");
    }
}
