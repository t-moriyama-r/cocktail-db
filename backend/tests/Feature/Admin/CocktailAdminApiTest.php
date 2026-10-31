<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailAlias;
use App\Models\CocktailIngredient;
use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use App\Models\Ice;
use App\Models\Method;
use App\Models\User;
use App\Services\CategoryTree;
use App\Services\RecipeCounter;
use App\Services\RecipeSignature;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

// 管理 API のカクテルの基本情報の取得・新規・更新と、フォームの選択肢(#291)、材料の保存(#292)。旧の
// DetailEditController@cocktail・EditCocktailValidate・FactoryHelper::generateFromRequest・RecipeFactory::saveRecipe と、
// SearchController@getListForRecipe の置き換え。
// 別名の取得・保存(#293。旧には別名が無い)も、ここで確かめる。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/** 1x1 の PNG(画像の種類は中身から推定させるので、本物の画像のバイト列にする) */
const COCKTAIL_ADMIN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/**
 * テストに使う木と選択肢。
 *
 * 洋酒(根)
 * ├ ラム
 * │  └ ホワイトラム
 * └ ジン(有名な銘柄: ジン A 40%)── 銘柄: ジン A 40%・ジン B 47%
 * ソフトドリンク(根。soft_drink)
 * └ ソーダ
 * その他(根。other)
 * └ ライム
 *
 * 技法: ビルド・シェイク / グラス(分類「グラス」): タンブラー・カクテルグラス / グラスでない用語: シェーカー / 氷: クラッシュド・キューブド
 *
 * @return array{nodes: array<string, Category>, brands: array<string, Brand>, methods: list<Method>, glasses: list<Dictionary>, notGlass: Dictionary, ices: list<Ice>}
 */
function cocktailAdminFixture(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, ?string $role = null): Category => $tree->addNode(
        $parent,
        Category::factory()->make([
            'name' => $name,
            'name_search' => NameNormalizer::normalize($name),
            'name_eng' => $name.'-eng',
            'role' => $role,
        ])->getAttributes(),
    );

    $n = [];
    $n['洋酒'] = $add(null, '洋酒');
    $n['ラム'] = $add($n['洋酒'], 'ラム');
    $n['ホワイトラム'] = $add($n['ラム'], 'ホワイトラム');
    $n['ジン'] = $add($n['洋酒'], 'ジン');
    $n['ソフトドリンク'] = $add(null, 'ソフトドリンク', 'soft_drink');
    $n['ソーダ'] = $add($n['ソフトドリンク'], 'ソーダ');
    $n['その他'] = $add(null, 'その他', 'other');
    $n['ライム'] = $add($n['その他'], 'ライム');

    $b = [];
    $b['ジン A'] = Brand::factory()->create(['category_id' => $n['ジン']->id, 'name' => 'ジン A', 'volume' => 40.0]);
    $b['ジン B'] = Brand::factory()->create(['category_id' => $n['ジン']->id, 'name' => 'ジン B', 'volume' => 47.0]);
    $n['ジン']->update(['famous_brand_id' => $b['ジン A']->id]);

    $glassCategory = DictionaryCategory::factory()->create(['id' => DictionaryCategory::GLASS_ID, 'name' => 'グラス', 'name_eng' => 'Glass']);
    $toolCategory = DictionaryCategory::factory()->create(['id' => DictionaryCategory::GLASS_ID + 1, 'name' => '道具', 'name_eng' => 'Tool']);

    return [
        'nodes' => $n,
        'brands' => $b,
        'methods' => [
            Method::factory()->create(['name' => 'ビルド']),
            Method::factory()->create(['name' => 'シェイク']),
        ],
        'glasses' => [
            Dictionary::factory()->create(['dictionary_category_id' => $glassCategory->id, 'name' => 'タンブラー']),
            Dictionary::factory()->create(['dictionary_category_id' => $glassCategory->id, 'name' => 'カクテルグラス']),
        ],
        'notGlass' => Dictionary::factory()->create(['dictionary_category_id' => $toolCategory->id, 'name' => 'シェーカー']),
        'ices' => [
            Ice::query()->create(['name' => 'クラッシュド', 'name_eng' => 'Crushed']),
            Ice::query()->create(['name' => 'キューブド', 'name_eng' => 'Cubed']),
        ],
    ];
}

/**
 * 材料の 1 行の入力。銘柄を渡したら銘柄の所属ノードにする。
 *
 * @return array{category_id: int, brand_id: int|null, amount: string, is_optional: bool}
 */
function cocktailAdminIngredient(Category|Brand $item, string $amount, bool $isOptional = false): array
{
    return [
        'category_id' => $item instanceof Brand ? $item->category_id : $item->id,
        'brand_id' => $item instanceof Brand ? $item->id : null,
        'amount' => $amount,
        'is_optional' => $isOptional,
    ];
}

/**
 * 正しい入力。$overrides で項目を差し替える(null を入れた項目は送らない)。
 *
 * 材料は、ジン 45(有名な銘柄 40%)・ソーダ 15 の 2 行(度数 30.0・材料の数 2)。
 *
 * @param  array{nodes: array<string, Category>, methods: list<Method>}  $f
 * @param  array<array-key, mixed>  $overrides  項目の名前 → 値(データセットから受けるので、鍵の型は絞らない)
 * @return array<array-key, mixed>
 */
function cocktailAdminInput(array $f, array $overrides = []): array
{
    $input = array_merge([
        'base_category_id' => $f['nodes']['ホワイトラム']->id,
        'name' => 'ダイキリ',
        'name_eng' => 'Daiquiri',
        'method_id' => $f['methods'][1]->id,
        'description' => '爽やかな味',
        'is_major' => true,
        'is_draft' => false,
        'ingredients' => [
            cocktailAdminIngredient($f['nodes']['ジン'], '45'),
            cocktailAdminIngredient($f['nodes']['ソーダ'], '15'),
        ],
    ], $overrides);

    return array_filter($input, fn (mixed $value): bool => $value !== null);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('TC-291-01: ログインしていないと、取得・新規・更新・選択肢は 401', function (string $method, string $uri) {
    auth()->logout();
    $cocktail = Cocktail::factory()->create();

    $this->json($method, str_replace('{id}', (string) $cocktail->id, $uri))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
})->with([
    '取得' => ['GET', '/api/admin/cocktails/{id}'],
    '新規' => ['POST', '/api/admin/cocktails'],
    '更新' => ['PUT', '/api/admin/cocktails/{id}'],
    '選択肢' => ['GET', '/api/admin/cocktail-form-options'],
]);

it('TC-291-02: 取得は、説明・補足に指す先の名前を添え、開始位置を時・分・秒に分けて返す。下書きも返し、無い ID は 404', function () {
    $f = cocktailAdminFixture();
    $n = $f['nodes'];
    $cocktail = Cocktail::factory()->create([
        'base_category_id' => $n['ジン']->id,
        'name' => 'ギムレット',
        'name_eng' => 'Gimlet',
        'method_id' => $f['methods'][1]->id,
        'glass_dictionary_id' => $f['glasses'][1]->id,
        'ice_id' => $f['ices'][0]->id,
        'volume' => '25.3',
        'rate' => 4,
        'sweet_degree' => -3,
        'is_major' => true,
        'is_draft' => true,
        'description' => "@n{$n['ジン']->id}@を使う",
        'note' => "@n{$n['ラム']->id}@でも作る",
        'youtube' => 'abcdefghijk',
        'youtube_start_seconds' => 3723,
        'image' => 'x.png',
        'image_txt' => '写真',
        'image_url' => 'https://example.com/',
    ]);

    $this->getJson("/api/admin/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $cocktail->id)
        ->assertJsonPath('data.base_category_id', $n['ジン']->id)
        ->assertJsonPath('data.name', 'ギムレット')
        ->assertJsonPath('data.name_eng', 'Gimlet')
        ->assertJsonPath('data.method_id', $f['methods'][1]->id)
        ->assertJsonPath('data.glass_dictionary_id', $f['glasses'][1]->id)
        ->assertJsonPath('data.ice_id', $f['ices'][0]->id)
        ->assertJsonPath('data.strength', 25.3)
        ->assertJsonPath('data.rate', 4)
        ->assertJsonPath('data.sweet_degree', -3)
        ->assertJsonPath('data.is_major', true)
        ->assertJsonPath('data.is_draft', true)
        ->assertJsonPath('data.description', "@n{$n['ジン']->id}@{!ジン!}を使う")
        ->assertJsonPath('data.note', "@n{$n['ラム']->id}@{!ラム!}でも作る")
        ->assertJsonPath('data.youtube', 'abcdefghijk')
        ->assertJsonPath('data.youtube_start_hour', 1)
        ->assertJsonPath('data.youtube_start_minute', 2)
        ->assertJsonPath('data.youtube_start_second', 3)
        ->assertJsonPath('data.image', 'x.png')
        ->assertJsonPath('data.image_txt', '写真')
        ->assertJsonPath('data.image_url', 'https://example.com/');

    $this->getJson('/api/admin/cocktails/'.($cocktail->id + 1000))->assertNotFound();
});

it('TC-291-03: 新規は 201 で保存し、名前の検索用の形と、説明・補足の記法への変換を入れる。度数・材料の数は材料から作る', function () {
    $f = cocktailAdminFixture();
    $n = $f['nodes'];

    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, [
        'description' => 'ラムの一杯',
        'note' => 'ジンでも作る',
        'glass_dictionary_id' => $f['glasses'][0]->id,
        'ice_id' => $f['ices'][1]->id,
        'rate' => 3,
        'sweet_degree' => 2,
        'image_txt' => '写真',
        'image_url' => 'https://example.com/a',
    ]))->assertCreated()
        ->assertJsonPath('data.name', 'ダイキリ')
        ->assertJsonPath('data.description', "@n{$n['ラム']->id}@{!ラム!}の一杯")
        ->assertJsonPath('data.strength', 30);

    $cocktail = Cocktail::query()->whereKey($response->json('data.id'))->sole();
    expect($cocktail->only([
        'base_category_id', 'name', 'name_search', 'name_eng', 'method_id', 'glass_dictionary_id', 'ice_id',
        'rate', 'sweet_degree', 'is_major', 'is_draft', 'description', 'note', 'recipe_count', 'recipe_signature',
        'youtube', 'youtube_created_at', 'youtube_start_seconds', 'image', 'image_txt', 'image_url',
    ]))->toBe([
        'base_category_id' => $n['ホワイトラム']->id,
        'name' => 'ダイキリ',
        'name_search' => NameNormalizer::normalize('ダイキリ'),
        'name_eng' => 'Daiquiri',
        'method_id' => $f['methods'][1]->id,
        'glass_dictionary_id' => $f['glasses'][0]->id,
        'ice_id' => $f['ices'][1]->id,
        'rate' => 3,
        'sweet_degree' => 2,
        'is_major' => true,
        'is_draft' => false,
        'description' => "@n{$n['ラム']->id}@の一杯",
        'note' => "@n{$n['ジン']->id}@でも作る",
        'recipe_count' => 2,
        'recipe_signature' => RecipeSignature::of([
            ['category_id' => $n['ジン']->id, 'brand_id' => null, 'amount' => '45ml', 'is_optional' => false],
            ['category_id' => $n['ソーダ']->id, 'brand_id' => null, 'amount' => '15ml', 'is_optional' => false],
        ]),
        'youtube' => null,
        'youtube_created_at' => null,
        'youtube_start_seconds' => null,
        'image' => null,
        'image_txt' => '写真',
        'image_url' => 'https://example.com/a',
    ])->and($cocktail->strength())->toBe(30.0);
});

it('TC-291-04: 必須の項目(ベース・名前・英名・技法・メジャー・下書き)が無いと 422', function (string $field) {
    $f = cocktailAdminFixture();

    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, [$field => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, [$field => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
    expect(Cocktail::query()->count())->toBe(0);
})->with(['base_category_id', 'name', 'name_eng', 'method_id', 'is_major', 'is_draft']);

it('TC-291-05: 甘さは -10〜10 の整数', function (mixed $value, bool $ok) {
    $f = cocktailAdminFixture();

    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['sweet_degree' => $value]));

    $ok ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors('sweet_degree');
})->with([
    '-10' => [-10, true],
    '10' => [10, true],
    '-11' => [-11, false],
    '11' => [11, false],
    '小数' => [1.5, false],
]);

it('TC-291-06: 評価は 1〜5 の整数', function (mixed $value, bool $ok) {
    $f = cocktailAdminFixture();

    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['rate' => $value]));

    $ok ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors('rate');
})->with([
    '1' => [1, true],
    '5' => [5, true],
    '0' => [0, false],
    '6' => [6, false],
]);

it('TC-291-07: 名前・英名・画像のリンクの文字は 255 文字まで', function (string $field) {
    $f = cocktailAdminFixture();

    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, [$field => str_repeat('あ', 255)]))
        ->assertCreated();
    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, [$field => str_repeat('あ', 256)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with(['name', 'name_eng', 'image_txt']);

it('TC-291-08: ベースは実在する根でないノード', function () {
    $f = cocktailAdminFixture();

    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['base_category_id' => $f['nodes']['洋酒']->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('base_category_id');
    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['base_category_id' => $f['nodes']['ジン']->id + 1000]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('base_category_id');
    // 2 段目(旧のカテゴリ)より深いノードも選べる
    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['base_category_id' => $f['nodes']['ホワイトラム']->id]))
        ->assertCreated();
});

it('TC-291-09: 技法は実在するもの、グラスは分類「グラス」の用語、氷は実在するもの(グラス・氷は空でもよい)', function (string $field, string $pick, bool $ok) {
    $f = cocktailAdminFixture();
    $value = match ($pick) {
        'existing' => match ($field) {
            'method_id' => $f['methods'][0]->id,
            'glass_dictionary_id' => $f['glasses'][1]->id,
            default => $f['ices'][0]->id,
        },
        'missing' => 999999,
        'not_glass' => $f['notGlass']->id,
        default => null,
    };

    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, [$field => $value]));

    $ok ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    '技法: ある' => ['method_id', 'existing', true],
    '技法: 無い' => ['method_id', 'missing', false],
    'グラス: ある' => ['glass_dictionary_id', 'existing', true],
    'グラス: 無い' => ['glass_dictionary_id', 'missing', false],
    'グラス: グラスでない用語' => ['glass_dictionary_id', 'not_glass', false],
    'グラス: 空' => ['glass_dictionary_id', 'empty', true],
    '氷: ある' => ['ice_id', 'existing', true],
    '氷: 無い' => ['ice_id', 'missing', false],
    '氷: 空' => ['ice_id', 'empty', true],
]);

it('TC-291-10: YouTube は URL か動画 ID で受け、開始位置は時 0 から、分・秒は 0〜59', function (array $input, ?string $error, ?string $videoId, ?int $seconds) {
    $f = cocktailAdminFixture();

    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, $input));

    if ($error !== null) {
        $response->assertUnprocessable()->assertJsonValidationErrors($error);

        return;
    }
    $response->assertCreated();
    $cocktail = Cocktail::query()->whereKey($response->json('data.id'))->sole();
    expect($cocktail->youtube)->toBe($videoId)->and($cocktail->youtube_start_seconds)->toBe($seconds);
})->with([
    'URL から動画 ID を取る' => [['youtube' => 'https://www.youtube.com/watch?v=abcdefghijk&t=1'], null, 'abcdefghijk', null],
    '短い URL' => [['youtube' => 'https://youtu.be/abcdefghijk'], null, 'abcdefghijk', null],
    '動画 ID' => [['youtube' => 'abcdefghijk'], null, 'abcdefghijk', null],
    'YouTube でない URL' => [['youtube' => 'https://example.com/watch?v=abcdefghijk'], 'youtube', null, null],
    '動画 ID の読めない URL' => [['youtube' => 'https://www.youtube.com/'], 'youtube', null, null],
    '時 0・分 59・秒 59' => [['youtube' => 'abcdefghijk', 'youtube_start_hour' => 0, 'youtube_start_minute' => 59, 'youtube_start_second' => 59], null, 'abcdefghijk', 3599],
    '秒 60' => [['youtube' => 'abcdefghijk', 'youtube_start_second' => 60], 'youtube_start_second', null, null],
    '分 60' => [['youtube' => 'abcdefghijk', 'youtube_start_minute' => 60], 'youtube_start_minute', null, null],
    '時 -1' => [['youtube' => 'abcdefghijk', 'youtube_start_hour' => -1], 'youtube_start_hour', null, null],
    '動画が無ければ開始位置は持たない' => [['youtube_start_second' => 30], null, null, null],
]);

it('TC-291-11: YouTube を入れた・変えたときに youtube_created_at を今にし、変えなければ残し、消したら空にする', function () {
    $f = cocktailAdminFixture();
    $cocktail = Cocktail::factory()->create(['base_category_id' => $f['nodes']['ラム']->id, 'method_id' => $f['methods'][0]->id]);
    $put = fn (array $input) => $this->putJson("/api/admin/cocktails/{$cocktail->id}", cocktailAdminInput($f, $input))->assertOk();

    Carbon::setTestNow('2026-10-01 10:00:00');
    $put(['youtube' => 'https://www.youtube.com/watch?v=abcdefghijk']);
    expect($cocktail->refresh()->getRawOriginal('youtube_created_at'))->toBe('2026-10-01 10:00:00');

    Carbon::setTestNow('2026-10-02 10:00:00');
    $put(['youtube' => 'abcdefghijk', 'youtube_start_second' => 5]);
    expect($cocktail->refresh()->getRawOriginal('youtube_created_at'))->toBe('2026-10-01 10:00:00');

    $put(['youtube' => 'zyxwvutsrqp', 'youtube_start_second' => 5]);
    expect($cocktail->refresh()->getRawOriginal('youtube_created_at'))->toBe('2026-10-02 10:00:00');

    $put(['youtube_start_second' => 5]);
    expect($cocktail->refresh()->only(['youtube', 'youtube_created_at', 'youtube_start_seconds']))
        ->toBe(['youtube' => null, 'youtube_created_at' => null, 'youtube_start_seconds' => null]);
});

it('TC-291-12: 下書きか公開かは is_draft で決め、公開するときは説明が要る', function (bool $isDraft, ?string $description, bool $ok) {
    $f = cocktailAdminFixture();

    $response = $this->postJson('/api/admin/cocktails', array_merge(
        cocktailAdminInput($f, ['description' => null]),
        ['is_draft' => $isDraft, 'description' => $description],
    ));

    if (! $ok) {
        $response->assertUnprocessable()->assertJsonValidationErrors('description');
        expect(Cocktail::query()->count())->toBe(0);

        return;
    }
    $response->assertCreated()->assertJsonPath('data.is_draft', $isDraft);
    expect(Cocktail::query()->sole()->is_draft)->toBe($isDraft);
})->with([
    '説明つきの下書き' => [true, '書きかけ', true],
    '説明の無い下書き' => [true, null, true],
    '説明つきで公開' => [false, '爽やかな味', true],
    '説明が無いと公開できない' => [false, null, false],
    '説明が空文字でも公開できない' => [false, '', false],
]);

it('TC-291-13: 更新は基本情報を書き換え、同じ材料を送れば材料の行・材料の数・材料の組のハッシュは変わらず、度数は材料から計算し直す。無い ID は 404', function () {
    $f = cocktailAdminFixture();
    $n = $f['nodes'];
    $cocktail = Cocktail::factory()->create([
        'base_category_id' => $n['ラム']->id,
        'method_id' => $f['methods'][0]->id,
        'volume' => '12.5',
        'is_draft' => false,
    ]);
    foreach ([$n['ホワイトラム'], $n['ジン']] as $position => $node) {
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id, 'category_id' => $node->id, 'brand_id' => null, 'amount' => '30ml',
            'position' => $position + 1, 'is_optional' => false,
        ]);
    }
    app(RecipeCounter::class)->recount([$cocktail->id]);
    app(RecipeSignature::class)->rewrite([$cocktail->id]);
    $before = $cocktail->refresh()->only(['recipe_count', 'recipe_signature']);
    $ingredientsBefore = CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->orderBy('position')->get(['category_id', 'amount'])->toArray();

    $this->putJson("/api/admin/cocktails/{$cocktail->id}", cocktailAdminInput($f, [
        'name' => '新しい名前',
        'base_category_id' => $n['ジン']->id,
        'is_draft' => true,
        'ingredients' => [
            cocktailAdminIngredient($n['ホワイトラム'], '30'),
            cocktailAdminIngredient($n['ジン'], '30ml'),
        ],
    ]))->assertOk()
        ->assertJsonPath('data.id', $cocktail->id)
        ->assertJsonPath('data.name', '新しい名前')
        ->assertJsonPath('data.is_draft', true)
        // ホワイトラム 30ml(有名な銘柄なし 0%)・ジン 30ml(40%)
        ->assertJsonPath('data.strength', 20);

    $cocktail->refresh();
    expect($cocktail->only(['name', 'base_category_id', 'is_draft']))->toBe(['name' => '新しい名前', 'base_category_id' => $n['ジン']->id, 'is_draft' => true])
        ->and($cocktail->only(['recipe_count', 'recipe_signature']))->toBe($before)
        ->and($before['recipe_count'])->toBe(2)
        ->and(CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->orderBy('position')->get(['category_id', 'amount'])->toArray())->toBe($ingredientsBefore);

    $this->putJson('/api/admin/cocktails/'.($cocktail->id + 1000), cocktailAdminInput($f))->assertNotFound();
});

it('TC-291-14: 画像つき(multipart)で保存でき、is_major・is_draft が "true"・"false" の文字列で届いても受ける', function () {
    Storage::fake('public');
    $f = cocktailAdminFixture();
    $path = tempnam(sys_get_temp_dir(), 'cocktail-image-') ?: throw new RuntimeException('一時ファイルを作れない');
    file_put_contents($path, base64_decode(COCKTAIL_ADMIN_PNG, true));

    $response = $this->post('/api/admin/cocktails', cocktailAdminInput($f, [
        'is_major' => 'false',
        'is_draft' => 'true',
        'image' => new UploadedFile($path, 'a.png', null, null, true),
    ]), ['Accept' => 'application/json'])->assertCreated();

    $cocktail = Cocktail::query()->whereKey($response->json('data.id'))->sole();
    expect($cocktail->is_major)->toBeFalse()
        ->and($cocktail->is_draft)->toBeTrue()
        ->and($cocktail->image)->toBeString();
    Storage::disk('public')->assertExists((string) $cocktail->image);
    $response->assertJsonPath('data.image', $cocktail->image);

    // 画像だけを消す
    $this->putJson("/api/admin/cocktails/{$cocktail->id}", cocktailAdminInput($f, ['remove_image' => true]))
        ->assertOk()
        ->assertJsonPath('data.image', null);
    Storage::disk('public')->assertMissing((string) $cocktail->image);
});

it('TC-291-15: 選択肢は、技法・グラス(分類「グラス」の用語だけ)・氷を、それぞれ ID の順で返す', function () {
    $f = cocktailAdminFixture();

    $this->getJson('/api/admin/cocktail-form-options')
        ->assertOk()
        ->assertExactJson([
            'methods' => [
                ['id' => $f['methods'][0]->id, 'name' => 'ビルド'],
                ['id' => $f['methods'][1]->id, 'name' => 'シェイク'],
            ],
            'glasses' => [
                ['id' => $f['glasses'][0]->id, 'name' => 'タンブラー'],
                ['id' => $f['glasses'][1]->id, 'name' => 'カクテルグラス'],
            ],
            'ices' => [
                ['id' => $f['ices'][0]->id, 'name' => 'クラッシュド'],
                ['id' => $f['ices'][1]->id, 'name' => 'キューブド'],
            ],
        ]);
});

it('TC-292-01: 材料は 2 件から 20 件まで。0 件・1 件・21 件は 422 で保存しない', function (int $count, bool $ok) {
    $f = cocktailAdminFixture();
    $rows = array_map(fn (int $i): array => cocktailAdminIngredient($f['nodes']['ジン'], (string) ($i + 1)), range(0, max($count - 1, 0)));

    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['ingredients' => array_slice($rows, 0, $count)]));

    if (! $ok) {
        $response->assertUnprocessable()->assertJsonValidationErrors('ingredients');
        expect(Cocktail::query()->count())->toBe(0)->and(CocktailIngredient::query()->count())->toBe(0);

        return;
    }
    $response->assertCreated()->assertJsonCount($count, 'data.ingredients');
    expect(CocktailIngredient::query()->count())->toBe($count);
})->with([
    '0 件' => [0, false],
    '1 件' => [1, false],
    '2 件' => [2, true],
    '20 件' => [20, true],
    '21 件' => [21, false],
]);

it('TC-292-02: 材料を送らないと 422', function () {
    $f = cocktailAdminFixture();

    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['ingredients' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ingredients');
    expect(Cocktail::query()->count())->toBe(0);
});

it('TC-292-03: 材料のノードは実在する根でないノード。根・無い ID は 422、3 段目のノードは通る', function (string $pick, bool $ok) {
    $f = cocktailAdminFixture();
    $n = $f['nodes'];
    $categoryId = match ($pick) {
        'root' => $n['洋酒']->id,
        'missing' => $n['ライム']->id + 1000,
        default => $n['ホワイトラム']->id,
    };

    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['ingredients' => [
        cocktailAdminIngredient($n['ジン'], '45'),
        ['category_id' => $categoryId, 'brand_id' => null, 'amount' => '15', 'is_optional' => false],
    ]]));

    $ok ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors('ingredients.1.category_id');
})->with([
    '根' => ['root', false],
    '無い ID' => ['missing', false],
    '3 段目' => ['deep', true],
]);

it('TC-292-04: 銘柄があれば、ノードは銘柄の所属ノード。違うノード・無い銘柄は 422', function (string $pick, ?string $error) {
    $f = cocktailAdminFixture();
    $n = $f['nodes'];
    $row = match ($pick) {
        'same' => cocktailAdminIngredient($f['brands']['ジン B'], '45'),
        'ancestor' => ['category_id' => $n['ラム']->id, 'brand_id' => $f['brands']['ジン B']->id, 'amount' => '45', 'is_optional' => false],
        default => ['category_id' => $n['ジン']->id, 'brand_id' => $f['brands']['ジン B']->id + 1000, 'amount' => '45', 'is_optional' => false],
    };

    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['ingredients' => [
        cocktailAdminIngredient($n['ソーダ'], '15'),
        $row,
    ]]));

    if ($error !== null) {
        $response->assertUnprocessable()->assertJsonValidationErrors($error);
        expect(Cocktail::query()->count())->toBe(0);

        return;
    }
    $response->assertCreated()
        ->assertJsonPath('data.ingredients.1.category_id', $n['ジン']->id)
        ->assertJsonPath('data.ingredients.1.brand_id', $f['brands']['ジン B']->id);
})->with([
    '銘柄の所属ノード' => ['same', null],
    '所属でないノード' => ['ancestor', 'ingredients.1.category_id'],
    '無い銘柄' => ['missing', 'ingredients.1.brand_id'],
]);

it('TC-292-05: 量は空にできない。送らない・空文字は 422', function (?string $amount) {
    $f = cocktailAdminFixture();
    $row = cocktailAdminIngredient($f['nodes']['ジン'], '45');
    if ($amount === null) {
        unset($row['amount']);
    } else {
        $row['amount'] = $amount;
    }

    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['ingredients' => [
        cocktailAdminIngredient($f['nodes']['ソーダ'], '15'),
        $row,
    ]]))->assertUnprocessable()->assertJsonValidationErrors('ingredients.1.amount');
    expect(Cocktail::query()->count())->toBe(0);
})->with([
    '送らない' => [null],
    '空文字' => [''],
]);

it('TC-292-06: 量は旧の format_amount と同じ形に整えて保存する', function (string $node, string $amount, string $stored) {
    $f = cocktailAdminFixture();

    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['ingredients' => [
        cocktailAdminIngredient($f['nodes']['ジン'], '30'),
        cocktailAdminIngredient($f['nodes'][$node], $amount),
    ]]))->assertCreated()
        ->assertJsonPath('data.ingredients.1.amount', $stored);

    expect(CocktailIngredient::query()->where('cocktail_id', $response->json('data.id'))->where('position', 2)->value('amount'))->toBe($stored);
})->with([
    '30' => ['ジン', '30', '30ml'],
    '1(その他の材料)' => ['ライム', '1', '1個'],
    '1(それ以外)' => ['ソーダ', '1', '1ml'],
    '-' => ['ソーダ', '-', '適量'],
    '1t' => ['ソーダ', '1t', '1tsp'],
    '10m' => ['ソーダ', '10m', '10ml'],
    '2da' => ['ソーダ', '2da', '2dash'],
    '3dr' => ['ソーダ', '3dr', '3drop'],
    '1c' => ['ソーダ', '1c', '1cup'],
    '1/2' => ['ソーダ', '1/2', '1/2'],
    '少量' => ['ソーダ', '少量', '少量'],
]);

it('TC-292-07: 量の長さは 20 文字まで。送った量が 21 文字か、整えると 21 文字になるなら 422', function (string $amount, bool $ok) {
    $f = cocktailAdminFixture();

    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['ingredients' => [
        cocktailAdminIngredient($f['nodes']['ジン'], '30'),
        cocktailAdminIngredient($f['nodes']['ソーダ'], $amount),
    ]]));

    if (! $ok) {
        $response->assertUnprocessable()->assertJsonValidationErrors('ingredients.1.amount');
        expect(Cocktail::query()->count())->toBe(0);

        return;
    }
    $response->assertCreated();
})->with([
    // 正規表現に合わない量も整えるだけで通す(docs/decisions/admin-screens.md の決まったこと 4)
    '整えても 20 文字' => [str_repeat('あ', 20), true],
    '整えると 20 文字(18 桁 + ml)' => [str_repeat('1', 18), true],
    '送った量が 21 文字' => [str_repeat('あ', 21), false],
    '整えると 21 文字(19 桁 + ml)' => [str_repeat('1', 19), false],
]);

it('TC-292-08: 材料は配列の順に position を 1 から振る。並びを入れ替えて保存すると position が入れ替わり、減らした行は残らない', function () {
    $f = cocktailAdminFixture();
    $n = $f['nodes'];
    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['ingredients' => [
        cocktailAdminIngredient($n['ジン'], '45'),
        cocktailAdminIngredient($n['ソーダ'], '90'),
        cocktailAdminIngredient($n['ライム'], '1'),
    ]]))->assertCreated();
    $id = Cocktail::query()->sole()->id;
    $rows = fn (): array => CocktailIngredient::query()->where('cocktail_id', $id)->orderBy('position')
        ->get(['position', 'category_id', 'amount'])->toArray();

    expect($rows())->toBe([
        ['position' => 1, 'category_id' => $n['ジン']->id, 'amount' => '45ml'],
        ['position' => 2, 'category_id' => $n['ソーダ']->id, 'amount' => '90ml'],
        ['position' => 3, 'category_id' => $n['ライム']->id, 'amount' => '1個'],
    ]);

    $this->putJson("/api/admin/cocktails/{$id}", cocktailAdminInput($f, ['ingredients' => [
        cocktailAdminIngredient($n['ソーダ'], '90'),
        cocktailAdminIngredient($n['ジン'], '45'),
    ]]))->assertOk()
        ->assertJsonPath('data.ingredients.0.category_id', $n['ソーダ']->id)
        ->assertJsonPath('data.ingredients.1.category_id', $n['ジン']->id);

    expect($rows())->toBe([
        ['position' => 1, 'category_id' => $n['ソーダ']->id, 'amount' => '90ml'],
        ['position' => 2, 'category_id' => $n['ジン']->id, 'amount' => '45ml'],
    ]);
});

it('TC-292-09: 保存すると、度数・材料の数・材料の組のハッシュを材料から作り直す', function () {
    $f = cocktailAdminFixture();
    $n = $f['nodes'];
    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f))->assertCreated();
    $id = Cocktail::query()->sole()->id;

    // ジン B 30ml(47%)・ソーダ 60ml(任意)・ライム 1 個(その他)
    $this->putJson("/api/admin/cocktails/{$id}", cocktailAdminInput($f, ['ingredients' => [
        cocktailAdminIngredient($f['brands']['ジン B'], '30'),
        cocktailAdminIngredient($n['ソーダ'], '60', true),
        cocktailAdminIngredient($n['ライム'], '1'),
    ]]))->assertOk()
        ->assertJsonPath('data.strength', 15.7);

    $cocktail = Cocktail::query()->sole();
    expect($cocktail->strength())->toBe(15.7)
        // 任意の材料と「その他」の材料は数えない
        ->and($cocktail->recipe_count)->toBe(1)
        ->and($cocktail->recipe_signature)->toBe(RecipeSignature::of([
            ['category_id' => $n['ジン']->id, 'brand_id' => $f['brands']['ジン B']->id, 'amount' => '30ml', 'is_optional' => false],
            ['category_id' => $n['ソーダ']->id, 'brand_id' => null, 'amount' => '60ml', 'is_optional' => true],
            ['category_id' => $n['ライム']->id, 'brand_id' => null, 'amount' => '1個', 'is_optional' => false],
        ]));
});

it('TC-292-10: 度数を計算できない材料の組は 422 で、カクテルも材料も書き換えない', function (string $ginAmount, string $sodaAmount) {
    $f = cocktailAdminFixture();
    $n = $f['nodes'];
    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f))->assertCreated();
    $id = Cocktail::query()->sole()->id;
    $before = Cocktail::query()->sole()->only(['name', 'volume', 'recipe_count', 'recipe_signature']);

    $this->putJson("/api/admin/cocktails/{$id}", cocktailAdminInput($f, [
        'name' => '書き換えない名前',
        'ingredients' => [
            cocktailAdminIngredient($n['ジン'], $ginAmount),
            cocktailAdminIngredient($n['ソーダ'], $sodaAmount),
        ],
    ]))->assertUnprocessable()->assertJsonValidationErrors('ingredients');

    expect(Cocktail::query()->sole()->only(['name', 'volume', 'recipe_count', 'recipe_signature']))->toBe($before)
        ->and(CocktailIngredient::query()->where('cocktail_id', $id)->orderBy('position')->pluck('amount')->all())->toBe(['45ml', '15ml']);
})->with([
    'tsp・dash だけ(量のある材料も適量も無い)' => ['1t', '2da'],
    '分母が数でない' => ['1/a', '30'],
]);

it('TC-292-11: 任意は送らなければ任意でない。multipart で "true"・"false" の文字列で届いても受ける', function () {
    $f = cocktailAdminFixture();
    $n = $f['nodes'];
    $input = cocktailAdminInput($f, ['ingredients' => [
        ['category_id' => (string) $n['ジン']->id, 'amount' => '45'],
        ['category_id' => (string) $n['ソーダ']->id, 'amount' => '15', 'is_optional' => 'true'],
        ['category_id' => (string) $n['ライム']->id, 'amount' => '1', 'is_optional' => 'false'],
    ]]);

    $response = $this->post('/api/admin/cocktails', $input, ['Accept' => 'application/json'])->assertCreated();

    expect(CocktailIngredient::query()->where('cocktail_id', $response->json('data.id'))->orderBy('position')->pluck('is_optional')->all())
        ->toBe([false, true, false]);
});

it('TC-292-12: 取得は材料を、保存のときに送る形でレシピの順に返す', function () {
    $f = cocktailAdminFixture();
    $n = $f['nodes'];
    $cocktail = Cocktail::factory()->create();
    CocktailIngredient::factory()->create(['cocktail_id' => $cocktail->id, 'position' => 2, 'category_id' => $n['ソーダ']->id, 'brand_id' => null, 'amount' => '適量', 'is_optional' => true]);
    CocktailIngredient::factory()->forBrand($f['brands']['ジン B'])->create(['cocktail_id' => $cocktail->id, 'position' => 1, 'amount' => '45ml', 'is_optional' => false]);

    $this->getJson("/api/admin/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertJsonPath('data.ingredients', [
            ['category_id' => $n['ジン']->id, 'brand_id' => $f['brands']['ジン B']->id, 'amount' => '45ml', 'is_optional' => false],
            ['category_id' => $n['ソーダ']->id, 'brand_id' => null, 'amount' => '適量', 'is_optional' => true],
        ]);
});

/**
 * 別名を持つカクテルを作る。別名は並べた順に登録する。
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<string>  $aliases
 */
function cocktailWithAliases(array $attributes, array $aliases = []): Cocktail
{
    $name = is_string($attributes['name'] ?? null) ? $attributes['name'] : 'テスト';
    $cocktail = Cocktail::factory()->create([...$attributes, 'name' => $name, 'name_search' => NameNormalizer::normalize($name)]);
    foreach ($aliases as $alias) {
        CocktailAlias::factory()->named($alias)->create(['cocktail_id' => $cocktail->id]);
    }

    return $cocktail;
}

/**
 * カクテルの別名を、登録した順に [名前, 検索用の名前] で返す。
 *
 * @return list<array{0: string, 1: string}>
 */
function storedAliases(Cocktail $cocktail): array
{
    return array_values(CocktailAlias::query()
        ->where('cocktail_id', $cocktail->id)
        ->orderBy('id')
        ->get()
        ->map(fn (CocktailAlias $a): array => [$a->name, $a->name_search])
        ->all());
}

it('TC-293-01: 取得は、別名の名前を登録した順に aliases で返す。別名が無ければ空の配列', function () {
    $f = cocktailAdminFixture();
    $base = ['base_category_id' => $f['nodes']['ラム']->id, 'method_id' => $f['methods'][0]->id];
    $with = cocktailWithAliases([...$base, 'name' => 'レゲエパンチ'], ['ピーチウーロン', 'ピーチ烏龍']);
    $without = cocktailWithAliases([...$base, 'name' => 'ダイキリ']);

    $this->getJson("/api/admin/cocktails/{$with->id}")
        ->assertOk()
        ->assertJsonPath('data.aliases', ['ピーチウーロン', 'ピーチ烏龍']);
    $this->getJson("/api/admin/cocktails/{$without->id}")
        ->assertOk()
        ->assertJsonPath('data.aliases', []);
});

it('TC-293-02: 新規で送った別名を、送った並びで、検索用の名前を付けて保存する', function () {
    $f = cocktailAdminFixture();
    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, [
        'name' => 'レゲエパンチ',
        'aliases' => ['ピーチ・ウーロン', 'ＰＯ'],
    ]))->assertCreated()
        ->assertJsonPath('data.aliases', ['ピーチ・ウーロン', 'ＰＯ'])
        ->assertJsonPath('warnings', []);

    $cocktail = Cocktail::query()->whereKey($response->json('data.id'))->sole();
    expect(storedAliases($cocktail))->toBe([['ピーチ・ウーロン', 'ピーチウーロン'], ['ＰＯ', 'PO']]);
});

it('TC-293-03: 更新で送った別名に置き換える(足した別名は入り、送らなかった別名は消える)', function () {
    $f = cocktailAdminFixture();
    $base = ['base_category_id' => $f['nodes']['ラム']->id, 'method_id' => $f['methods'][0]->id];
    $cocktail = cocktailWithAliases([...$base, 'name' => 'レゲエパンチ'], ['ピーチウーロン', 'ピーチ烏龍']);

    $this->putJson("/api/admin/cocktails/{$cocktail->id}", cocktailAdminInput($f, [
        'name' => 'レゲエパンチ',
        'aliases' => ['ピーチ・ティー', 'ピーチウーロン'],
    ]))->assertOk()
        ->assertJsonPath('data.aliases', ['ピーチ・ティー', 'ピーチウーロン']);

    expect(storedAliases($cocktail))->toBe([['ピーチ・ティー', 'ピーチティー'], ['ピーチウーロン', 'ピーチウーロン']]);
});

it('TC-293-04: 空の別名を送ると全部消す(JSON は空の配列、multipart は空の値)', function (bool $multipart) {
    $f = cocktailAdminFixture();
    $base = ['base_category_id' => $f['nodes']['ラム']->id, 'method_id' => $f['methods'][0]->id];
    $cocktail = cocktailWithAliases([...$base, 'name' => 'レゲエパンチ'], ['ピーチウーロン']);
    $uri = "/api/admin/cocktails/{$cocktail->id}";

    $response = $multipart
        ? $this->post($uri, [...cocktailAdminInput($f, ['name' => 'レゲエパンチ']), '_method' => 'PUT', 'aliases' => ''], ['Accept' => 'application/json'])
        : $this->putJson($uri, cocktailAdminInput($f, ['name' => 'レゲエパンチ', 'aliases' => []]));

    $response->assertOk()->assertJsonPath('data.aliases', []);
    expect(storedAliases($cocktail))->toBe([]);
})->with(['JSON' => [false], 'multipart' => [true]]);

it('TC-293-05: 別名を送らない更新は、今の別名を残す', function () {
    $f = cocktailAdminFixture();
    $base = ['base_category_id' => $f['nodes']['ラム']->id, 'method_id' => $f['methods'][0]->id];
    $cocktail = cocktailWithAliases([...$base, 'name' => 'レゲエパンチ'], ['ピーチウーロン', 'ピーチ烏龍']);

    $this->putJson("/api/admin/cocktails/{$cocktail->id}", cocktailAdminInput($f, ['name' => 'レゲエ・パンチ']))
        ->assertOk()
        ->assertJsonPath('data.name', 'レゲエ・パンチ')
        ->assertJsonPath('data.aliases', ['ピーチウーロン', 'ピーチ烏龍']);

    expect(storedAliases($cocktail))->toBe([['ピーチウーロン', 'ピーチウーロン'], ['ピーチ烏龍', 'ピーチ烏龍']]);
});

it('TC-293-06: 検索用の名前が同じ別名を 2 つ送ると 422 で、後の方を誤りにし、何も保存しない', function (string $first, string $second) {
    $f = cocktailAdminFixture();
    $base = ['base_category_id' => $f['nodes']['ラム']->id, 'method_id' => $f['methods'][0]->id];
    $cocktail = cocktailWithAliases([...$base, 'name' => 'レゲエパンチ'], ['ピーチ烏龍']);

    $this->putJson("/api/admin/cocktails/{$cocktail->id}", cocktailAdminInput($f, [
        'name' => '新しい名前',
        'aliases' => ['別の名前', $first, $second],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['aliases.2' => '同じ別名がほかにあります'])
        ->assertJsonMissingValidationErrors(['aliases.0', 'aliases.1']);

    expect($cocktail->refresh()->name)->toBe('レゲエパンチ')
        ->and(storedAliases($cocktail))->toBe([['ピーチ烏龍', 'ピーチ烏龍']]);
})->with([
    'まったく同じ' => ['ピーチウーロン', 'ピーチウーロン'],
    '中黒の有無' => ['ピーチウーロン', 'ピーチ・ウーロン'],
    '空白の有無' => ['Peach Oolong', 'PeachOolong'],
    '全角と半角の英数字' => ['PO1', 'ＰＯ１'],
    '大文字と小文字' => ['Peach', 'peach'],
]);

it('TC-293-07: 検索用の名前がカクテル自身の名前と同じ別名は 422 で、何も保存しない', function (string $name, string $alias) {
    $f = cocktailAdminFixture();
    $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['name' => $name, 'aliases' => [$alias]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['aliases.0' => 'カクテルの名前と同じ別名です']);

    expect(Cocktail::query()->count())->toBe(0)->and(CocktailAlias::query()->count())->toBe(0);
})->with([
    'まったく同じ' => ['レゲエパンチ', 'レゲエパンチ'],
    '中黒の有無' => ['レゲエパンチ', 'レゲエ・パンチ'],
    '全角と半角の英数字' => ['XYZ', 'ＸＹＺ'],
]);

it('TC-293-08: 別名を送らずに、名前を今の別名と同じ検索用の名前に変えると 422', function () {
    $f = cocktailAdminFixture();
    $base = ['base_category_id' => $f['nodes']['ラム']->id, 'method_id' => $f['methods'][0]->id];
    $cocktail = cocktailWithAliases([...$base, 'name' => 'レゲエパンチ'], ['ピーチウーロン']);

    $this->putJson("/api/admin/cocktails/{$cocktail->id}", cocktailAdminInput($f, ['name' => 'ピーチ・ウーロン']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => '別名と同じ名前です']);

    expect($cocktail->refresh()->name)->toBe('レゲエパンチ');
});

it('TC-293-09: 別名がほかのカクテルの名前・別名と同じなら、保存したうえで warnings にどのカクテルと同じかを返す', function () {
    $f = cocktailAdminFixture();
    $base = ['base_category_id' => $f['nodes']['ラム']->id, 'method_id' => $f['methods'][0]->id];
    $byName = cocktailWithAliases([...$base, 'name' => 'ピーチウーロン']);
    $byAlias = cocktailWithAliases([...$base, 'name' => 'ファジーネーブル'], ['ピーチ烏龍']);
    // 名前でも別名でも同じになるカクテルは、名前の方で 1 件だけ返す
    $both = cocktailWithAliases([...$base, 'name' => 'ピーチ烏龍', 'is_draft' => true], ['ピーチ・烏龍']);
    $target = cocktailWithAliases([...$base, 'name' => 'レゲエパンチ']);

    $this->putJson("/api/admin/cocktails/{$target->id}", cocktailAdminInput($f, [
        'name' => 'レゲエパンチ',
        'aliases' => ['ほかと同じでない', 'ピーチ・ウーロン', 'ピーチ烏龍'],
    ]))->assertOk()
        ->assertJsonPath('data.aliases', ['ほかと同じでない', 'ピーチ・ウーロン', 'ピーチ烏龍'])
        ->assertJsonPath('warnings', [
            ['field' => 'aliases.1', 'message' => '「ピーチウーロン」の名前と同じです', 'cocktail_id' => $byName->id, 'cocktail_name' => 'ピーチウーロン'],
            ['field' => 'aliases.2', 'message' => '「ファジーネーブル」の別名と同じです', 'cocktail_id' => $byAlias->id, 'cocktail_name' => 'ファジーネーブル'],
            ['field' => 'aliases.2', 'message' => '「ピーチ烏龍」の名前と同じです', 'cocktail_id' => $both->id, 'cocktail_name' => 'ピーチ烏龍'],
        ]);

    expect(storedAliases($target))->toBe([
        ['ほかと同じでない', 'ほかと同じでない'], ['ピーチ・ウーロン', 'ピーチウーロン'], ['ピーチ烏龍', 'ピーチ烏龍'],
    ]);
});

it('TC-293-10: ほかのカクテルと同じでなければ warnings は空(自分の別名・名前とは比べない)', function () {
    $f = cocktailAdminFixture();
    $base = ['base_category_id' => $f['nodes']['ラム']->id, 'method_id' => $f['methods'][0]->id];
    cocktailWithAliases([...$base, 'name' => 'ダイキリ'], ['フローズン']);
    $target = cocktailWithAliases([...$base, 'name' => 'レゲエパンチ'], ['ピーチウーロン']);

    $this->putJson("/api/admin/cocktails/{$target->id}", cocktailAdminInput($f, [
        'name' => 'レゲエパンチ',
        'aliases' => ['ピーチウーロン'],
    ]))->assertOk()->assertJsonPath('warnings', []);
});

it('TC-293-11: 別名は 255 文字までの文字列の配列で、空の要素・配列でない値は 422', function (mixed $aliases, ?string $error) {
    $f = cocktailAdminFixture();
    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, ['name' => 'レゲエパンチ', 'aliases' => $aliases]));

    if ($error === null) {
        $response->assertCreated();

        return;
    }
    $response->assertUnprocessable()->assertJsonValidationErrors($error);
    expect(Cocktail::query()->count())->toBe(0);
})->with([
    '255 文字' => [[str_repeat('あ', 255)], null],
    '256 文字' => [[str_repeat('あ', 256)], 'aliases.0'],
    '空文字の要素' => [['ピーチウーロン', ''], 'aliases.1'],
    '文字列でない要素' => [[['x']], 'aliases.0'],
    '配列でない' => ['ピーチウーロン', 'aliases'],
]);

it('TC-293-12: 保存した別名で、名前の横断検索がカクテルを見つける', function () {
    $f = cocktailAdminFixture();
    $response = $this->postJson('/api/admin/cocktails', cocktailAdminInput($f, [
        'name' => 'レゲエパンチ',
        'aliases' => ['ピーチ・ウーロン'],
    ]))->assertCreated();

    $this->getJson('/api/search?'.http_build_query(['q' => 'ピーチウーロン']))
        ->assertOk()
        ->assertJsonPath('cocktails.0.id', $response->json('data.id'))
        ->assertJsonPath('cocktails.0.matched_alias', 'ピーチ・ウーロン');
});
