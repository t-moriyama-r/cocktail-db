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
    }

    /**
     * ヘッダー(#99)の E2E 用のカクテルと銘柄。登録数(公開のカクテルの数)を 1 にし、ランダムウォークがどの種類
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
     * トップの材料の欄(#305)の E2E 用の木。根(蒸留酒)から数えて深さ 5 の「ビーフィーター」まで
     * (蒸留酒 > ジン > ドライジン > ロンドンドライジン > ビーフィーター)。
     * 閉包表は CategoryTree 経由でだけ書き換える決まりなので、ノードは addNode で作る。
     *
     * @return array{gin: Category, beefeater: Category} ヘッダーのデータ(seedHeaderData)が使うノード
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
        $node($liquor, 'ウォッカ');

        $node(null, 'ソフトドリンク', CategoryRole::SoftDrink);
        $other = $node(null, 'その他', CategoryRole::Other);
        $node($other, 'ミント');

        return ['gin' => $gin, 'beefeater' => $beefeater];
    }
}
