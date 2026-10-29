<?php

namespace Database\Seeders;

use App\Enums\CategoryRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Models\DictionaryCategory;
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
        $terms = $this->seedDictionary();
        $this->seedHeaderData($nodes['gin'], $nodes['beefeater'], $terms['build'], $terms['cocktailGlass']);
    }

    /**
     * ヘッダー(#99)の E2E 用のカクテルと銘柄。登録数(公開のカクテルの数)を 1 にし、ランダムウォークがどの種類
     * (カクテル・ノード・銘柄)を選んでも行き先があるようにする。
     * 名前検索「ジン」は、ノードのジン・ドライジン・ロンドンドライジンとカクテルのジントニックの 4 件に当たる(銘柄の名前にはジンを入れない)。
     * ジントニックの技法(ビルド)とグラスは用語辞典の用語に結び付け、用語の詳細(#56)の「これを使ったカクテル」に出す。
     */
    private function seedHeaderData(Category $gin, Category $beefeater, Dictionary $build, Dictionary $cocktailGlass): void
    {
        Cocktail::factory()->create([
            'name' => 'ジントニック',
            'name_search' => 'ジントニック',
            'name_eng' => 'Gin and Tonic',
            'base_category_id' => $gin->id,
            'method_id' => Method::factory()->create(['name' => 'ビルド', 'name_eng' => 'Build', 'dictionary_id' => $build->id])->id,
            'glass_dictionary_id' => $cocktailGlass->id,
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

    /**
     * 用語辞典(#56)の E2E 用の分類と用語。分類は旧と同じ ID(製法 1・グラス 2・スタイル 5・単位 6)で作る。
     * スタイルは用語が 0 件(一覧に出ない)。単位の用語は、作る順(ID の順)と五十音の順をずらし、ひらがなとカタカナを混ぜる。
     * ビルドの説明文は、カクテル・グラスへの参照を持つ(用語から用語へ移る E2E に使う)。
     *
     * @return array{build: Dictionary, cocktailGlass: Dictionary} 技法とグラスに結び付ける用語
     */
    private function seedDictionary(): array
    {
        $category = fn (int $id, string $name, string $nameEng): DictionaryCategory => DictionaryCategory::factory()
            ->create(['id' => $id, 'name' => $name, 'name_eng' => $nameEng]);
        $term = fn (DictionaryCategory $category, string $name, string $nameEng, string $description): Dictionary => Dictionary::factory()
            ->create([
                'dictionary_category_id' => $category->id, 'name' => $name, 'name_eng' => $nameEng, 'name_search' => $name,
                'description' => $description,
            ]);

        $method = $category(1, '製法', 'Method');
        $glass = $category(DictionaryCategory::GLASS_ID, 'グラス', 'Glass');
        $category(5, 'スタイル', 'Style');
        $unit = $category(6, '単位', 'Unit');

        $cocktailGlass = $term($glass, 'カクテル・グラス', 'Cocktail glass', '逆三角形の脚つきのグラス。');
        $build = $term($method, 'ビルド', 'Build', 'グラスに直接注いで作る。@d'.$cocktailGlass->id.'@には使わない。');
        $term($method, 'シェイク', 'Shake', 'シェイカーで振って混ぜる。');
        $term($unit, 'ひとつまみ', 'Pinch', '指でつまんだ量。');
        $term($unit, 'ティースプーン', 'Teaspoon', '約 5ml。');
        $term($unit, 'ダッシュ', 'Dash', '約 1ml。');

        return ['build' => $build, 'cocktailGlass' => $cocktailGlass];
    }
}
