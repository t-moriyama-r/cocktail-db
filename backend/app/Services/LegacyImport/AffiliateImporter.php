<?php

namespace App\Services\LegacyImport;

use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\LegacyIdMap;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * 旧のアフィリエイト(affiliates)を affiliates に移す(#32)。
 *
 * 旧はカテゴリ・ミドル・銘柄の 3 つの ID の複合キーで、無いところを 0 にしていた(docs/migration-anomalies.md の 9)。
 * 新しい表はノード(いちばん深いもの)と銘柄(null 可)で 1 行を決める。銘柄の行のノードは、旧の行のカテゴリ・ミドルではなく
 * 移した銘柄の所属ノードにする。銘柄の行のノードは銘柄の所属と同じにする決まり(docs/schema.md)で、旧の行のカテゴリが
 * ミドルの親と合わない行(docs/migration-anomalies.md の 12)も、銘柄を移した #30 と同じノードに付く。
 * そのため、カテゴリ木と銘柄を先に移しておく必要がある。
 *
 * 旧は ID を持たないので、新の ID は振り直す。旧の行を指す 1 つの ID が無いので legacy_id_maps には書かない。
 * 2 回目は、銘柄の行は銘柄で、ノードの行はノードで前の回の行を見つけて書き直し、ID を変えない(銘柄の所属が変わっても
 * 行を増やさない)。旧から消えた行は消す。ほかの表は affiliates を指さないので、消しても困るものは無い。
 *
 * Eloquent を通さずに書くのは、作成・更新日時を旧の値のまま入れるため。
 */
final class AffiliateImporter implements LegacyImporter
{
    private const LEGACY_TABLE = 'affiliates';

    private const NEW_TABLE = 'affiliates';

    /** カテゴリ木のノードを書いた新しい表 */
    private const NODE_TABLE = 'categories';

    private const LEGACY_CATEGORY = 'm_category';

    private const LEGACY_MIDDLE = 'm_middle';

    /** @var array<string, array<int, int>> 旧の表 => (旧 ID => ノードの ID) */
    private array $nodeIds = [];

    /** @var array<int, int> 銘柄の ID => 所属ノードの ID */
    private array $brandNodeIds = [];

    public function import(): array
    {
        DB::transaction(function () {
            foreach ([self::LEGACY_CATEGORY, self::LEGACY_MIDDLE] as $legacyTable) {
                $this->nodeIds[$legacyTable] = LegacyIdMap::newIdsByLegacyId($legacyTable, self::NODE_TABLE);
            }
            foreach (Brand::query()->get(['id', 'category_id']) as $brand) {
                $this->brandNodeIds[$brand->id] = $brand->category_id;
            }

            $rows = [];
            // 旧はキーの 3 列の複合の主キーで、id の列が無い
            foreach (LegacyRows::ordered(self::LEGACY_TABLE, 'category_id', 'middle_id', 'brand_id') as $row) {
                $brandId = LegacyRows::nullableId($row, 'brand_id', self::LEGACY_TABLE);
                $categoryId = $brandId !== null ? $this->brandNodeId($brandId, $row) : $this->nodeOnlyId($row);
                $key = self::rowKey($categoryId, $brandId);
                if (isset($rows[$key])) {
                    throw new UnexpectedValueException(self::LEGACY_TABLE." の {$this->describe($row)} が、ほかの行と同じノード・銘柄の行になる");
                }
                $rows[$key] = ['category_id' => $categoryId, 'brand_id' => $brandId, ...$this->valueAttrs($row)];
            }

            $this->write($rows);
        });

        return [self::NEW_TABLE => DB::table(self::NEW_TABLE)->count()];
    }

    /**
     * 銘柄の行のノード。移した銘柄の所属ノードにする(旧の行のカテゴリ・ミドルは見ない)。
     *
     * @param  array<mixed>  $row
     */
    private function brandNodeId(int $brandId, array $row): int
    {
        return $this->brandNodeIds[$brandId]
            ?? throw new UnexpectedValueException(self::LEGACY_TABLE." の {$this->describe($row)} が指す銘柄 {$brandId} を移した先が無い(銘柄を先に移す)");
    }

    /**
     * 銘柄の無い行のノード。旧はミドルが無いことを 0 で表していた。
     *
     * @param  array<mixed>  $row
     */
    private function nodeOnlyId(array $row): int
    {
        $middleId = LegacyRows::nullableId($row, 'middle_id', self::LEGACY_TABLE);
        [$legacyTable, $legacyId] = $middleId !== null
            ? [self::LEGACY_MIDDLE, $middleId]
            : [self::LEGACY_CATEGORY, LegacyRows::int($row, 'category_id', self::LEGACY_TABLE)];

        return $this->nodeIds[$legacyTable][$legacyId]
            ?? throw new UnexpectedValueException(self::LEGACY_TABLE." の {$this->describe($row)} が指す {$legacyTable} の id {$legacyId} を移した先のノードが無い(カテゴリ木を先に移す)");
    }

    /**
     * 1 行を決めるキー。銘柄の行のノードは銘柄の所属で決まるので、銘柄の行は銘柄だけで決まる。
     */
    private static function rowKey(int $categoryId, ?int $brandId): string
    {
        return $brandId !== null ? "brand:{$brandId}" : "node:{$categoryId}";
    }

    /**
     * 例外に添える、どの旧の行か。
     *
     * @param  array<mixed>  $row
     */
    private function describe(array $row): string
    {
        $key = fn (string $column): string => is_numeric($row[$column] ?? null) ? (string) $row[$column] : '?';

        return "(category_id {$key('category_id')}, middle_id {$key('middle_id')}, brand_id {$key('brand_id')})の行";
    }

    /**
     * 旧のアフィリエイトの行の、キー(ノード・銘柄)のほかの列を、affiliates の列にする。
     *
     * 旧は Amazon と楽天の値を 1 つの updated_at で持っていた。新は提供元ごとに取った時刻を持ち、旧の値を取ったのは
     * updated_at とみなす。値の無い提供元は取ったことが無いので null にする(docs/decisions/affiliate-freshness.md の 6)。
     *
     * @param  array<mixed>  $row
     * @return array<string, mixed>
     */
    private function valueAttrs(array $row): array
    {
        $amazon = [
            'amazon_title' => $row['title'],
            'amazon_price' => $row['price'],
            'amazon_image_large_url' => $row['img_large_url'],
            'amazon_image_medium_url' => $row['img_medium_url'],
            'amazon_image_small_url' => $row['img_small_url'],
            'amazon_detail_url' => $row['amazon_detail_url'],
            'amazon_search_url' => $row['amazon_search_url'],
        ];
        $rakuten = [
            'rakuten_title' => $row['rakuten_title'],
            'rakuten_price' => $row['rakuten_price'],
            'rakuten_image_url' => $row['rakuten_image_url'],
            'rakuten_detail_url' => $row['rakuten_detail_url'],
            'rakuten_search_url' => $row['rakuten_search_url'],
        ];
        $fetchedAt = fn (array $values): mixed => array_filter($values, fn (mixed $value): bool => $value !== null) !== []
            ? $row['updated_at']
            : null;

        return [
            ...$amazon,
            'amazon_asin' => AffiliateItemIds::asin($row['amazon_detail_url']),
            'amazon_fetched_at' => $fetchedAt($amazon),
            ...$rakuten,
            'rakuten_item_code' => AffiliateItemIds::rakutenItemCode($row['rakuten_detail_url']),
            'rakuten_fetched_at' => $fetchedAt($rakuten),
            'human_title' => $row['human_title'],
            'human_price' => $row['human_price'],
            'human_image_url' => $row['human_image_url'],
            'human_amazon_detail_url' => $row['human_amazon_detail_url'],
            'human_amazon_search_url' => $row['human_amazon_search_url'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * 前の回の行を、銘柄の行は銘柄で、ノードの行はノードで見つけて書き直す。無ければ足し、旧から消えた行は消す。
     *
     * @param  array<string, array<string, mixed>>  $rows  rowKey => 書く列
     */
    private function write(array $rows): void
    {
        $existingIds = [];
        foreach (Affiliate::query()->get(['id', 'category_id', 'brand_id']) as $existing) {
            $existingIds[self::rowKey($existing->category_id, $existing->brand_id)] = $existing->id;
        }

        $keptIds = [];
        foreach ($rows as $key => $attrs) {
            $id = $existingIds[$key] ?? null;
            if ($id === null) {
                $keptIds[] = DB::table(self::NEW_TABLE)->insertGetId($attrs);
            } else {
                DB::table(self::NEW_TABLE)->where('id', $id)->update($attrs);
                $keptIds[] = $id;
            }
        }

        DB::table(self::NEW_TABLE)->whereNotIn('id', $keptIds)->delete();
    }
}
