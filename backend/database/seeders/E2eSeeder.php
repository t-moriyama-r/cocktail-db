<?php

namespace Database\Seeders;

use App\Enums\CategoryRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Method;
use App\Services\CategoryTree;
use Illuminate\Database\Seeder;

/**
 * E2E テスト(e2e/)が前提にするデータを入れる。
 * 本番のデータは使わず、画面の E2E が要るデータをここに足していく。
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        $nodes = $this->seedIngredientTree(app(CategoryTree::class));
        $this->seedHeaderData($nodes['gin'], $nodes['beefeater']);
        $this->seedTopData($nodes['vodka']);
    }

    /**
     * トップ(#50)の E2E 用のカクテル。3 つの一覧がどれも 7 件になり、新着動画が出るようにする。
     *
     * - 公開で評価 3 以上を 7 件(おすすめの一覧はちょうどこの 7 件になる)。どれも名前に「ジン」を入れない(ヘッダーの名前検索の件数を変えない)
     * - そのうち「モスコミュール」だけに動画と説明文を付け、新着動画にする
     * - 下書きを 1 件。更新・動画の公開がいちばん新しく、評価も 5 にして、下書きを返してしまうとどの一覧・新着動画にも出るようにする
     *
     * 公開のカクテルは、ヘッダーのジントニックと合わせて 8 件になる(ヘッダーの登録数は 8)。
     */
    private function seedTopData(Category $vodka): void
    {
        $method = Method::factory()->create(['name' => 'シェイク', 'name_eng' => 'Shake']);

        $this->createTopCocktail($vodka, $method, [
            'name' => 'モスコミュール',
            'name_eng' => 'Moscow Mule',
            'rate' => 5,
            'youtube' => 'x9gaP5Q8lc4',
            'youtube_created_at' => '2024-05-06 07:08:09',
            'description' => "銅のマグで飲む、@n{$vodka->id}@のカクテル",
        ]);
        foreach (
            [
                ['スクリュードライバー', 'Screwdriver', 4],
                ['ソルティドッグ', 'Salty Dog', 4],
                ['ブラッディメアリー', 'Bloody Mary', 3],
                ['カミカゼ', 'Kamikaze', 3],
                ['コスモポリタン', 'Cosmopolitan', 5],
                ['バラライカ', 'Balalaika', 3],
            ] as [$name, $nameEng, $rate]
        ) {
            $this->createTopCocktail($vodka, $method, ['name' => $name, 'name_eng' => $nameEng, 'rate' => $rate]);
        }

        $this->createTopCocktail($vodka, $method, [
            'name' => '下書きのカクテル',
            'name_eng' => 'Draft Cocktail',
            'rate' => 5,
            'is_draft' => true,
            'youtube' => 'abcdefghijk',
            'youtube_created_at' => '2030-01-01 00:00:00',
            'updated_at' => '2030-01-01 00:00:00',
        ]);
    }

    /**
     * ヘッダー(#99)の E2E 用のカクテルと銘柄。ランダムウォークがどの種類
     * (カクテル・ノード・銘柄)を選んでも行き先があるようにする。
     * 名前検索「ジン」は、ノードのジン・ドライジン・ロンドンドライジンとカクテルのジントニックの 4 件に当たる(銘柄の名前にはジンを入れない)。
     */
    private function seedHeaderData(Category $gin, Category $beefeater): void
    {
        Cocktail::factory()->create([
            'name' => 'ジントニック',
            'name_search' => 'ジントニック',
            'name_eng' => 'Gin and Tonic',
            'base_category_id' => $gin->id,
            'method_id' => Method::factory()->create(['name' => 'ビルド', 'name_eng' => 'Build'])->id,
        ]);
        Brand::factory()->create([
            'category_id' => $beefeater->id,
            'name' => 'ビーフィーター 40度',
            'name_search' => 'ビーフィーター40度',
            'name_eng' => 'Beefeater 40',
        ]);
    }

    /**
     * トップの E2E 用のカクテルを 1 件作る。ベースはウォッカ、技法はシェイクにそろえ、検索用の名前は名前と同じにする。
     *
     * @param  array<string, mixed>  $attributes  名前(name)は必ず入れる
     */
    private function createTopCocktail(Category $vodka, Method $method, array $attributes): Cocktail
    {
        return Cocktail::factory()->create([
            'name_search' => $attributes['name'],
            'base_category_id' => $vodka->id,
            'method_id' => $method->id,
            ...$attributes,
        ]);
    }

    /**
     * トップの材料の欄(#305)の E2E 用の木。根(蒸留酒)から数えて深さ 5 の「ビーフィーター」まで
     * (蒸留酒 > ジン > ドライジン > ロンドンドライジン > ビーフィーター)。
     * 閉包表は CategoryTree 経由でだけ書き換える決まりなので、ノードは addNode で作る。
     *
     * @return array{gin: Category, beefeater: Category, vodka: Category} ヘッダー(seedHeaderData)・トップ(seedTopData)のデータが使うノード
     */
    private function seedIngredientTree(CategoryTree $tree): array
    {
        $node = fn (?Category $parent, string $name, ?CategoryRole $role = null): Category => $tree->addNode($parent, [
            'name' => $name,
            'name_search' => $name,
            'name_eng' => $name,
            'is_major' => true,
            'role' => $role,
        ]);

        $liquor = $node(null, '蒸留酒');
        $gin = $node($liquor, 'ジン');
        $dryGin = $node($gin, 'ドライジン');
        $londonDryGin = $node($dryGin, 'ロンドンドライジン');
        $beefeater = $node($londonDryGin, 'ビーフィーター');
        $vodka = $node($liquor, 'ウォッカ');

        $node(null, 'ソフトドリンク', CategoryRole::SoftDrink);
        $other = $node(null, 'その他', CategoryRole::Other);
        $node($other, 'ミント');

        return ['gin' => $gin, 'beefeater' => $beefeater, 'vodka' => $vodka];
    }
}
