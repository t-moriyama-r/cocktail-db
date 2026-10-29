<?php

namespace App\Services\LegacyImport;

use App\Models\LegacyIdMap;
use App\Services\RecipeCounter;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * 旧のカクテル(t_cocktails)と材料(t_recipies)を、cocktails と cocktail_ingredients に移す(#31)。
 *
 * カクテルは URL(/detail/cocktail/{id})を変えないため旧 ID のまま入れる(docs/schema.md の ID の方針)。ベースと材料の
 * ノード・銘柄は、先に移したカテゴリ木(#29)と銘柄(#30)の legacy_id_maps で引くので、それらを先に移しておく必要がある。
 * 技法・氷・グラスは旧 ID のまま入れた表(#33)を指すので、ID を変えずに入れる。
 *
 * 旧の材料には主キーも並び順の列も無く、旧アプリは ORDER BY を付けずに読んだ順(入れた順)で材料を並べていた。
 * その順を残すため、材料も ORDER BY を付けずに読み、カクテルごとに読んだ順で position を 1 から振る。
 *
 * 旧データの食い違い(docs/migration-anomalies.md)のうち、ここで扱うもの:
 * - 1: カクテルが消えた後に残った材料の行は移さない
 * - 2: 1 つのカクテルの中で材料・銘柄・量まで同じ行は、最初の 1 行だけ移す(量が違えば両方移す)
 * - 3: ミドルの親とカテゴリが合わない材料の行は、カテゴリの列を見ずにミドルのノードに付ける
 * - 5: recipe_count は旧の値を使わず、移した材料から RecipeCounter で数え直す
 *
 * 2 回目は、カクテルを消さずに上書きし(銘柄などと同じく、ほかの表が外部キーで指しうるため)、材料は消して入れ直す。
 * Eloquent を通さずに書くのは、作成・更新日時を旧の値(null を含む)のまま入れるため。
 */
final class CocktailImporter implements LegacyImporter
{
    private const LEGACY_TABLE = 't_cocktails';

    private const NEW_TABLE = 'cocktails';

    private const LEGACY_RECIPE_TABLE = 't_recipies';

    private const INGREDIENT_TABLE = 'cocktail_ingredients';

    /** カテゴリ木のノードを書いた新しい表 */
    private const NODE_TABLE = 'categories';

    private const LEGACY_CATEGORY = 'm_category';

    private const LEGACY_MIDDLE = 'm_middle';

    private const LEGACY_BRAND = 'm_brand';

    /** 材料を一度に書く行数。プレースホルダの数の上限を超えないように分ける */
    private const INSERT_CHUNK = 1000;

    /** @var array<string, array<int, int>> 旧の表 => (旧 ID => 新 ID) */
    private array $newIds = [];

    public function __construct(private readonly RecipeCounter $counter) {}

    public function import(): array
    {
        DB::transaction(function () {
            foreach ([self::LEGACY_CATEGORY, self::LEGACY_MIDDLE] as $legacyTable) {
                $this->newIds[$legacyTable] = LegacyIdMap::newIdsByLegacyId($legacyTable, self::NODE_TABLE);
            }
            $this->newIds[self::LEGACY_BRAND] = LegacyIdMap::newIdsByLegacyId(self::LEGACY_BRAND, 'brands');

            $cocktailIds = KeptIdCopier::copy(self::LEGACY_TABLE, self::NEW_TABLE, $this->cocktailAttrs(...));
            $this->replaceIngredients($cocktailIds);
            $this->counter->recount($cocktailIds);
        });

        return [
            self::NEW_TABLE => DB::table(self::NEW_TABLE)->count(),
            self::INGREDIENT_TABLE => DB::table(self::INGREDIENT_TABLE)->count(),
        ];
    }

    /**
     * 旧のカクテルの行を、cocktails の列にする。recipe_count は入れない(材料を移した後に数え直す)。
     *
     * @param  array<mixed>  $row
     * @return array<string, mixed>
     */
    private function cocktailAttrs(array $row): array
    {
        $id = LegacyRows::int($row, 'id', self::LEGACY_TABLE);
        $context = self::LEGACY_TABLE." の id {$id}";

        return [
            'id' => $id,
            'name' => $row['name'],
            'name_search' => $row['name_search'],
            'name_eng' => $row['name_eng'],
            'base_category_id' => $this->newId(self::LEGACY_CATEGORY, LegacyRows::int($row, 'base', self::LEGACY_TABLE), "{$context} のベース"),
            'method_id' => LegacyRows::int($row, 'method', self::LEGACY_TABLE),
            'glass_dictionary_id' => LegacyRows::nullableId($row, 'glass', self::LEGACY_TABLE),
            'ice_id' => LegacyRows::nullableId($row, 'ice', self::LEGACY_TABLE),
            // 度数は計算し直さず、旧の値をそのまま移す(docs/decisions/strength.md)
            'volume' => LegacyVolume::fromLegacy($row['volume'] ?? null, $context),
            'sweet_degree' => $row['sweet_degree'],
            'rate' => $row['rate'],
            'description' => $row['description'],
            'note' => $row['note'],
            'is_draft' => (bool) $row['is_draft'],
            'is_major' => (bool) $row['is_major'],
            'instagram' => $row['instagram'],
            'youtube' => $row['youtube'],
            'youtube_created_at' => $row['cc_youtube_created_at'],
            'youtube_start_seconds' => YoutubeStartSeconds::fromLegacy($row['y_hour'], $row['y_minute'], $row['y_second']),
            'image' => $row['image'],
            'image_txt' => $row['image_txt'],
            'image_url' => $row['image_url'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * $cocktailIds のカクテルの材料を、旧の材料の行で入れ直す。
     *
     * @param  list<int>  $cocktailIds
     */
    private function replaceIngredients(array $cocktailIds): void
    {
        $isImported = array_flip($cocktailIds);
        $ingredients = [];
        $positions = [];
        $seen = [];
        foreach (LegacyRows::inStoredOrder(self::LEGACY_RECIPE_TABLE) as $row) {
            $cocktailId = LegacyRows::int($row, 'cocktail_id', self::LEGACY_RECIPE_TABLE);
            if (! isset($isImported[$cocktailId])) {
                continue;
            }

            $ingredient = $this->ingredientAttrs($cocktailId, $row);
            $sameRowKey = implode("\0", [
                $cocktailId,
                LegacyRows::int($row, 'category', self::LEGACY_RECIPE_TABLE),
                LegacyRows::nullableId($row, 'middle_category', self::LEGACY_RECIPE_TABLE) ?? '',
                LegacyRows::nullableId($row, 'brand', self::LEGACY_RECIPE_TABLE) ?? '',
                $ingredient['amount'],
                (int) $ingredient['is_optional'],
            ]);
            if (isset($seen[$sameRowKey])) {
                continue;
            }
            $seen[$sameRowKey] = true;

            $positions[$cocktailId] = ($positions[$cocktailId] ?? 0) + 1;
            $ingredients[] = ['cocktail_id' => $cocktailId, 'position' => $positions[$cocktailId], ...$ingredient];
        }

        DB::table(self::INGREDIENT_TABLE)->whereIn('cocktail_id', $cocktailIds)->delete();
        foreach (array_chunk($ingredients, self::INSERT_CHUNK) as $chunk) {
            DB::table(self::INGREDIENT_TABLE)->insert($chunk);
        }
    }

    /**
     * 旧の材料の行を、cocktail_ingredients の列(カクテルと並び順を除く)にする。
     * ノードは、ミドルがあればミドル、無ければカテゴリ。銘柄の所属ノードには合わせない(docs/migration-anomalies.md の 11)。
     *
     * @param  array<mixed>  $row
     * @return array{category_id: int, brand_id: int|null, amount: string, is_optional: bool}
     */
    private function ingredientAttrs(int $cocktailId, array $row): array
    {
        $context = self::LEGACY_RECIPE_TABLE." の cocktail_id {$cocktailId} の行";
        $middleId = LegacyRows::nullableId($row, 'middle_category', self::LEGACY_RECIPE_TABLE);
        $brandId = LegacyRows::nullableId($row, 'brand', self::LEGACY_RECIPE_TABLE);

        return [
            'category_id' => $middleId !== null
                ? $this->newId(self::LEGACY_MIDDLE, $middleId, $context)
                : $this->newId(self::LEGACY_CATEGORY, LegacyRows::int($row, 'category', self::LEGACY_RECIPE_TABLE), $context),
            'brand_id' => $brandId === null ? null : $this->newId(self::LEGACY_BRAND, $brandId, $context),
            'amount' => LegacyRows::string($row, 'amount', self::LEGACY_RECIPE_TABLE),
            'is_optional' => (bool) $row['is_optional'],
        ];
    }

    /**
     * 旧の表の ID から、先に移した先の新 ID を引く。カテゴリ木・銘柄をまだ移していなければ見つからない。
     *
     * @param  string  $context  見つからないときの例外に添える、どの行から引いたか
     */
    private function newId(string $legacyTable, int $legacyId, string $context): int
    {
        return $this->newIds[$legacyTable][$legacyId]
            ?? throw new UnexpectedValueException("{$context} が指す {$legacyTable} の id {$legacyId} を移した先が無い(カテゴリ木・銘柄を先に移す)");
    }
}
