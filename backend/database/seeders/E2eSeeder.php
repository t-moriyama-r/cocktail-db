<?php

namespace Database\Seeders;

use App\Enums\CategoryRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use App\Models\Ice;
use App\Models\Method;
use App\Services\CategoryTree;
use App\Services\RecipeCounter;
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
        $this->seedHeaderData($nodes['gin'], $nodes['beefeater'], $nodes['mint'], $terms['build'], $terms['tumbler']);
    }

    /**
     * ヘッダー(#99)の E2E 用のカクテルと銘柄。登録数(公開のカクテルの数)を 1 にし、ランダムウォークがどの種類
     * (カクテル・ノード・銘柄)を選んでも行き先があるようにする。
     * 名前検索「ジン」は、ノードのジン・ドライジン・ロンドンドライジンとカクテルのジントニックの 4 件と、
     * 材料にジンの配下の銘柄を使うジントニック(ingredient_matches)の 1 件の、合わせて 5 件に当たる(銘柄の名前にはジンを入れない)。
     *
     * ジントニックは、カクテルの詳細(#108)の E2E にも使う。登録数を変えないよう、カクテルは足さずにこの 1 件に
     * グラス・氷・甘さ・評価・補足と、銘柄つきの材料・任意の材料を持たせる。
     * 技法(ビルド)とグラス(タンブラー)は用語辞典の用語に結び付け、用語の詳細(#56)の「これを使ったカクテル」に出す。
     */
    private function seedHeaderData(Category $gin, Category $beefeater, Category $mint, Dictionary $build, Dictionary $tumbler): void
    {
        $beefeater40 = Brand::factory()->create([
            'category_id' => $beefeater->id,
            'name' => 'ビーフィーター 40度',
            'name_search' => 'ビーフィーター40度',
            'name_eng' => 'Beefeater 40',
        ]);
        $cocktail = Cocktail::factory()->create([
            'name' => 'ジントニック',
            'name_search' => 'ジントニック',
            'name_eng' => 'Gin and Tonic',
            'base_category_id' => $gin->id,
            'method_id' => Method::factory()->create(['name' => 'ビルド', 'name_eng' => 'Build', 'dictionary_id' => $build->id])->id,
            'glass_dictionary_id' => $tumbler->id,
            'ice_id' => Ice::query()->create(['name' => 'キューブアイス', 'name_eng' => 'Ice Cube'])->id,
            'volume' => 12.5,
            'sweet_degree' => 0,
            'rate' => 3,
            'description' => '定番のロングカクテル。',
            'note' => 'ライムを添える。',
        ]);
        CocktailIngredient::factory()->forBrand($beefeater40)->create([
            'cocktail_id' => $cocktail->id,
            'position' => 1,
            'amount' => '45ml',
        ]);
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id,
            'category_id' => $mint->id,
            'position' => 2,
            'amount' => '適量',
            'is_optional' => true,
        ]);
        app(RecipeCounter::class)->recount([$cocktail->id]);
    }

    /**
     * トップの材料の欄(#305)の E2E 用の木。根(蒸留酒)から数えて深さ 5 の「ビーフィーター」まで
     * (蒸留酒 > ジン > ドライジン > ロンドンドライジン > ビーフィーター)。
     * 閉包表は CategoryTree 経由でだけ書き換える決まりなので、ノードは addNode で作る。
     *
     * @return array{gin: Category, beefeater: Category, mint: Category} ヘッダーのデータ(seedHeaderData)が使うノード
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
        $mint = $node($other, 'ミント');

        return ['gin' => $gin, 'beefeater' => $beefeater, 'mint' => $mint];
    }

    /**
     * 用語辞典(#56)の E2E 用の分類と用語。分類は旧と同じ ID(製法 1・グラス 2・スタイル 5・単位 6)で作る。
     * スタイルは用語が 0 件(一覧に出ない)。単位の用語は、作る順(ID の順)と五十音の順をずらし、ひらがなとカタカナを混ぜる。
     * ビルドの説明文は、タンブラーへの参照を持つ(用語から用語へ移る E2E に使う)。
     * タンブラーは、カクテルの詳細(#108)の E2E がグラスのリンク先を /dictionary/1 と見るので、最初に作る。
     *
     * @return array{build: Dictionary, tumbler: Dictionary} 技法とグラスに結び付ける用語
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

        $tumbler = $term($glass, 'タンブラー', 'Tumbler', '脚の無い、筒の形のグラス。');
        $build = $term($method, 'ビルド', 'Build', 'グラスに直接注いで作る。@d'.$tumbler->id.'@で作ることが多い。');
        $term($method, 'シェイク', 'Shake', 'シェイカーで振って混ぜる。');
        $term($unit, 'ひとつまみ', 'Pinch', '指でつまんだ量。');
        $term($unit, 'ティースプーン', 'Teaspoon', '約 5ml。');
        $term($unit, 'ダッシュ', 'Dash', '約 1ml。');

        return ['build' => $build, 'tumbler' => $tumbler];
    }
}
