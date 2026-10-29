<?php

namespace App\Console\Commands;

use App\Models\LegacyIdMap;
use App\Services\LegacyImport\LegacyRows;
use App\Support\PublicDiskImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 旧 DB(接続 legacy)から移した(legacy:import)結果が、旧と合っているかを調べる(#35)。読むだけで、何も書き換えない。
 *
 * 次を調べ、合わないものを一覧にして失敗する。
 * - 表ごとの件数。移さないと決めた行(docs/migration-anomalies.md の 1・2)は旧の側から除いて数える
 * - legacy_id_maps に、旧のすべての種別・カテゴリ・ミドル・銘柄・カクテルの対応があり、対応の先の行がある
 * - カクテルごとの材料の組と並び。新の値を legacy_id_maps で旧の ID に戻して、旧の材料の行と比べる
 * - tree:verify(カテゴリ木)と data:verify(木の外の整合性と説明文の参照。#157・#285)が通る
 * - 新しい DB の画像の列が指すファイルが、public ディスクにある
 *
 * 旧の材料の行は、移行と同じく ORDER BY を付けずに読んだ順を旧の並びとし、カクテルが消えた後に残った行(1)と、
 * 同じカクテルの中で材料・銘柄・量・任意まで同じ行の 2 行目以降(2)を除いてから比べる。ミドルのある行はミドルで
 * 比べ、カテゴリの列は見ない(3。#28 でミドルの親に合わせると決めた)。
 */
final class VerifyLegacy extends Command
{
    protected $signature = 'legacy:verify';

    protected $description = '旧 DB から移した結果(件数・ID の対応・材料・木・画像)が旧と合っているかを調べ、合わなければ一覧を出して失敗する';

    private const COUNT = '件数';

    private const ID_MAP = 'ID の対応(legacy_id_maps)';

    private const INGREDIENTS = '材料の組と並び';

    private const SUBCOMMAND = 'ほかの検査';

    private const IMAGE = '画像のファイル';

    private const TABLE_HEADERS = ['調べたこと', '対象', '内容'];

    private const LEGACY_RECIPE_TABLE = 't_recipies';

    /**
     * 旧の表の行をそのまま 1 行ずつ移す表。新しい表 => 移した元の旧の表。材料は移さない行があるので別に数える。
     *
     * @var array<string, list<string>>
     */
    private const COUNTED_TABLES = [
        'methods' => ['m_method'],
        'ices' => ['m_ice'],
        'dictionary_categories' => ['m_dictionary_category'],
        'dictionaries' => ['t_dictionary'],
        'paraphrases' => ['paraphrases'],
        'skip_words' => ['skip_db'],
        'users' => ['users'],
        'categories' => ['m_category_kinds', 'm_category', 'm_middle'],
        'brands' => ['m_brand'],
        'cocktails' => ['t_cocktails'],
        'affiliates' => ['affiliates'],
    ];

    /**
     * legacy_id_maps にすべての行の対応があるはずの旧の表 => 移した先の新しい表。
     *
     * @var array<string, string>
     */
    private const MAPPED_TABLES = [
        'm_category_kinds' => 'categories',
        'm_category' => 'categories',
        'm_middle' => 'categories',
        'm_brand' => 'brands',
        't_cocktails' => 'cocktails',
    ];

    /**
     * 画像の列(image)を持つ新しい表 => 表に出す名前。
     *
     * @var array<string, string>
     */
    private const IMAGE_TABLES = [
        'categories' => 'ノード',
        'brands' => '銘柄',
        'cocktails' => 'カクテル',
        'dictionaries' => '用語',
    ];

    /** ほかの検査のコマンド。失敗の中身は、そのコマンドが自分の出力に出す */
    private const SUBCOMMANDS = ['tree:verify', 'data:verify'];

    public function handle(): int
    {
        $expectedIngredients = $this->legacyIngredients();

        $violations = [
            ...$this->countViolations($expectedIngredients),
            ...$this->idMapViolations(),
            ...$this->ingredientViolations($expectedIngredients),
            ...$this->subcommandViolations(),
            ...$this->imageViolations(),
        ];

        if ($violations === []) {
            $this->info('問題なし');

            return self::SUCCESS;
        }

        $this->error(sprintf('移行の結果が旧と合わない(%d 件):', count($violations)));
        $this->table(self::TABLE_HEADERS, $violations);

        return self::FAILURE;
    }

    /**
     * 旧の材料の行を、移すものだけ、カクテルごとに旧の並びで返す。ノードは「旧の表:旧 ID」の形にする。
     *
     * @return array<int, list<string>> 旧のカクテルの ID => 材料(self::ingredientLabel の形)。材料の無いカクテルも空で入れる
     */
    private function legacyIngredients(): array
    {
        $ingredients = [];
        foreach (DB::connection('legacy')->table('t_cocktails')->orderBy('id')->pluck('id') as $id) {
            $ingredients[(int) self::str($id)] = [];
        }

        $seen = [];
        $table = self::LEGACY_RECIPE_TABLE;
        foreach (LegacyRows::inStoredOrder($table) as $row) {
            $cocktailId = LegacyRows::int($row, 'cocktail_id', $table);
            if (! isset($ingredients[$cocktailId])) {
                continue;
            }

            $middleId = LegacyRows::nullableId($row, 'middle_category', $table);
            $category = LegacyRows::int($row, 'category', $table);
            $node = $middleId !== null ? "m_middle:{$middleId}" : "m_category:{$category}";
            $label = self::ingredientLabel(
                $node,
                LegacyRows::nullableId($row, 'brand', $table),
                LegacyRows::string($row, 'amount', $table),
                (bool) $row['is_optional'],
            );

            // 移行と同じく、旧の列(カテゴリ・ミドル・銘柄・量・任意)まで同じ行は最初の 1 行だけを数える
            $sameRowKey = implode("\0", [$cocktailId, $category, $label]);
            if (isset($seen[$sameRowKey])) {
                continue;
            }
            $seen[$sameRowKey] = true;
            $ingredients[$cocktailId][] = $label;
        }

        return $ingredients;
    }

    /**
     * @param  array<int, list<string>>  $expectedIngredients
     * @return list<array{string, string, string}>
     */
    private function countViolations(array $expectedIngredients): array
    {
        $legacy = DB::connection('legacy');
        $counts = [];
        foreach (self::COUNTED_TABLES as $newTable => $legacyTables) {
            $legacyCount = 0;
            foreach ($legacyTables as $legacyTable) {
                $legacyCount += $legacy->table($legacyTable)->count();
            }
            $counts[] = [$newTable, implode('・', $legacyTables), $legacyCount];
        }
        $counts[] = [
            'cocktail_ingredients',
            self::LEGACY_RECIPE_TABLE.'。消えたカクテルの行と、同じ行の 2 行目以降を除く',
            array_sum(array_map(count(...), $expectedIngredients)),
        ];

        $violations = [];
        foreach ($counts as [$newTable, $legacyLabel, $legacyCount]) {
            $newCount = DB::table($newTable)->count();
            if ($newCount !== $legacyCount) {
                $violations[] = [self::COUNT, $newTable, "旧({$legacyLabel})は {$legacyCount} 件、新は {$newCount} 件"];
            }
        }

        return $violations;
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function idMapViolations(): array
    {
        $violations = [];
        foreach (self::MAPPED_TABLES as $legacyTable => $newTable) {
            $newIds = LegacyIdMap::newIdsByLegacyId($legacyTable, $newTable);
            $existing = array_flip(array_map(
                fn (mixed $id): int => (int) self::str($id),
                DB::table($newTable)->whereIn('id', array_values($newIds))->pluck('id')->all(),
            ));

            foreach (DB::connection('legacy')->table($legacyTable)->orderBy('id')->pluck('id') as $id) {
                $legacyId = (int) self::str($id);
                $newId = $newIds[$legacyId] ?? null;
                if ($newId === null) {
                    $violations[] = [self::ID_MAP, "{$legacyTable} #{$legacyId}", "{$newTable} への対応が無い"];
                } elseif (! isset($existing[$newId])) {
                    $violations[] = [self::ID_MAP, "{$legacyTable} #{$legacyId}", "対応の先の {$newTable} #{$newId} が無い"];
                }
            }
        }

        return $violations;
    }

    /**
     * 新の材料を、ノード・銘柄を legacy_id_maps で旧の ID に戻し、旧の材料と並びまで比べる。
     *
     * @param  array<int, list<string>>  $expectedIngredients
     * @return list<array{string, string, string}>
     */
    private function ingredientViolations(array $expectedIngredients): array
    {
        $legacyNodes = $this->legacyIdsByNewId(['m_category', 'm_middle'], 'categories');
        $legacyBrands = $this->legacyIdsByNewId(['m_brand'], 'brands');
        $cocktailIds = LegacyIdMap::newIdsByLegacyId('t_cocktails', 'cocktails');

        $actual = [];
        $rows = DB::table('cocktail_ingredients')
            ->whereIn('cocktail_id', array_values($cocktailIds))
            ->orderBy('cocktail_id')
            ->orderBy('position')
            ->get(['cocktail_id', 'category_id', 'brand_id', 'amount', 'is_optional']);
        foreach ($rows as $row) {
            $nodeId = (int) self::str($row->category_id);
            $brandId = $row->brand_id === null ? null : (int) self::str($row->brand_id);
            $actual[(int) self::str($row->cocktail_id)][] = self::ingredientLabel(
                $legacyNodes[$nodeId] ?? "旧に無いノード #{$nodeId}",
                $brandId === null ? null : ($legacyBrands[$brandId] ?? "旧に無い銘柄 #{$brandId}"),
                self::str($row->amount),
                (bool) $row->is_optional,
            );
        }

        $violations = [];
        foreach ($expectedIngredients as $legacyId => $expected) {
            $newId = $cocktailIds[$legacyId] ?? null;
            if ($newId === null) {
                // 対応の無いカクテルは idMapViolations が挙げる
                continue;
            }
            $got = $actual[$newId] ?? [];
            if ($got !== $expected) {
                $violations[] = [
                    self::INGREDIENTS,
                    "t_cocktails #{$legacyId}",
                    sprintf('旧: %s / 新: %s', self::ingredientList($expected), self::ingredientList($got)),
                ];
            }
        }

        return $violations;
    }

    /**
     * 新しい表の ID から、移した元の旧の行(「旧の表:旧 ID」の形)を引く。
     *
     * @param  list<string>  $legacyTables
     * @return array<int, string> 新 ID => 「旧の表:旧 ID」
     */
    private function legacyIdsByNewId(array $legacyTables, string $newTable): array
    {
        $legacyIds = [];
        foreach ($legacyTables as $legacyTable) {
            foreach (LegacyIdMap::newIdsByLegacyId($legacyTable, $newTable) as $legacyId => $newId) {
                $legacyIds[$newId] = "{$legacyTable}:{$legacyId}";
            }
        }

        return $legacyIds;
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function subcommandViolations(): array
    {
        $violations = [];
        foreach (self::SUBCOMMANDS as $command) {
            $this->line("{$command}:");
            if ($this->call($command) !== self::SUCCESS) {
                $violations[] = [self::SUBCOMMAND, $command, '失敗した(上の出力を見る)'];
            }
        }

        return $violations;
    }

    /**
     * 新しい DB の画像の列が指すファイルが無いもの。NULL・空文字は画像の無い行、外部の URL はファイルではないので見ない。
     *
     * @return list<array{string, string, string}>
     */
    private function imageViolations(): array
    {
        $disk = Storage::disk('public');
        $violations = [];
        foreach (self::IMAGE_TABLES as $table => $label) {
            $rows = DB::table($table)
                ->whereNotNull('image')
                ->where('image', '<>', '')
                ->orderBy('id')
                ->get(['id', 'image']);
            foreach ($rows as $row) {
                $image = self::str($row->image);
                if (! PublicDiskImage::isExternal($image) && ! PublicDiskImage::exists($disk, $image)) {
                    $violations[] = [self::IMAGE, "{$label} #".self::str($row->id), "image の {$image} が無い"];
                }
            }
        }

        return $violations;
    }

    /**
     * 材料 1 行を、比べて一覧に出す形にする。
     */
    private static function ingredientLabel(string $node, int|string|null $brand, string $amount, bool $isOptional): string
    {
        $brandLabel = match (true) {
            $brand === null => '銘柄なし',
            is_int($brand) => "m_brand:{$brand}",
            default => $brand,
        };

        return implode(' ', [$node, $brandLabel, $amount, ...($isOptional ? ['任意'] : [])]);
    }

    /**
     * @param  list<string>  $ingredients
     */
    private static function ingredientList(array $ingredients): string
    {
        return $ingredients === [] ? '(材料なし)' : implode('、', $ingredients);
    }

    /**
     * クエリビルダーで読んだ列の値(ドライバーによって int か文字列)を、文字列にする。
     */
    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
