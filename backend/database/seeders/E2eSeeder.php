<?php

namespace Database\Seeders;

use App\Enums\CategoryRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailAlias;
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
     * 名前検索「ジン」は、ノードのジン・ドライジン・ロンドンドライジンとカクテルのジントニックの 4 件と、
     * 材料にジンの配下の銘柄を使うジントニック(ingredient_matches)の 1 件の、合わせて 5 件に当たる(銘柄の名前にはジンを入れない)。
     *
     * ジントニックは、カクテルの詳細(#108)の E2E にも使う。登録数を変えないよう、カクテルは足さずにこの 1 件に
     * グラス・氷・甘さ・評価・補足と、銘柄つきの材料・任意の材料を持たせる。
     * 技法(ビルド)とグラス(タンブラー)は用語辞典の用語に結び付け、用語の詳細(#56)の「これを使ったカクテル」に出す。
     * 詳細の別名の欄(#109)のために、別名「G&T」を持たせる(名前検索「ジン」には当たらない名前にし、件数を変えない)。
     *
     * 銘柄はジンの有名な銘柄にもする(カテゴリの詳細の E2E(#105)で、有名な銘柄の欄が出るノードと出ないノードを作る)。
     */
    private function seedHeaderData(Category $gin, Category $beefeater, Category $mint, Dictionary $build, Dictionary $tumbler): void
    {
        $beefeater40 = Brand::factory()->create([
            'category_id' => $beefeater->id,
            'name' => 'ビーフィーター 40度',
            'name_search' => 'ビーフィーター40度',
            'name_eng' => 'Beefeater 40',
        ]);
        $gin->update(['famous_brand_id' => $beefeater40->id]);
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
        CocktailAlias::factory()->named('G&T')->create(['cocktail_id' => $cocktail->id]);
        app(RecipeCounter::class)->recount([$cocktail->id]);
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
     * @return array{gin: Category, beefeater: Category, mint: Category, vodka: Category} ヘッダー(seedHeaderData)・トップ(seedTopData)のデータが使うノード
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
        $mint = $node($other, 'ミント');

        return ['gin' => $gin, 'beefeater' => $beefeater, 'mint' => $mint, 'vodka' => $vodka];
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
