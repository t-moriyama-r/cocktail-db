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
        $gin = $this->seedIngredientTree(app(CategoryTree::class));
        $this->seedDetailPages($gin);
    }

    /**
     * トップの材料の欄(#305)の E2E 用の木。根(蒸留酒)から数えて深さ 5 の「ビーフィーター」まで
     * (蒸留酒 > ジン > ドライジン > ロンドンドライジン > ビーフィーター)。
     * 閉包表は CategoryTree 経由でだけ書き換える決まりなので、ノードは addNode で作る。
     */
    private function seedIngredientTree(CategoryTree $tree): Category
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
        $node($londonDryGin, 'ビーフィーター');
        $node($liquor, 'ウォッカ');

        $node(null, 'ソフトドリンク', CategoryRole::SoftDrink);
        $other = $node(null, 'その他', CategoryRole::Other);
        $node($other, 'ミント');

        return $gin;
    }

    /**
     * 詳細ページ(#111)の E2E 用の 1 件ずつ。サーバーは無い ID・下書きの詳細を 404 で返すので、
     * 詳細の画面を開く E2E(/detail/cocktail/1・/detail/brand/1・/dictionary/1)が開ける行を置く。
     * カテゴリの詳細(/category/1)は、上の木の根(蒸留酒)を開く。下書きのカクテル(ID 2)は、404 の画面の E2E が開く。
     */
    private function seedDetailPages(Category $gin): void
    {
        $build = Method::query()->create(['name' => 'ビルド', 'name_eng' => 'Build', 'description' => 'グラスに直接注いで作る。']);
        Cocktail::query()->create([
            'name' => 'ジン・トニック',
            'name_search' => 'ジン・トニック',
            'name_eng' => 'Gin and Tonic',
            'base_category_id' => $gin->id,
            'method_id' => $build->id,
            'volume' => 10,
            'description' => 'ジンをトニックウォーターで割った、すっきりした一杯。',
        ]);
        Cocktail::query()->create([
            'name' => '下書きのカクテル',
            'name_search' => '下書きのカクテル',
            'name_eng' => 'Draft',
            'base_category_id' => $gin->id,
            'method_id' => $build->id,
            'volume' => 10,
            'is_draft' => true,
        ]);
        Brand::query()->create([
            'category_id' => $gin->id,
            'name' => 'ビーフィーター・ジン',
            'name_search' => 'ビーフィーター・ジン',
            'name_eng' => 'Beefeater Gin',
            'volume' => 40,
        ]);
        $technique = DictionaryCategory::query()->create(['name' => '技法', 'name_eng' => 'Technique']);
        Dictionary::query()->create([
            'dictionary_category_id' => $technique->id,
            'name' => 'ビルド',
            'name_eng' => 'Build',
            'name_search' => 'ビルド',
            'description' => 'グラスに直接注いで作る技法。',
        ]);
    }
}
