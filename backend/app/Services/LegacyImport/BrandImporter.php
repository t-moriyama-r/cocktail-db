<?php

namespace App\Services\LegacyImport;

use App\Models\LegacyIdMap;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * 旧の銘柄(m_brand)を brands に移し、カテゴリ・ミドルのノードに有名な銘柄(famous_brand_id)を付ける(#30)。
 *
 * 銘柄は URL(/detail/brand/{id})を変えないため旧 ID のまま入れ、legacy_id_maps にも同じ値の対応を入れる
 * (docs/schema.md の ID の方針)。所属するノードは、カテゴリ木を移した #29 の legacy_id_maps で引くので、
 * カテゴリ木を先に移しておく必要がある。
 *
 * 旧の銘柄はカテゴリとミドルの両方の列を持ち、ミドルの親とカテゴリが合わない行がある(docs/migration-anomalies.md の 4)。
 * #28 でミドルの親に合わせると決めたので、ミドルがあればカテゴリの列は見ずにミドルのノードに付ける。
 *
 * 2 回目は消して入れ直さずに上書きする。材料・アフィリエイト・有名な銘柄が外部キーで銘柄を指すため。
 * Eloquent を通さずに書くのは、作成・更新日時を旧の値(null を含む)のまま入れ、ノードの updated_at も動かさないため。
 */
final class BrandImporter implements LegacyImporter
{
    private const LEGACY_TABLE = 'm_brand';

    private const NEW_TABLE = 'brands';

    /** 有名な銘柄の列を持つ旧の表。どれもカテゴリ木のノードに移してある */
    private const FAMOUS_BRAND_TABLES = ['m_category', 'm_middle'];

    public function import(): array
    {
        DB::transaction(function () {
            $categoryNodeIds = LegacyIdMap::newIdsByLegacyId('m_category', 'categories');
            $middleNodeIds = LegacyIdMap::newIdsByLegacyId('m_middle', 'categories');

            $rows = [];
            foreach (LegacyRows::ordered(self::LEGACY_TABLE, 'id') as $row) {
                $rows[] = $this->brandAttrs($row, $categoryNodeIds, $middleNodeIds);
            }
            DB::table(self::NEW_TABLE)->upsert($rows, ['id']);

            $ids = array_column($rows, 'id');
            LegacyIdMap::record(self::LEGACY_TABLE, self::NEW_TABLE, array_combine($ids, $ids));

            foreach (self::FAMOUS_BRAND_TABLES as $legacyTable) {
                $this->linkFamousBrands($legacyTable, array_flip($ids));
            }
        });

        return [self::NEW_TABLE => DB::table(self::NEW_TABLE)->count()];
    }

    /**
     * 旧の銘柄の行を、brands の列にする。
     *
     * @param  array<mixed>  $row
     * @param  array<int, int>  $categoryNodeIds  旧のカテゴリの ID => ノードの ID
     * @param  array<int, int>  $middleNodeIds  旧のミドルの ID => ノードの ID
     * @return array<string, mixed>
     */
    private function brandAttrs(array $row, array $categoryNodeIds, array $middleNodeIds): array
    {
        $id = LegacyRows::int($row, 'id', self::LEGACY_TABLE);

        return [
            'id' => $id,
            'category_id' => $this->nodeId($id, $row, $categoryNodeIds, $middleNodeIds),
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
     * 銘柄が属するノード。旧は middle_category が null のとき、銘柄をカテゴリの直下に置いていた。
     * 0 も null と同じに読む(旧の画面は middle_category を真偽で見て、0 をミドル無しとして扱っていた)。
     *
     * @param  array<mixed>  $row
     * @param  array<int, int>  $categoryNodeIds
     * @param  array<int, int>  $middleNodeIds
     */
    private function nodeId(int $id, array $row, array $categoryNodeIds, array $middleNodeIds): int
    {
        if (! empty($row['middle_category'])) {
            $middleId = LegacyRows::int($row, 'middle_category', self::LEGACY_TABLE);

            return $middleNodeIds[$middleId]
                ?? throw new UnexpectedValueException("m_brand の id {$id} のミドル {$middleId} を移した先のノードが無い(カテゴリ木を先に移す)");
        }

        $categoryId = LegacyRows::int($row, 'category', self::LEGACY_TABLE);

        return $categoryNodeIds[$categoryId]
            ?? throw new UnexpectedValueException("m_brand の id {$id} のカテゴリ {$categoryId} を移した先のノードが無い(カテゴリ木を先に移す)");
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
            throw new UnexpectedValueException("m_brand の id {$id} の volume が数でない");
        }

        $volume = (float) $row['volume'];
        $rounded = round($volume, 1);
        if (abs($volume - $rounded) > 0.0001) {
            throw new UnexpectedValueException("m_brand の id {$id} の volume({$volume})が小数 1 桁に収まらない");
        }

        return number_format($rounded, 1, '.', '');
    }

    /**
     * 旧のカテゴリ・ミドルの famous_brand_id を、移した先のノードに付ける。0 は null にする(旧は無いことを 0 でも表していた)。
     *
     * 前の回に付けた値も、旧の値で書き直す。
     *
     * @param  array<int, int>  $brandIds  キーが旧の銘柄の ID(値は使わない)
     */
    private function linkFamousBrands(string $legacyTable, array $brandIds): void
    {
        $nodeIds = LegacyIdMap::newIdsByLegacyId($legacyTable, 'categories');

        foreach (LegacyRows::ordered($legacyTable, 'id') as $row) {
            $legacyId = LegacyRows::int($row, 'id', $legacyTable);
            $nodeId = $nodeIds[$legacyId]
                ?? throw new UnexpectedValueException("{$legacyTable} の id {$legacyId} を移した先のノードが無い(カテゴリ木を先に移す)");

            $brandId = empty($row['famous_brand_id']) ? null : LegacyRows::int($row, 'famous_brand_id', $legacyTable);
            if ($brandId !== null && ! isset($brandIds[$brandId])) {
                throw new UnexpectedValueException("{$legacyTable} の id {$legacyId} の有名な銘柄 {$brandId} が旧に無い");
            }

            DB::table('categories')->where('id', $nodeId)->update(['famous_brand_id' => $brandId]);
        }
    }
}
