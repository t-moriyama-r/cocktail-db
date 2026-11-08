<?php

namespace Database\Seeders;

use App\Enums\CategoryRole;
use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailAlias;
use App\Models\CocktailIngredient;
use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use App\Models\Ice;
use App\Models\LegacyIdMap;
use App\Models\Method;
use App\Models\User;
use App\Services\CategoryTree;
use App\Services\RecipeCounter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

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
        $this->seedAdminCocktailEditData($nodes['vodka']);
        $this->seedCategoryImage($nodes['vodka']);
        $this->seedAffiliates($nodes['dryGin'], $nodes['londonDryGin']);
        $this->seedBrandDetailData($nodes['liquor'], $nodes['dryGin']);
        $this->seedAdmin();
        $this->seedLegacyIdMaps($nodes['gin'], $nodes['dryGin'], $nodes['vodka']);
    }

    /**
     * 旧サイトが保存した検索条件の移し替え(#175)の E2E 用の、旧 ID と新しい ID の対応(legacy_id_maps)。
     * 旧のカテゴリ 9001 → ジン(子のミドル 9101 → ドライジン)、9002 → ウォッカ(子のミドルなし)、旧の技法 9201 → ビルド。
     * 旧 ID は、ほかの E2E のデータの ID と重ならないよう大きな値にする
     */
    private function seedLegacyIdMaps(Category $gin, Category $dryGin, Category $vodka): void
    {
        LegacyIdMap::record('m_category', 'categories', [9001 => $gin->id, 9002 => $vodka->id]);
        LegacyIdMap::record('m_middle', 'categories', [9101 => $dryGin->id]);
        LegacyIdMap::record('m_method', 'methods', [9201 => Method::query()->where('name', 'ビルド')->firstOrFail()->id]);
    }

    /**
     * カテゴリの詳細の商品の欄(#106)の E2E 用。画像の無いドライジンと、画像のあるロンドンドライジンにノードの行を置き、
     * 画像の有無で欄の位置が変わることを確かめる(どちらも有名な銘柄が無いので、ノードの行が返る)。
     * 画像のあるノードをウォッカにしないのは、ウォッカは seedTopData のカクテルが銘柄なしの材料に使い、
     * ノードの行を置くとカクテルの詳細の材料ごとの商品の欄(#110)に出てしまうため。ロンドンドライジンを銘柄なしの材料に使うカクテルは無い。
     * 画像のファイルは seedCategoryImage が置いたものを使う。欄を出す条件(手で入れた Amazon の商品のページ)だけを入れ、外の画像・価格は入れない
     */
    private function seedAffiliates(Category $dryGin, Category $londonDryGin): void
    {
        $londonDryGin->update(['image' => 'e2e-vodka.png']);
        foreach ([$dryGin, $londonDryGin] as $node) {
            Affiliate::factory()->create([
                'category_id' => $node->id,
                'human_title' => "{$node->name}の商品",
                'human_amazon_detail_url' => 'https://www.amazon.co.jp/dp/E2E0000000',
            ]);
        }
    }

    /**
     * カテゴリ詳細の画像の E2E(#585)用。image 列にはファイル名だけを入れ、画像のファイルは public ディスクに置く。
     * /storage/<ファイル名> を Laravel が返せるよう、public/storage の symlink も無ければ作る。
     * カクテルの詳細の画像の E2E(#110)も、同じファイルを使う(seedTopData のモスコミュール)。
     */
    private function seedCategoryImage(Category $vodka): void
    {
        // 1x1 の PNG
        Storage::disk('public')->put(
            'e2e-vodka.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
        );
        if (! file_exists(public_path('storage'))) {
            Artisan::call('storage:link');
        }
        $vodka->update(['image' => 'e2e-vodka.png']);
    }

    /**
     * 銘柄の詳細(#54)の E2E 用の銘柄。ヘッダーのビーフィーター 40度(ビーフィーター。深さ 5)に、次の 3 つを足す。
     *
     * - スピリタス: 根の蒸留酒(深さ 1)に所属する。パンくずは 1 段で、親が無いので銘柄の一覧はどちらも出ない
     * - タンカレー・ゴードン: ドライジン(深さ 3)に所属する。パンくずは 3 段。同じノードの一覧に互いが出て、
     *   ジンの配下の一覧にビーフィーター 40度が出る。タンカレーだけ画像を持ち、どちらも商品の行を持つ
     *   (画像の有無で商品の欄の場所が変わることを見る)
     *
     * 名前検索の E2E の件数を変えないよう、名前に「ジン」「ミント」「ビーフィーター」を入れない。
     * 画像のファイルは seedCategoryImage と同じく public ディスクに置く(symlink は seedCategoryImage が作る)。
     */
    private function seedBrandDetailData(Category $liquor, Category $dryGin): void
    {
        $affiliate = fn (Brand $brand) => Affiliate::factory()->create([
            'category_id' => $brand->category_id,
            'brand_id' => $brand->id,
            'human_title' => "{$brand->name} 700ml",
            'human_amazon_detail_url' => 'https://www.amazon.co.jp/dp/B000000099',
        ]);

        $this->createBrand($liquor, 'スピリタス', 'Spirytus', 96);

        // 1x1 の PNG
        Storage::disk('public')->put(
            'e2e-tanqueray.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
        );
        $affiliate($this->createBrand($dryGin, 'タンカレー', 'Tanqueray', 47.3, [
            'description' => 'ロンドンの辛口。',
            'image' => 'e2e-tanqueray.png',
            'image_txt' => '画像の出典',
        ]));
        $affiliate($this->createBrand($dryGin, 'ゴードン', "Gordon's", 37.5));
    }

    /**
     * 銘柄の詳細の E2E 用の銘柄を 1 件作る。検索用の名前は名前と同じにする。
     *
     * @param  array<string, mixed>  $attributes  ほかに入れる列
     */
    private function createBrand(Category $node, string $name, string $nameEng, float $volume, array $attributes = []): Brand
    {
        return Brand::factory()->create([
            'category_id' => $node->id,
            'name' => $name,
            'name_search' => $name,
            'name_eng' => $nameEng,
            'volume' => $volume,
            ...$attributes,
        ]);
    }

    /**
     * 管理画面の枠(#119)の E2E でログインする管理者。E2E は php artisan admin:login-link で非常用のログイン URL を出してログインする
     * (ユーザーが 1 人なので email を渡さなくてよいが、取り違えないよう E2E は email を渡す)。
     */
    private function seedAdmin(): void
    {
        User::factory()->create(['name' => 'E2E の管理者', 'email' => 'e2e-admin@example.com']);
    }

    /**
     * トップ(#50)の E2E 用のカクテル。3 つの一覧がどれも 7 件になり、新着動画が出るようにする。
     *
     * - 公開で評価 3 以上を 7 件(おすすめの一覧はちょうどこの 7 件になる)。どれも名前に「ジン」を入れない(ヘッダーの名前検索の件数を変えない)
     * - そのうち「モスコミュール」だけに動画と説明文を付け、新着動画にする
     * - 下書きを 1 件。更新・動画の公開がいちばん新しく、評価も 5 にして、下書きを返してしまうとどの一覧・新着動画にも出るようにする
     *
     * 公開のカクテルは、ヘッダーのジントニックと合わせて 8 件になる(ヘッダーの登録数は 8)。
     *
     * どれも材料にウォッカを 1 つ持たせ、条件検索の結果のページ(#103)の E2E で、ウォッカの検索が公開の 7 件に当たるようにする。
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
            // カクテルの詳細の画像の E2E(#110)用。ファイルは seedCategoryImage が置く
            'image' => 'e2e-vodka.png',
            'image_txt' => 'E2E の出典',
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
     * 管理画面のカクテルの編集(#127・#128)の E2E 用。既にあるカクテルを開いてそのまま保存するので、保存の API の
     * 材料の件数(2 件以上。#292)と、編集画面の送る前の確かめ(同じノード・同じ銘柄の行が 2 つあると止める。#128)を
     * 通るよう、保存を試すカクテルには、ウォッカの行と、ウォッカの銘柄「スミノフ」の行の 2 件を持たせる。
     *
     * - 「公開する」を試す: seedTopData の公開のカクテル「カミカゼ」に、2 件目の材料(スミノフ)と、公開に要る説明を足す。
     *   公開のカクテルを足すと登録数(ヘッダーの 8)が変わるので、足さずに既にあるものを使う。ほかの E2E はカミカゼの材料・説明を見ていない
     * - 「下書きとして保存」を試す: 材料 2 件(ウォッカとスミノフ)の下書き「E2E の下書き」を足す。下書きなので登録数・公開の一覧・検索には入らない。
     *   カクテルの一覧の E2E(#126)が「下書きのカクテル」で始まる名前と英名の「Cocktail」で絞るので、どちらにも当たらない名前にする。
     *   材料の並べ替えの E2E(#128)も、この下書きの 2 行を入れ替えて保存する(並びはどちらでもよい)
     *
     * 2 件目をウォッカの銘柄にするのは、ノードの数(カテゴリ木の E2E)も、ウォッカの材料で当たるカクテルの数(条件検索の 7 件)も
     * 変えないため。銘柄の名前には、名前検索の E2E の語(ジン・ミント・ビーフィーター)を入れない
     */
    private function seedAdminCocktailEditData(Category $vodka): void
    {
        $smirnoff = $this->createBrand($vodka, 'スミノフ', 'Smirnoff', 40);
        $secondVodka = fn (Cocktail $cocktail) => CocktailIngredient::factory()->forBrand($smirnoff)->create([
            'cocktail_id' => $cocktail->id,
            'position' => 2,
            'amount' => '15ml',
        ]);

        $kamikaze = Cocktail::query()->where('name', 'カミカゼ')->firstOrFail();
        $kamikaze->update(['description' => 'ライムを搾った、ウォッカのショートカクテル。']);
        $secondVodka($kamikaze);

        $draft = $this->createTopCocktail($vodka, Method::query()->findOrFail($kamikaze->method_id), [
            'name' => 'E2E の下書き',
            'name_eng' => 'Save Test',
            'is_draft' => true,
        ]);
        $secondVodka($draft);

        app(RecipeCounter::class)->recount([$kamikaze->id, $draft->id]);
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
     *
     * 詳細の商品の欄(#110)のために、銘柄にだけ商品の行を作る(ミントには作らないので、ミントの商品の欄は出ない)。
     * 商品の値は空で、E2E では Amazon の設定が無いので、API は名前から組み立てた Amazon の検索結果のリンクだけを返す
     * (ボタン「Amazonで探す」だけが出る)。画像は持たせない(OGP の E2E(#112)が既定の画像を見ている)。
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
        Affiliate::factory()->create(['category_id' => $beefeater->id, 'brand_id' => $beefeater40->id]);
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

        // 詳細ページ(#111)の 404 の E2E 用の下書きのカクテル(ID 2)。公開ではないので登録数・名前検索には入らない
        Cocktail::factory()->create([
            'name' => '下書きのカクテル',
            'name_search' => '下書きのカクテル',
            'name_eng' => 'Draft',
            'base_category_id' => $gin->id,
            'method_id' => $cocktail->method_id,
            'is_draft' => true,
        ]);
    }

    /**
     * トップの E2E 用のカクテルを 1 件作る。ベースはウォッカ、技法はシェイクにそろえ、検索用の名前は名前と同じにする。
     * 材料はウォッカ 1 つ。
     *
     * @param  array<string, mixed>  $attributes  名前(name)は必ず入れる
     */
    private function createTopCocktail(Category $vodka, Method $method, array $attributes): Cocktail
    {
        $cocktail = Cocktail::factory()->create([
            'name_search' => $attributes['name'],
            'base_category_id' => $vodka->id,
            'method_id' => $method->id,
            ...$attributes,
        ]);
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id,
            'category_id' => $vodka->id,
            'position' => 1,
            'amount' => '45ml',
        ]);
        app(RecipeCounter::class)->recount([$cocktail->id]);

        return $cocktail;
    }

    /**
     * トップの材料の欄(#305)の E2E 用の木。根(蒸留酒)から数えて深さ 5 の「ビーフィーター」まで
     * (蒸留酒 > ジン > ドライジン > ロンドンドライジン > ビーフィーター)。
     * 閉包表は CategoryTree 経由でだけ書き換える決まりなので、ノードは addNode で作る。
     *
     * @return array{liquor: Category, gin: Category, dryGin: Category, londonDryGin: Category, beefeater: Category, mint: Category, vodka: Category} ヘッダー(seedHeaderData)・トップ(seedTopData)・銘柄の詳細(seedBrandDetailData)・旧 ID の対応(seedLegacyIdMaps)・商品の欄(seedAffiliates)のデータが使うノード
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

        return ['liquor' => $liquor, 'gin' => $gin, 'dryGin' => $dryGin, 'londonDryGin' => $londonDryGin, 'beefeater' => $beefeater, 'mint' => $mint, 'vodka' => $vodka];
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
