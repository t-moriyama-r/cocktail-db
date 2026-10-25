<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 移行のテストのために、旧 DB と同じ構造の表をテスト用の DB(接続 legacy)に作り、小さな旧データを入れる。
 *
 * 旧 DB は外部キーを持たず、アプリも整合を保っていなかったため、本番には食い違ったデータが残っている(#28)。
 * 移行がそれを黙って捨てたり直したりしないかを確かめられるよう、その例を 1 件ずつ混ぜてある。
 * 値は本番の行を写さず、形だけを合わせて作ったもの。
 */
final class LegacyFixture
{
    /**
     * 表をすべて作り直してから旧データを入れる。何度呼んでも同じ状態になる。
     */
    public static function load(): void
    {
        $connection = DB::connection('legacy');
        self::assertTestDatabase($connection);

        $connection->getSchemaBuilder()->dropAllTables();
        foreach (self::schemaStatements() as $statement) {
            $connection->statement($statement);
        }

        foreach (self::rows() as $table => $rows) {
            $connection->table($table)->insert($rows);
        }
    }

    /**
     * 表を消して作り直すので、本番の旧 DB を向いていたら取り返しがつかない。
     * テスト用と分かる名前の DB でなければ、接続する前に止める。
     */
    private static function assertTestDatabase(Connection $connection): void
    {
        $database = $connection->getDatabaseName();
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("legacy 接続の DB 名が _test で終わらないため、旧データを入れない: {$database}");
        }
    }

    /**
     * PDO に複数の文をまとめて渡すと、2 つ目以降の文のエラーを取りこぼすため、1 文ずつに分ける。
     * schema.sql は DDL だけで、文字列の中に ; を含まない。
     *
     * @return list<string>
     */
    private static function schemaStatements(): array
    {
        $sql = file_get_contents(database_path('legacy/schema.sql'));
        $sql = preg_replace('/^--.*$/m', '', $sql);

        return array_values(array_filter(
            array_map('trim', preg_split('/;\s*$/m', $sql)),
            fn (string $statement) => $statement !== '',
        ));
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private static function rows(): array
    {
        return [
            'm_category_kinds' => [
                ['id' => 1, 'name' => '蒸留酒', 'name_eng' => 'Spirits', 'description' => ''],
                ['id' => 2, 'name' => '醸造酒', 'name_eng' => 'Brew', 'description' => ''],
                ['id' => 4, 'name' => 'ソフトドリンク', 'name_eng' => 'Non-alcoholic', 'description' => ''],
            ],
            'm_category' => [
                self::category(1, 1, 'ジン', 'Gin'),
                self::category(2, 1, 'ラム', 'Rum'),
                self::category(3, 2, 'ワイン', 'Wine'),
                self::category(4, 4, 'トニックウォーター', 'Tonic Water'),
                self::category(5, 4, 'ライムジュース', 'Lime Juice'),
            ],
            'm_middle' => [
                self::middle(1, 3, '赤ワイン', 'Red Wine'),
                self::middle(2, 3, '白ワイン', 'White Wine'),
                self::middle(3, 2, 'ダークラム', 'Dark Rum'),
            ],
            'm_brand' => [
                self::brand(1, 1, null, 'サンプル・ドライジン', 'Sample Dry Gin', 40),
                self::brand(2, 2, 3, 'サンプル・ダークラム', 'Sample Dark Rum', 40),
                // 異常: 銘柄の名前(name_search)がカテゴリ 4 と同じ
                self::brand(3, 4, null, 'トニックウォーター', 'Tonic Water', 0),
                // 異常: 銘柄のカテゴリ(1)と、そのミドル 3 の親(2)が合わない
                self::brand(4, 1, 3, 'サンプル・スパイスドラム', 'Sample Spiced Rum', 35),
            ],
            'm_method' => [
                ['id' => 1, 'name' => 'ビルド', 'name_eng' => 'Build', 'description' => ''],
                ['id' => 2, 'name' => 'ステア', 'name_eng' => 'Stir', 'description' => ''],
                ['id' => 3, 'name' => 'シェイク', 'name_eng' => 'Shake', 'description' => ''],
            ],
            'm_ice' => [
                ['id' => 1, 'name' => '氷入り', 'name_eng' => null],
            ],
            't_cocktails' => [
                self::cocktail(1, 'ジン・トニック', 'Gin and Tonic', base: 1, method: 1, recipeCount: 2),
                // 異常: recipe_count(3)が、任意でないレシピの行の数(5)と合わない
                self::cocktail(2, 'ラム・トニック', 'Rum and Tonic', base: 2, method: 1, recipeCount: 3),
                self::cocktail(3, 'サンプル下書き', null, base: 1, method: 3, recipeCount: 0, isDraft: true),
            ],
            't_recipies' => [
                self::recipe(1, 1, null, 1, '45ml'),
                self::recipe(1, 4, null, null, '適量'),
                self::recipe(1, 5, null, null, '1tsp', isOptional: true),
                // 異常: 同じカクテルで材料・銘柄まで同じ行が、量を変えて 2 行
                self::recipe(2, 2, 3, 2, '45ml'),
                self::recipe(2, 2, 3, 2, '30ml'),
                // 異常: 同じカクテルで材料・銘柄・量まで同じ行が 2 行
                self::recipe(2, 4, null, null, '適量'),
                self::recipe(2, 4, null, null, '適量'),
                // 異常: レシピのカテゴリ(1)と、ミドル 3 の親(2)が合わない
                self::recipe(2, 1, 3, null, '10ml'),
                // 異常: カクテル 99 は消えていて、レシピの行だけが残っている
                self::recipe(99, 1, null, null, '30ml'),
                self::recipe(99, 4, null, null, '適量'),
            ],
            'affiliates' => [
                self::affiliate(2, 3, 2, 'サンプル・ダークラム 700ml'),
                // 異常: ミドルが無いことを 0 で表している
                self::affiliate(1, 0, 1, 'サンプル・ドライジン 700ml'),
                // 異常: ミドルも銘柄も無いことを 0 で表している
                self::affiliate(4, 0, 0, 'トニックウォーター 500ml'),
            ],
            'm_dictionary_category' => [
                ['id' => 1, 'name' => '製法', 'name_eng' => 'method'],
            ],
            't_dictionary' => [
                [
                    'id' => 1, 'name' => 'ビルド', 'name_eng' => 'Build', 'name_search' => 'ビルド',
                    'description' => 'グラスに直接材料を注いで作る製法。', 'category' => 1,
                ],
            ],
            'paraphrases' => [
                ['name' => 'ドライジン', 'converted' => 'ジン', 'middle' => null, 'note' => null, 'command' => null],
                ['name' => '黒ラム', 'converted' => 'ラム', 'middle' => 'ダークラム', 'note' => null, 'command' => null],
            ],
            'skip_db' => [
                ['name' => 'サンプル除外語'],
            ],
            'users' => [
                [
                    'id' => 1, 'name' => 'テスト管理者', 'email' => 'admin@example.com',
                    'password' => password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
                    'twitter_id' => 'example',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function category(int $id, int $kind, string $name, string $nameEng): array
    {
        return ['id' => $id, 'kind' => $kind, 'name' => $name, 'name_search' => $name, 'name_eng' => $nameEng];
    }

    /**
     * @return array<string, mixed>
     */
    private static function middle(int $id, int $parent, string $name, string $nameEng): array
    {
        return [
            'id' => $id, 'parent' => $parent, 'name' => $name, 'name_search' => $name, 'name_eng' => $nameEng,
            'description' => '', 'is_like' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function brand(int $id, int $category, ?int $middle, string $name, string $nameEng, float $volume): array
    {
        return [
            'id' => $id, 'category' => $category, 'middle_category' => $middle,
            'name' => $name, 'name_search' => $name, 'name_eng' => $nameEng, 'volume' => $volume,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cocktail(
        int $id,
        string $name,
        ?string $nameEng,
        int $base,
        int $method,
        int $recipeCount,
        bool $isDraft = false,
    ): array {
        return [
            'id' => $id, 'name' => $name, 'name_search' => $name, 'name_eng' => $nameEng,
            'base' => $base, 'volume' => 10.5, 'method' => $method, 'ice' => 1,
            'recipe_count' => $recipeCount, 'is_draft' => $isDraft ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function recipe(
        int $cocktailId,
        int $category,
        ?int $middle,
        ?int $brand,
        string $amount,
        bool $isOptional = false,
    ): array {
        return [
            'cocktail_id' => $cocktailId, 'category' => $category, 'middle_category' => $middle,
            'brand' => $brand, 'amount' => $amount, 'is_optional' => $isOptional ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function affiliate(int $categoryId, int $middleId, int $brandId, string $name): array
    {
        return ['category_id' => $categoryId, 'middle_id' => $middleId, 'brand_id' => $brandId, 'name' => $name];
    }
}
