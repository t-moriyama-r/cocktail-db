<?php

use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Models\User;
use App\Services\CategoryTree;
use App\Services\RecipeCounter;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

// 管理 API のカテゴリ木のノードの一覧・取得・新規・更新・削除(#121)。旧の DetailEditController@category・middle と
// EditCategoryValidate・EditMiddleValidate の置き換え(削除は旧に無い)。
// 木は CategoryTree だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/** 1x1 の PNG(画像の種類は中身から推定させるので、本物の画像のバイト列にする) */
const CATEGORY_ADMIN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/**
 * テストに使う木。
 *
 * 洋酒(根)
 * ├ ラム ── 有名な銘柄: バカルディ
 * │  ├ ホワイトラム ── 銘柄: バカルディ・有名な銘柄: バカルディ
 * │  └ ダークラム
 * └ ジン ── 銘柄: ゴードン・有名な銘柄: ゴードン
 * その他(根。other)
 * └ 砂糖
 * ソフトドリンク(根。soft_drink)
 *
 * @return array{nodes: array<string, Category>, bacardi: Brand, gordons: Brand}
 */
function categoryAdminTree(): array
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
    $n['ダークラム'] = $add($n['ラム'], 'ダークラム');
    $n['ジン'] = $add($n['洋酒'], 'ジン');
    $n['その他'] = $add(null, 'その他', 'other');
    $n['砂糖'] = $add($n['その他'], '砂糖');
    $n['ソフトドリンク'] = $add(null, 'ソフトドリンク', 'soft_drink');

    $bacardi = Brand::factory()->create([
        'category_id' => $n['ホワイトラム']->id,
        'name' => 'バカルディ',
        'name_search' => NameNormalizer::normalize('バカルディ'),
        'name_eng' => 'Bacardi',
    ]);
    $gordons = Brand::factory()->create([
        'category_id' => $n['ジン']->id,
        'name' => 'ゴードン',
        'name_search' => NameNormalizer::normalize('ゴードン'),
        'name_eng' => "Gordon's",
    ]);
    $n['ラム']->update(['famous_brand_id' => $bacardi->id]);
    $n['ホワイトラム']->update(['famous_brand_id' => $bacardi->id]);
    $n['ジン']->update(['famous_brand_id' => $gordons->id]);

    return ['nodes' => $n, 'bacardi' => $bacardi, 'gordons' => $gordons];
}

/**
 * 正しい入力。$overrides で項目を差し替える(null を入れた項目は送らない。parent_id は必ず送る)。
 *
 * @param  array<array-key, mixed>  $overrides  項目の名前 → 値(データセットから受けるので、鍵の型は絞らない)
 * @return array<array-key, mixed>
 */
function categoryAdminInput(?Category $parent, array $overrides = []): array
{
    $input = array_merge([
        'name' => 'ゴールドラム',
        'name_eng' => 'Gold Rum',
        'description' => '熟成させたラム',
        'is_major' => true,
        'is_like' => false,
        'is_powder' => false,
    ], $overrides);

    return ['parent_id' => $parent?->id, ...array_filter($input, fn (mixed $value): bool => $value !== null)];
}

/**
 * その場で作る銘柄の欄。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function categoryAdminBrandInput(array $overrides = []): array
{
    return array_merge([
        'brand_name' => 'ロンサカパ',
        'brand_name_eng' => 'Ron Zacapa',
        'brand_strength' => 40,
        'brand_description' => 'グアテマラの銘柄',
        'brand_is_major' => true,
        'brand_is_like' => false,
    ], $overrides);
}

/**
 * 今のノードの値を編集の入力にする(親と有名な銘柄も今のまま送る)。$overrides で項目を差し替える。
 *
 * @param  array<array-key, mixed>  $overrides
 * @return array<array-key, mixed>
 */
function categoryAdminCurrentInput(Category $node, array $overrides = []): array
{
    $node->refresh();

    return array_merge([
        'parent_id' => $node->parent()->first()?->id,
        'role' => $node->role?->value,
        'name' => $node->name,
        'name_eng' => $node->name_eng,
        'description' => $node->description ?? '説明',
        'is_major' => $node->is_major,
        'is_like' => $node->is_like,
        'is_powder' => $node->is_powder,
        'famous_brand_id' => $node->famous_brand_id,
    ], $overrides);
}

function categoryAdminPng(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'category-image-') ?: throw new RuntimeException('一時ファイルを作れない');
    file_put_contents($path, base64_decode(CATEGORY_ADMIN_PNG, true));

    return new UploadedFile($path, 'a.png', null, null, true);
}

/**
 * 材料のノードを並べてカクテルを作り、材料の数を数えておく。
 *
 * @param  list<Category>  $nodes
 */
function categoryAdminCocktail(Category $base, array $nodes): Cocktail
{
    $cocktail = Cocktail::factory()->create(['base_category_id' => $base->id]);
    foreach ($nodes as $position => $node) {
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id,
            'category_id' => $node->id,
            'position' => $position + 1,
            'is_optional' => false,
        ]);
    }
    app(RecipeCounter::class)->recount([$cocktail->id]);

    return $cocktail->refresh();
}

/**
 * 根から $node までの名前。
 *
 * @return list<string>
 */
function categoryAdminPathNames(Category $node): array
{
    return array_values(app(CategoryTree::class)->path($node->refresh())->map(fn (Category $step): string => $step->name)->all());
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('TC-121-01: ログインしていないと、一覧・取得・新規・更新・削除は 401', function (string $method, string $uri) {
    auth()->logout();
    $node = app(CategoryTree::class)->addNode(null, Category::factory()->make()->getAttributes());

    $this->json($method, str_replace('{id}', (string) $node->id, $uri))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
    expect(Category::query()->whereKey($node->id)->exists())->toBeTrue();
})->with([
    '一覧' => ['GET', '/api/admin/categories'],
    '取得' => ['GET', '/api/admin/categories/{id}'],
    '新規' => ['POST', '/api/admin/categories'],
    '更新' => ['PUT', '/api/admin/categories/{id}'],
    '削除' => ['DELETE', '/api/admin/categories/{id}'],
]);

it('TC-121-02: 一覧は木全体を入れ子で、兄弟の並び順に、根の役割とノードの根の役割を付けて返す', function () {
    ['nodes' => $n] = categoryAdminTree();
    $n['ラム']->update(['sort_order' => 2]);
    $n['ジン']->update(['sort_order' => 1]);

    $response = $this->getJson('/api/admin/categories')->assertOk();

    $response->assertJsonPath('data.0.name', '洋酒')
        ->assertJsonPath('data.0.role', null)
        ->assertJsonPath('data.0.root_role', null)
        ->assertJsonPath('data.0.children.0.name', 'ジン')
        ->assertJsonPath('data.0.children.1.name', 'ラム')
        ->assertJsonPath('data.0.children.1.children.0.name', 'ホワイトラム')
        ->assertJsonPath('data.0.children.1.children.0.name_eng', 'ホワイトラム-eng')
        ->assertJsonPath('data.0.children.1.children.1.name', 'ダークラム')
        ->assertJsonPath('data.0.children.1.children.1.children', [])
        ->assertJsonPath('data.1.name', 'その他')
        ->assertJsonPath('data.1.role', 'other')
        ->assertJsonPath('data.1.children.0.name', '砂糖')
        ->assertJsonPath('data.1.children.0.role', null)
        ->assertJsonPath('data.1.children.0.root_role', 'other')
        ->assertJsonPath('data.2.role', 'soft_drink');
    expect($response->json('data'))->toHaveCount(3);
});

it('TC-121-03: 取得は、親・パンくず・役割・有名な銘柄の候補・子・直下の銘柄・消せない理由を返し、説明に名前を添え、開始位置を分ける。無い ID は 404', function () {
    ['nodes' => $n, 'bacardi' => $bacardi] = categoryAdminTree();
    $n['ラム']->update([
        'description' => "@n{$n['ジン']->id}@と並ぶ",
        'youtube' => 'abcdefghijk',
        'youtube_start_seconds' => 3723,
    ]);

    $this->getJson("/api/admin/categories/{$n['ラム']->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $n['ラム']->id)
        ->assertJsonPath('data.parent_id', $n['洋酒']->id)
        ->assertJsonPath('data.path', [['id' => $n['洋酒']->id, 'name' => '洋酒']])
        ->assertJsonPath('data.role', null)
        ->assertJsonPath('data.root_role', null)
        ->assertJsonPath('data.famous_brand_requirement', 'required')
        ->assertJsonPath('data.description', "@n{$n['ジン']->id}@{!ジン!}と並ぶ")
        ->assertJsonPath('data.youtube_start_hour', 1)
        ->assertJsonPath('data.youtube_start_minute', 2)
        ->assertJsonPath('data.youtube_start_second', 3)
        ->assertJsonPath('data.famous_brand_id', $bacardi->id)
        ->assertJsonPath('data.famous_brand_candidates', [['id' => $bacardi->id, 'name' => 'バカルディ', 'name_eng' => 'Bacardi']])
        ->assertJsonPath('data.children', [
            ['id' => $n['ホワイトラム']->id, 'name' => 'ホワイトラム', 'name_eng' => 'ホワイトラム-eng'],
            ['id' => $n['ダークラム']->id, 'name' => 'ダークラム', 'name_eng' => 'ダークラム-eng'],
        ])
        ->assertJsonPath('data.brands', [])
        ->assertJsonPath('data.delete_blockers', [['code' => 'children', 'message' => '子のノードがあります']]);

    $this->getJson("/api/admin/categories/{$n['その他']->id}")
        ->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.path', [])
        ->assertJsonPath('data.role', 'other')
        ->assertJsonPath('data.famous_brand_requirement', 'none');
    $this->getJson("/api/admin/categories/{$n['砂糖']->id}")
        ->assertJsonPath('data.root_role', 'other')
        ->assertJsonPath('data.famous_brand_requirement', 'optional')
        ->assertJsonPath('data.delete_blockers', []);

    $this->getJson('/api/admin/categories/999999')->assertNotFound();
});

it('TC-121-04: 洋酒の部分木の新規は、その場で作った銘柄を直下の銘柄と有名な銘柄にし、201 で返す', function () {
    ['nodes' => $n] = categoryAdminTree();
    $n['ホワイトラム']->update(['sort_order' => 7]);
    $n['ダークラム']->update(['sort_order' => 3]);

    $response = $this->postJson('/api/admin/categories', categoryAdminInput($n['ラム'], [
        'name' => 'ゴールドラム',
        'description' => 'ジンではないラム',
        'youtube' => 'https://www.youtube.com/watch?v=abcdefghijk',
        'youtube_start_minute' => 1,
        ...categoryAdminBrandInput([
            'brand_youtube' => 'zyxwvutsrqp',
            'brand_youtube_start_second' => 30,
            'brand_image_txt' => '公式',
            'brand_image_url' => 'https://example.com/zacapa',
        ]),
    ]))->assertCreated()->assertJsonPath('warnings', []);

    $node = Category::query()->whereKey($response->json('data.id'))->sole();
    expect(categoryAdminPathNames($node))->toBe(['洋酒', 'ラム', 'ゴールドラム'])
        ->and($node->name_search)->toBe(NameNormalizer::normalize('ゴールドラム'))
        ->and($node->description)->toBe("@n{$n['ジン']->id}@ではない@n{$n['ラム']->id}@")
        ->and($node->youtube)->toBe('abcdefghijk')
        ->and($node->youtube_start_seconds)->toBe(60)
        ->and($node->youtube_created_at)->not->toBeNull()
        ->and($node->role)->toBeNull()
        // 兄弟(ホワイトラム 7・ダークラム 3)の末尾
        ->and($node->sort_order)->toBe(8);

    $brand = Brand::query()->findOrFail($node->famous_brand_id);
    expect($brand->only(['category_id', 'name', 'name_eng', 'is_major', 'is_like', 'youtube', 'youtube_start_seconds', 'image_txt', 'image_url']))
        ->toBe([
            'category_id' => $node->id,
            'name' => 'ロンサカパ',
            'name_eng' => 'Ron Zacapa',
            'is_major' => true,
            'is_like' => false,
            'youtube' => 'zyxwvutsrqp',
            'youtube_start_seconds' => 30,
            'image_txt' => '公式',
            'image_url' => 'https://example.com/zacapa',
        ])
        ->and((float) $brand->volume)->toBe(40.0);
    $response->assertJsonPath('data.famous_brand_id', $brand->id)
        ->assertJsonPath('data.brands', [['id' => $brand->id, 'name' => 'ロンサカパ', 'name_eng' => 'Ron Zacapa']]);
});

it('TC-121-05: 必須の項目が無いと 422 で、保存しない', function (string $field, mixed $value) {
    ['nodes' => $n] = categoryAdminTree();
    $input = [...categoryAdminInput($n['砂糖']), $field => $value];
    if ($value === null) {
        unset($input[$field]);
    }

    $this->postJson('/api/admin/categories', $input)->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect(Category::query()->where('name', 'ゴールドラム')->exists())->toBeFalse();
})->with([
    '親の欄が無い' => ['parent_id', null],
    '名前が無い' => ['name', null],
    '名前が空' => ['name', ''],
    '英名が無い' => ['name_eng', null],
    '説明が無い' => ['description', null],
    'メジャーが無い' => ['is_major', null],
    'お気に入りが無い' => ['is_like', null],
    '粉ものが無い' => ['is_powder', null],
    'メジャーが真偽値でない' => ['is_major', 'abc'],
    '親が無いノード' => ['parent_id', 999999],
]);

it('TC-121-06: 画像つき(multipart)で、真偽値が "true"・"false" の文字列で届いても受け、親の空は根にする', function () {
    ['nodes' => $n] = categoryAdminTree();

    $this->post('/api/admin/categories', [
        ...categoryAdminInput($n['砂糖'], ['is_major' => 'true', 'is_like' => 'false', 'is_powder' => 'TRUE']),
        'image' => categoryAdminPng(),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.is_major', true)
        ->assertJsonPath('data.is_like', false)
        ->assertJsonPath('data.is_powder', true);

    $this->post('/api/admin/categories', [
        ...categoryAdminInput(null, ['name' => '新しい根', 'is_major' => 'false', 'is_like' => 'false', 'is_powder' => 'false']),
        'parent_id' => '',
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.parent_id', null);
});

it('TC-121-07: 名前・英名・YouTube・リンクの文字は 255 文字まで、リンクの URL は http・https だけ', function (string $field, string $value, bool $ok) {
    ['nodes' => $n] = categoryAdminTree();

    $response = $this->postJson('/api/admin/categories', categoryAdminInput($n['砂糖'], [$field => $value]));

    $ok ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    '名前 255 文字' => ['name', str_repeat('あ', 255), true],
    '名前 256 文字' => ['name', str_repeat('あ', 256), false],
    '英名 255 文字' => ['name_eng', str_repeat('a', 255), true],
    '英名 256 文字' => ['name_eng', str_repeat('a', 256), false],
    'YouTube 256 文字' => ['youtube', str_repeat('a', 256), false],
    'リンクの文字 255 文字' => ['image_txt', str_repeat('a', 255), true],
    'リンクの文字 256 文字' => ['image_txt', str_repeat('a', 256), false],
    'リンクの URL が https' => ['image_url', 'https://example.com/a', true],
    'リンクの URL が javascript:' => ['image_url', 'javascript:alert(1)', false],
]);

it('TC-121-08: 同じ親の下(根なら根どうし)に、検索用の名前が同じノードがあれば 422。親が違えば通る', function (string $parent, string $name, bool $ok) {
    ['nodes' => $n] = categoryAdminTree();

    // 洋酒の部分木では有名な銘柄が要るので、根のほかは銘柄もその場で作る
    $input = $parent === '' ? categoryAdminInput(null, ['name' => $name]) : categoryAdminInput($n[$parent], ['name' => $name, ...categoryAdminBrandInput()]);

    $response = $this->postJson('/api/admin/categories', $input);

    if ($ok) {
        $response->assertCreated();
    } else {
        $response->assertUnprocessable()->assertJsonPath('errors.name', ['同じ親の下に、同じ名前のノードがあります']);
        expect(Category::query()->where('name', $name)->count())->toBe(1);
    }
})->with([
    '同じ親・同じ名前' => ['ラム', 'ホワイトラム', false],
    '同じ親・ひらがなにした名前' => ['ラム', 'ほわいとらむ', false],
    '親が違う' => ['ジン', 'ホワイトラム', true],
    '親が違う(孫の名前)' => ['洋酒', 'ホワイトラム', true],
    '根どうし' => ['', 'その他', false],
]);

it('TC-121-09: 同じ親の下に同じ名前のノードが既にあっても、名前も親も変えない更新は通る。名前を変えて重なる・重なる親の下へ移すと 422', function () {
    ['nodes' => $n] = categoryAdminTree();
    $tree = app(CategoryTree::class);
    // 移行した旧のデータにある、同じ親の下の同じ名前のノード(docs/decisions/admin-screens.md の「旧 DB で確かめた事実」)
    $twin = $tree->addNode($n['砂糖'], Category::factory()->make(['name' => '粉糖', 'name_search' => NameNormalizer::normalize('粉糖')])->getAttributes());
    $twin2 = $tree->addNode($n['砂糖'], Category::factory()->make(['name' => '粉糖', 'name_search' => NameNormalizer::normalize('粉糖')])->getAttributes());
    $other = $tree->addNode($n['その他'], Category::factory()->make(['name' => 'ライム', 'name_search' => NameNormalizer::normalize('ライム')])->getAttributes());

    $this->putJson("/api/admin/categories/{$twin->id}", categoryAdminCurrentInput($twin, ['is_major' => true]))
        ->assertOk()
        ->assertJsonPath('data.is_major', true);

    $this->putJson("/api/admin/categories/{$other->id}", categoryAdminCurrentInput($other, ['name' => '砂糖']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.name', ['同じ親の下に、同じ名前のノードがあります']);
    $this->putJson("/api/admin/categories/{$twin2->id}", categoryAdminCurrentInput($twin2, ['parent_id' => $n['その他']->id, 'name' => 'ライム']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.name', ['同じ親の下に、同じ名前のノードがあります']);
    expect(categoryAdminPathNames($twin2))->toBe(['その他', '砂糖', '粉糖'])
        ->and($other->refresh()->name)->toBe('ライム');
});

it('TC-121-10: 英名が同じ親の下のほかのノードと同じなら、保存したうえで警告を返す。親が違えば警告しない', function () {
    ['nodes' => $n] = categoryAdminTree();

    $this->postJson('/api/admin/categories', categoryAdminInput($n['ラム'], ['name_eng' => 'ホワイトラム-eng', ...categoryAdminBrandInput()]))
        ->assertCreated()
        ->assertJsonPath('warnings', [['field' => 'name_eng', 'message' => '同じ名前が存在しています']]);
    $this->postJson('/api/admin/categories', categoryAdminInput($n['ジン'], ['name_eng' => 'ホワイトラム-eng', ...categoryAdminBrandInput(['brand_name' => '別の銘柄'])]))
        ->assertCreated()
        ->assertJsonPath('warnings', []);
});

it('TC-121-11: 開始位置は時 0 から、分・秒は 0〜59', function (array $input, ?string $error, ?int $seconds) {
    ['nodes' => $n] = categoryAdminTree();

    $response = $this->postJson('/api/admin/categories', categoryAdminInput($n['砂糖'], ['youtube' => 'abcdefghijk', ...$input]));

    if ($error !== null) {
        $response->assertUnprocessable()->assertJsonValidationErrors([$error]);

        return;
    }
    $response->assertCreated();
    expect(Category::query()->whereKey($response->json('data.id'))->sole()->youtube_start_seconds)->toBe($seconds);
})->with([
    '時 0・分 59・秒 59' => [['youtube_start_hour' => 0, 'youtube_start_minute' => 59, 'youtube_start_second' => 59], null, 3599],
    '分 60' => [['youtube_start_minute' => 60], 'youtube_start_minute', null],
    '秒 60' => [['youtube_start_second' => 60], 'youtube_start_second', null],
    '時 -1' => [['youtube_start_hour' => -1], 'youtube_start_hour', null],
    'YouTube でない URL' => [['youtube' => 'https://example.com/watch?v=abcdefghijk'], 'youtube', null],
]);

it('TC-121-12: 洋酒の部分木では有名な銘柄が要り、部分木の銘柄を選べば通り、別の系統の銘柄は 422', function () {
    ['nodes' => $n, 'bacardi' => $bacardi, 'gordons' => $gordons] = categoryAdminTree();

    $this->postJson('/api/admin/categories', categoryAdminInput($n['ラム']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['brand_name', 'brand_name_eng', 'brand_strength', 'brand_description', 'brand_is_major', 'brand_is_like']);

    $this->putJson("/api/admin/categories/{$n['ラム']->id}", categoryAdminCurrentInput($n['ラム'], ['famous_brand_id' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['brand_name']);

    $this->putJson("/api/admin/categories/{$n['ダークラム']->id}", categoryAdminCurrentInput($n['ダークラム'], ['famous_brand_id' => $bacardi->id]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.famous_brand_id', ['有名な銘柄は、このノードとその下のノードの銘柄から選んでください']);
    $this->putJson("/api/admin/categories/{$n['ラム']->id}", categoryAdminCurrentInput($n['ラム'], ['famous_brand_id' => $gordons->id]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.famous_brand_id', ['有名な銘柄は、このノードとその下のノードの銘柄から選んでください']);
    expect($n['ラム']->refresh()->famous_brand_id)->toBe($bacardi->id);

    // 新しいノードの部分木には銘柄がまだ無いので、既存の銘柄は選べない(その場で作る)
    $this->postJson('/api/admin/categories', categoryAdminInput($n['ラム'], ['famous_brand_id' => $bacardi->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['famous_brand_id']);

    $this->putJson("/api/admin/categories/{$n['ラム']->id}", categoryAdminCurrentInput($n['ラム'], ['famous_brand_id' => $bacardi->id, 'name' => 'ラム酒']))
        ->assertOk()
        ->assertJsonPath('data.name', 'ラム酒')
        ->assertJsonPath('data.famous_brand_id', $bacardi->id);
});

it('TC-121-13: ソフトドリンク・その他の部分木では有名な銘柄は空でよく、銘柄を書き始めたら残りが要る', function (string $parent, array $brandInput, array $errors) {
    ['nodes' => $n] = categoryAdminTree();

    $response = $this->postJson('/api/admin/categories', categoryAdminInput($n[$parent], $brandInput));

    if ($errors === []) {
        $response->assertCreated();
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors($errors);
        // 書き始めた欄のほかに誤りが無い
        expect($response->json('errors'))->toHaveCount(count($errors));
    }
})->with([
    'その他・空' => ['その他', [], []],
    'ソフトドリンク・空' => ['ソフトドリンク', [], []],
    'その他の孫・空' => ['砂糖', [], []],
    'その他・名前だけ' => ['その他', ['brand_name' => 'グラニュー糖'], ['brand_name_eng', 'brand_strength', 'brand_description', 'brand_is_major', 'brand_is_like']],
    'その他・度数 0 だけ' => ['その他', ['brand_strength' => 0], ['brand_name', 'brand_name_eng', 'brand_description', 'brand_is_major', 'brand_is_like']],
    'その他・メジャーだけ' => ['その他', ['brand_is_major' => true], []],
]);

it('TC-121-14: ソフトドリンク・その他の部分木でも、銘柄をすべて書けばその場で作って有名な銘柄にする', function () {
    ['nodes' => $n] = categoryAdminTree();

    $response = $this->postJson('/api/admin/categories', categoryAdminInput($n['ソフトドリンク'], ['name' => 'トニック', ...categoryAdminBrandInput(['brand_name' => 'シュウェップス', 'brand_strength' => 0])]))
        ->assertCreated();

    $node = Category::query()->whereKey($response->json('data.id'))->sole();
    expect($node->famousBrand?->name)->toBe('シュウェップス')
        ->and($node->famousBrand?->category_id)->toBe($node->id);
});

it('TC-121-15: 根は役割を選んで作れ、役割は根にだけ入る。根に有名な銘柄は入れられない', function (array $input, int $status, ?string $error) {
    categoryAdminTree();

    $response = $this->postJson('/api/admin/categories', [...categoryAdminInput(null, ['name' => '新しい根']), ...$input]);

    $response->assertStatus($status);
    if ($error !== null) {
        $response->assertJsonValidationErrors([$error]);
    }
})->with([
    '根・役割なし' => [[], 201, null],
    '根・その他' => [['role' => 'other'], 201, null],
    '根・ソフトドリンク' => [['role' => 'soft_drink'], 201, null],
    '根・無い役割' => [['role' => 'liquor'], 422, 'role'],
    '根・有名な銘柄を作る' => [categoryAdminBrandInput(), 422, 'brand_name'],
]);

it('TC-121-16: 根でないノードに役割を入れると 422。根の役割は変えられ、変えると部分木を材料に使うカクテルの材料の数を数え直す', function () {
    ['nodes' => $n] = categoryAdminTree();
    $cocktail = categoryAdminCocktail($n['ジン'], [$n['ジン'], $n['砂糖']]);
    expect($cocktail->recipe_count)->toBe(1);

    $this->postJson('/api/admin/categories', categoryAdminInput($n['砂糖'], ['role' => 'other']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.role', ['役割は根にだけ入れられます']);

    $this->putJson("/api/admin/categories/{$n['その他']->id}", categoryAdminCurrentInput($n['その他'], ['role' => null]))
        ->assertOk()
        ->assertJsonPath('data.role', null);
    expect($cocktail->refresh()->recipe_count)->toBe(2);

    $this->putJson("/api/admin/categories/{$n['その他']->id}", categoryAdminCurrentInput($n['その他'], ['role' => 'other']))
        ->assertOk()
        ->assertJsonPath('data.role', 'other');
    expect($cocktail->refresh()->recipe_count)->toBe(1);
});

it('TC-121-17: 親を変えると部分木ごと移して兄弟の末尾に置く。自分・子孫の下、役割を持つ根をほかの下へは 422', function () {
    ['nodes' => $n] = categoryAdminTree();
    app(CategoryTree::class)->addNode($n['ジン'], Category::factory()->make(['name' => 'ドライジン', 'sort_order' => 4])->getAttributes());

    $this->putJson("/api/admin/categories/{$n['ダークラム']->id}", categoryAdminCurrentInput($n['ダークラム'], ['parent_id' => $n['ジン']->id, ...categoryAdminBrandInput()]))
        ->assertOk()
        ->assertJsonPath('data.parent_id', $n['ジン']->id);
    expect(categoryAdminPathNames($n['ダークラム']))->toBe(['洋酒', 'ジン', 'ダークラム'])
        ->and($n['ダークラム']->refresh()->sort_order)->toBe(5);

    $this->putJson("/api/admin/categories/{$n['ラム']->id}", categoryAdminCurrentInput($n['ラム'], ['parent_id' => $n['ホワイトラム']->id]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.parent_id', ['自分自身と、自分より下のノードは親にできません']);
    $this->putJson("/api/admin/categories/{$n['ラム']->id}", categoryAdminCurrentInput($n['ラム'], ['parent_id' => $n['ラム']->id]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.parent_id', ['自分自身と、自分より下のノードは親にできません']);
    $this->putJson("/api/admin/categories/{$n['その他']->id}", categoryAdminCurrentInput($n['その他'], ['parent_id' => $n['洋酒']->id, 'role' => null]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.parent_id', ['役割を持つ根は、ほかのノードの下へ移せません']);
    expect(categoryAdminPathNames($n['ラム']))->toBe(['洋酒', 'ラム'])
        ->and(categoryAdminPathNames($n['その他']))->toBe(['その他']);

    // 部分木を移したので、ダークラムの閉包表の行は、自分・ジン・洋酒からの 3 行
    expect(CategoryClosure::query()->where('descendant_id', $n['ダークラム']->id)->orderBy('depth')->pluck('ancestor_id')->all())
        ->toBe([$n['ダークラム']->id, $n['ジン']->id, $n['洋酒']->id]);
});

it('TC-121-18: 親を変えると祖先の有名な銘柄がその部分木の外になるなら 422 で、何も変えない', function () {
    ['nodes' => $n, 'bacardi' => $bacardi] = categoryAdminTree();

    // ホワイトラム(バカルディを持つ)をジンの下へ移すと、ラムの有名な銘柄(バカルディ)がラムの部分木の外になる
    $this->putJson("/api/admin/categories/{$n['ホワイトラム']->id}", categoryAdminCurrentInput($n['ホワイトラム'], ['parent_id' => $n['ジン']->id]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.parent_id', ['このノードの下の銘柄が「ラム」の有名な銘柄なので、そのノードの外へは移せません']);
    expect(categoryAdminPathNames($n['ホワイトラム']))->toBe(['洋酒', 'ラム', 'ホワイトラム']);

    // ラムごとなら、有名な銘柄も一緒に移るので通る
    $this->putJson("/api/admin/categories/{$n['ラム']->id}", categoryAdminCurrentInput($n['ラム'], ['parent_id' => $n['ジン']->id]))
        ->assertOk();
    expect(categoryAdminPathNames($n['ホワイトラム']))->toBe(['洋酒', 'ジン', 'ラム', 'ホワイトラム'])
        ->and($n['ラム']->refresh()->famous_brand_id)->toBe($bacardi->id);
});

it('TC-121-19: 画像を上げると保存し、差し替えると前のファイルを消し、remove_image で画像だけを消す。その場で作る銘柄にも画像を付けられる', function () {
    Storage::fake('public');
    ['nodes' => $n] = categoryAdminTree();
    $put = fn (array $input) => $this->put("/api/admin/categories/{$n['砂糖']->id}", categoryAdminCurrentInput($n['砂糖'], $input), ['Accept' => 'application/json']);

    $put(['image' => categoryAdminPng()])->assertOk();
    $first = (string) $n['砂糖']->refresh()->image;
    Storage::disk('public')->assertExists($first);

    $put(['image' => categoryAdminPng()])->assertOk();
    $second = (string) $n['砂糖']->refresh()->image;
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    $put(['remove_image' => '1'])->assertOk()->assertJsonPath('data.image', null);
    Storage::disk('public')->assertMissing($second);

    $response = $this->post('/api/admin/categories', [
        ...categoryAdminInput($n['ジン']),
        ...categoryAdminBrandInput(['brand_is_major' => 'true', 'brand_is_like' => 'false']),
        'brand_image' => categoryAdminPng(),
    ], ['Accept' => 'application/json'])->assertCreated();
    $brandImage = (string) Category::query()->whereKey($response->json('data.id'))->sole()->famousBrand?->image;
    Storage::disk('public')->assertExists($brandImage);
});

it('TC-121-20: YouTube を入れた・変えたときに公開日時を今にし、変えなければ残し、消したら空にする', function () {
    ['nodes' => $n] = categoryAdminTree();
    $put = fn (array $input) => $this->putJson("/api/admin/categories/{$n['砂糖']->id}", categoryAdminCurrentInput($n['砂糖'], $input))->assertOk();

    Carbon::setTestNow('2026-11-01 10:00:00');
    $put(['youtube' => 'abcdefghijk']);
    expect($n['砂糖']->refresh()->getRawOriginal('youtube_created_at'))->toBe('2026-11-01 10:00:00');

    Carbon::setTestNow('2026-11-02 10:00:00');
    $put(['youtube' => 'https://youtu.be/abcdefghijk']);
    expect($n['砂糖']->refresh()->getRawOriginal('youtube_created_at'))->toBe('2026-11-01 10:00:00');

    $put(['youtube' => 'zyxwvutsrqp']);
    expect($n['砂糖']->refresh()->getRawOriginal('youtube_created_at'))->toBe('2026-11-02 10:00:00');

    $put(['youtube' => null, 'youtube_start_second' => 5]);
    expect($n['砂糖']->refresh()->only(['youtube', 'youtube_created_at', 'youtube_start_seconds']))
        ->toBe(['youtube' => null, 'youtube_created_at' => null, 'youtube_start_seconds' => null]);
    Carbon::setTestNow();
});

it('TC-121-21: 説明の名前で書いた記法の名前が見つからないと 422', function () {
    ['nodes' => $n] = categoryAdminTree();

    $this->postJson('/api/admin/categories', categoryAdminInput($n['砂糖'], ['description' => '@c存在しないカクテル@の材料']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['description']);
});

it('TC-121-22: 子・銘柄・材料・ベース・アフィリエイトの無い葉は消せ、画像のファイルも消える', function () {
    Storage::fake('public');
    ['nodes' => $n] = categoryAdminTree();
    Storage::disk('public')->put('sugar.png', (string) base64_decode(CATEGORY_ADMIN_PNG, true));
    $n['砂糖']->update(['image' => 'sugar.png']);

    $this->deleteJson("/api/admin/categories/{$n['砂糖']->id}")->assertNoContent();

    expect(Category::query()->whereKey($n['砂糖']->id)->exists())->toBeFalse()
        ->and(CategoryClosure::query()->where('descendant_id', $n['砂糖']->id)->exists())->toBeFalse()
        ->and(categoryAdminPathNames($n['その他']))->toBe(['その他']);
    Storage::disk('public')->assertMissing('sugar.png');
});

it('TC-121-23: 消せないノードは 409 で、理由をすべて決まった順に返し、消さない', function (string $codes) {
    $blockers = explode(',', $codes);
    Storage::fake('public');
    ['nodes' => $n] = categoryAdminTree();
    $tree = app(CategoryTree::class);
    $leaf = $tree->addNode($n['砂糖'], Category::factory()->make(['name' => '粉糖', 'image' => 'leaf.png'])->getAttributes());
    Storage::disk('public')->put('leaf.png', 'x');

    foreach ($blockers as $blocker) {
        match ($blocker) {
            'children' => $tree->addNode($leaf, Category::factory()->make()->getAttributes()),
            'brands' => Brand::factory()->create(['category_id' => $leaf->id]),
            'ingredients' => categoryAdminCocktail($n['ジン'], [$leaf, $n['ジン']]),
            'base_cocktails' => categoryAdminCocktail($leaf, [$n['ジン'], $n['ラム']]),
            'affiliates' => Affiliate::factory()->create(['category_id' => $leaf->id]),
            default => throw new InvalidArgumentException("知らない理由: {$blocker}"),
        };
    }
    $messages = [
        'children' => '子のノードがあります',
        'brands' => 'このノードの銘柄があります',
        'ingredients' => 'カクテルの材料に使われています',
        'base_cocktails' => 'カクテルのベースに使われています',
        'affiliates' => 'このノードのアフィリエイトがあります',
    ];
    $expected = array_map(fn (string $code): array => ['code' => $code, 'message' => $messages[$code]], array_values(array_intersect(array_keys($messages), $blockers)));

    $this->deleteJson("/api/admin/categories/{$leaf->id}")
        ->assertStatus(409)
        ->assertExactJson(['message' => 'このノードは消せません', 'reasons' => $expected]);
    expect(Category::query()->whereKey($leaf->id)->exists())->toBeTrue();
    Storage::disk('public')->assertExists('leaf.png');
    $this->getJson("/api/admin/categories/{$leaf->id}")->assertJsonPath('data.delete_blockers', $expected);
})->with([
    '子がある' => ['children'],
    '銘柄がある' => ['brands'],
    '材料に使われている' => ['ingredients'],
    'ベースに使われている' => ['base_cocktails'],
    'アフィリエイトがある' => ['affiliates'],
    '全部(順を逆にして作る)' => ['affiliates,base_cocktails,ingredients,brands,children'],
]);

it('TC-121-24: 無いノードの更新・削除は 404', function (string $method) {
    categoryAdminTree();

    $this->json($method, '/api/admin/categories/999999', categoryAdminInput(null, ['name' => '新しい根']))->assertNotFound();
})->with(['PUT', 'DELETE']);
