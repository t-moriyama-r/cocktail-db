<?php

namespace App\Services\LegacyImport;

use App\Enums\CategoryRole;
use App\Models\Category;
use App\Models\LegacyIdMap;
use App\Services\CategoryTree;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * 旧の種別・カテゴリ・ミドルを、カテゴリ木のノードに移す(#29)。種別は根、カテゴリは種別の子、ミドルはカテゴリの子になる。
 *
 * カテゴリとミドルは旧 ID がぶつかるので、ノードの ID は振り直し、旧 ID との対応を legacy_id_maps に残す
 * (docs/schema.md の ID の方針)。2 回目は、この対応で前に作ったノードを見つけて値を書き直す。消して作り直すと
 * ノードの ID が変わり、先に移した銘柄・材料などが指す先を失うため。閉包表は CategoryTree を通してだけ書く。
 *
 * 有名な銘柄(famous_brand_id)は、銘柄がまだ無いので外部キーを満たせない。作るときは null で入れ、書き直すときも
 * 触らない(銘柄を移す #30 が付ける。2 回目に null へ戻すと、その付け替えを消してしまう)。
 */
final class CategoryImporter implements LegacyImporter
{
    /** 根の役割を持つ旧の種別の ID(docs/decisions/category-root-role.md)。ほかの種別の根は役割を持たない */
    private const ROLE_BY_LEGACY_KIND = [
        4 => CategoryRole::SoftDrink,
        5 => CategoryRole::Other,
    ];

    public function __construct(private readonly CategoryTree $tree) {}

    public function import(): array
    {
        DB::transaction(function () {
            $kindNodeIds = $this->importLevel('m_category_kinds', null, fn (array $row) => [
                'name' => $row['name'],
                // 旧の種別には name_search の列が無いので、新で作るときと同じ変換で作る
                'name_search' => NameNormalizer::normalize(self::stringColumn($row, 'name', 'm_category_kinds')),
                'name_eng' => $row['name_eng'],
                'description' => $row['description'],
                'role' => self::ROLE_BY_LEGACY_KIND[self::intColumn($row, 'id', 'm_category_kinds')] ?? null,
                // 旧の種別には作成・更新日時の列が無い
                'created_at' => null,
                'updated_at' => null,
            ]);
            $categoryNodeIds = $this->importLevel('m_category', [$kindNodeIds, 'kind'], $this->nodeAttrs(...));
            $this->importLevel('m_middle', [$categoryNodeIds, 'parent'], $this->nodeAttrs(...));
        });

        return [
            'categories' => DB::table('categories')->count(),
            'category_closures' => DB::table('category_closures')->count(),
        ];
    }

    /**
     * 旧の表 1 つの行を、1 段のノードにする。前の回に作ったノードは書き直し、無ければ作る。
     *
     * 並び順は、旧に列が無いので、同じ親の下で旧の ID の昇順に 1 から振る(docs/schema.md の「並び順」)。
     *
     * @param  array{array<int, int>, string}|null  $parent  親の段の「旧 ID => ノードの ID」と、親の旧 ID を持つ列。根の段では null
     * @param  callable(array<mixed>): array<string, mixed>  $toAttrs  旧の行 → ノードの列(並び順を除く)
     * @return array<int, int> 旧 ID => ノードの ID
     */
    private function importLevel(string $legacyTable, ?array $parent, callable $toAttrs): array
    {
        $existing = [];
        foreach (LegacyIdMap::query()->where('legacy_table', $legacyTable)->where('new_table', 'categories')->get() as $map) {
            $existing[$map->legacy_id] = $map->new_id;
        }

        $nodeIds = [];
        $siblingCounts = [];
        foreach ($this->legacyRows($legacyTable) as $row) {
            $legacyId = self::intColumn($row, 'id', $legacyTable);
            $parentNodeId = null;
            if ($parent !== null) {
                [$parentNodeIds, $parentColumn] = $parent;
                $parentLegacyId = self::intColumn($row, $parentColumn, $legacyTable);
                $parentNodeId = $parentNodeIds[$parentLegacyId]
                    ?? throw new UnexpectedValueException("{$legacyTable} の id {$legacyId} の親({$parentColumn} = {$parentLegacyId})が旧に無い");
            }
            $siblingKey = $parentNodeId ?? 0;
            $siblingCounts[$siblingKey] = ($siblingCounts[$siblingKey] ?? 0) + 1;

            $attrs = [...$toAttrs($row), 'sort_order' => $siblingCounts[$siblingKey]];
            $nodeIds[$legacyId] = isset($existing[$legacyId])
                ? $this->updateNode($legacyTable, $legacyId, $existing[$legacyId], $parentNodeId, $attrs)
                : $this->createNode($parentNodeId, $attrs);
        }

        LegacyIdMap::record($legacyTable, 'categories', $nodeIds);

        return $nodeIds;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return int 作ったノードの ID
     */
    private function createNode(?int $parentNodeId, array $attrs): int
    {
        $parent = $parentNodeId === null ? null : Category::query()->findOrFail($parentNodeId);
        $timestamps = ['created_at' => $attrs['created_at'], 'updated_at' => $attrs['updated_at']];
        unset($attrs['created_at'], $attrs['updated_at']);
        $node = $this->tree->addNode($parent, [...$attrs, 'famous_brand_id' => null]);

        // Category は作成・更新日時を自分で付けるので、旧の日時(null を含む)は作った後に書く
        $this->writeColumns($node->id, $timestamps);

        return $node->id;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return int 書き直したノードの ID
     */
    private function updateNode(string $legacyTable, int $legacyId, int $nodeId, ?int $parentNodeId, array $attrs): int
    {
        $node = Category::query()->find($nodeId)
            ?? throw new UnexpectedValueException("{$legacyTable} の id {$legacyId} の移した先のノード {$nodeId} が無い");

        if ($node->parent?->id !== $parentNodeId) {
            $this->tree->moveSubtree($node, $parentNodeId === null ? null : Category::query()->findOrFail($parentNodeId));
        }

        $this->writeColumns($nodeId, $attrs);

        return $nodeId;
    }

    /**
     * ノードの列を、Eloquent を通さずに書く。通すと更新日時が今の時刻になり、旧の日時が残らない。
     *
     * @param  array<string, mixed>  $columns
     */
    private function writeColumns(int $nodeId, array $columns): void
    {
        if (array_key_exists('role', $columns)) {
            $columns['role'] = $columns['role'] instanceof CategoryRole ? $columns['role']->value : null;
        }

        DB::table('categories')->where('id', $nodeId)->update($columns);
    }

    /**
     * 旧のカテゴリ・ミドルの行を、ノードの列にする。2 つの表は同じ列を持つ。
     *
     * @param  array<mixed>  $row
     * @return array<string, mixed>
     */
    private function nodeAttrs(array $row): array
    {
        return [
            'name' => $row['name'],
            'name_search' => $row['name_search'],
            'name_eng' => $row['name_eng'],
            'description' => $row['description'],
            'role' => null,
            'is_major' => (bool) $row['is_major'],
            'is_like' => (bool) $row['is_like'],
            'is_powder' => (bool) $row['is_powder'],
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
     * @return list<array<mixed>> 旧の行(ID の昇順)
     */
    private function legacyRows(string $table): array
    {
        $rows = [];
        foreach (DB::connection('legacy')->table($table)->orderBy('id')->get() as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function intColumn(array $row, string $column, string $table): int
    {
        return is_numeric($row[$column] ?? null) ? (int) $row[$column] : throw new UnexpectedValueException("{$table} の {$column} が数でない");
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function stringColumn(array $row, string $column, string $table): string
    {
        return is_string($row[$column] ?? null) ? $row[$column] : throw new UnexpectedValueException("{$table} の {$column} が文字列でない");
    }
}
