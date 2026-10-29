<?php

use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Models\User;
use App\Services\CategoryTree;
use App\Services\RecipeCounter;
use App\Services\RecipeSignature;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// 管理 API の銘柄の一覧・取得・新規・更新(#314)。旧の DetailEditController@brand・EditBrandValidate と、
// 一覧(getBrandListWithCategory と ListComponent.vue)の置き換え。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/** 1x1 の PNG(画像の種類は中身から推定させるので、本物の画像のバイト列にする) */
const BRAND_ADMIN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/**
 * テストに使う木。
 *
 * 洋酒(根)
 * ├ ラム ── 有名な銘柄: バカルディ
 * │  ├ ホワイトラム ── 銘柄: バカルディ
 * │  └ ダークラム
 * └ ジン
 * その他(根。other)
 * └ 砂糖
 *
 * @return array{nodes: array<string, Category>, bacardi: Brand}
 */
function brandAdminTree(): array
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

    $bacardi = Brand::factory()->create([
        'category_id' => $n['ホワイトラム']->id,
        'name' => 'バカルディ',
        'name_search' => NameNormalizer::normalize('バカルディ'),
        'name_eng' => 'Bacardi',
        'volume' => 40,
    ]);
    $n['ラム']->update(['famous_brand_id' => $bacardi->id]);

    return ['nodes' => $n, 'bacardi' => $bacardi];
}

/**
 * 正しい入力。$overrides で項目を差し替える(null を入れた項目は送らない)。
 *
 * @param  array<array-key, mixed>  $overrides  項目の名前 → 値(データセットから受けるので、鍵の型は絞らない)
 * @return array<array-key, mixed>
 */
function brandAdminInput(Category $category, array $overrides = []): array
{
    $input = array_merge([
        'category_id' => $category->id,
        'name' => 'ハバナクラブ',
        'name_eng' => 'Havana Club',
        'strength' => 40,
        'description' => 'キューバの酒',
        'is_major' => true,
        'is_like' => false,
    ], $overrides);

    return array_filter($input, fn (mixed $value): bool => $value !== null);
}

/**
 * 材料を並べてカクテルを作り、材料の数を数えておく。材料は銘柄かノード。
 *
 * @param  list<Brand|Category>  $items
 */
function brandAdminCocktail(array $items): Cocktail
{
    $cocktail = Cocktail::factory()->create();
    foreach ($items as $position => $item) {
        $factory = CocktailIngredient::factory();
        $factory = $item instanceof Brand ? $factory->forBrand($item) : $factory->state(['category_id' => $item->id]);
        $factory->create(['cocktail_id' => $cocktail->id, 'position' => $position + 1, 'is_optional' => false]);
    }
    app(RecipeCounter::class)->recount([$cocktail->id]);

    return $cocktail->refresh();
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('TC-314-01: ログインしていないと、一覧・取得・新規・更新は 401', function (string $method, string $uri) {
    auth()->logout();
    $brand = Brand::factory()->create();

    $this->json($method, str_replace('{id}', (string) $brand->id, $uri))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
})->with([
    '一覧' => ['GET', '/api/admin/brands'],
    '取得' => ['GET', '/api/admin/brands/{id}'],
    '新規' => ['POST', '/api/admin/brands'],
    '更新' => ['PUT', '/api/admin/brands/{id}'],
]);

it('TC-314-02: 一覧の 1 行は、名前・英名・根を除いた所属のパンくずと、有名な銘柄として使われているか', function () {
    ['nodes' => $n, 'bacardi' => $bacardi] = brandAdminTree();
    $gordons = Brand::factory()->create(['category_id' => $n['ジン']->id, 'name' => 'ゴードン', 'name_eng' => "Gordon's"]);

    $this->getJson('/api/admin/brands')
        ->assertOk()
        ->assertJsonPath('data', [
            [
                'id' => $gordons->id,
                'name' => 'ゴードン',
                'name_eng' => "Gordon's",
                'category_path' => [
                    ['id' => $n['ジン']->id, 'name' => 'ジン', 'name_eng' => 'ジン-eng', 'is_famous_brand' => false],
                ],
                'is_famous_brand' => false,
            ],
            [
                'id' => $bacardi->id,
                'name' => 'バカルディ',
                'name_eng' => 'Bacardi',
                'category_path' => [
                    ['id' => $n['ラム']->id, 'name' => 'ラム', 'name_eng' => 'ラム-eng', 'is_famous_brand' => true],
                    ['id' => $n['ホワイトラム']->id, 'name' => 'ホワイトラム', 'name_eng' => 'ホワイトラム-eng', 'is_famous_brand' => false],
                ],
                'is_famous_brand' => true,
            ],
        ])
        ->assertJsonPath('meta.total', 2);
});

it('TC-314-03: 一覧は名前の昇順(カタカナとひらがな・大文字と小文字をそろえ、同じ名前は ID の順)', function () {
    $node = Category::factory()->create();
    $ids = [];
    foreach (['ジン', 'かしす', 'abd', 'カシス', 'ABC', 'あいう', 'カシス'] as $name) {
        $ids[] = Brand::factory()->create(['category_id' => $node->id, 'name' => $name])->id;
    }

    $this->getJson('/api/admin/brands')
        ->assertOk()
        // ABC → abd → あいう → かしす・カシス・カシス(そろえると同じなので ID の順)→ ジン
        ->assertJsonPath('data.*.id', [$ids[4], $ids[2], $ids[5], $ids[1], $ids[3], $ids[6], $ids[0]]);
});

it('TC-314-04: q は名前か英名のどこかに含むものに絞る', function (string $q, array $expectedNames) {
    $node = Category::factory()->create();
    Brand::factory()->create(['category_id' => $node->id, 'name' => 'カシス', 'name_eng' => 'Cassis']);
    Brand::factory()->create(['category_id' => $node->id, 'name' => 'ラム酒', 'name_eng' => 'Rum']);
    Brand::factory()->create(['category_id' => $node->id, 'name' => 'ＡＢＣ', 'name_eng' => 'abc']);

    $this->getJson('/api/admin/brands?q='.urlencode($q))
        ->assertOk()
        ->assertJsonPath('data.*.name', $expectedNames);
})->with([
    '名前の途中(ひらがなでカタカナに当たる)' => ['しす', ['カシス']],
    '名前の途中(漢字)' => ['酒', ['ラム酒']],
    '英名の途中(大文字・小文字を区別しない)' => ['ASS', ['カシス']],
    '全角の英数字を半角にそろえる' => ['BC', ['ＡＢＣ']],
    '当たらない' => ['zzz', []],
]);

it('TC-314-05: 1 ページは既定で 50 件、per_page は 1〜50', function () {
    $node = Category::factory()->create();
    Brand::factory()->count(51)->sequence(...array_map(fn (int $i): array => ['name' => sprintf('銘柄%02d', $i)], range(0, 50)))->create(['category_id' => $node->id]);

    $this->getJson('/api/admin/brands')
        ->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('meta.total', 51)
        ->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/admin/brands?page=2')->assertOk()->assertJsonPath('data.*.name', ['銘柄50']);
    $this->getJson('/api/admin/brands?per_page=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/admin/brands?per_page=50')->assertOk()->assertJsonCount(50, 'data');
    $this->getJson('/api/admin/brands?per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->getJson('/api/admin/brands?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('TC-314-06: 取得は、説明に指す先の名前を添え、開始位置を時・分・秒に分けて返す。無い ID は 404', function () {
    ['nodes' => $n, 'bacardi' => $bacardi] = brandAdminTree();
    $bacardi->update([
        'description' => "@n{$n['ラム']->id}@の銘柄",
        'youtube' => 'abcdefghijk',
        'youtube_start_seconds' => 3723,
        'image' => 'x.png',
        'image_txt' => '公式',
        'image_url' => 'https://example.com/',
        'is_like' => true,
    ]);

    $this->getJson("/api/admin/brands/{$bacardi->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $bacardi->id)
        ->assertJsonPath('data.category_id', $n['ホワイトラム']->id)
        ->assertJsonPath('data.category_path.*.name', ['ラム', 'ホワイトラム'])
        ->assertJsonPath('data.is_famous_brand', true)
        ->assertJsonPath('data.strength', 40)
        ->assertJsonPath('data.description', "@n{$n['ラム']->id}@{!ラム!}の銘柄")
        ->assertJsonPath('data.youtube', 'abcdefghijk')
        ->assertJsonPath('data.youtube_start_hour', 1)
        ->assertJsonPath('data.youtube_start_minute', 2)
        ->assertJsonPath('data.youtube_start_second', 3)
        ->assertJsonPath('data.image', 'x.png')
        ->assertJsonPath('data.image_txt', '公式')
        ->assertJsonPath('data.image_url', 'https://example.com/')
        ->assertJsonPath('data.is_major', false)
        ->assertJsonPath('data.is_like', true);

    $this->getJson('/api/admin/brands/'.($bacardi->id + 1000))->assertNotFound();
});

it('TC-314-07: 新規は 201 で保存し、名前の検索用の形と、説明の記法への変換を入れる', function () {
    ['nodes' => $n] = brandAdminTree();

    $response = $this->postJson('/api/admin/brands', brandAdminInput($n['ダークラム'], [
        'description' => 'ラムの一種',
        'strength' => '37.5',
    ]))->assertCreated()
        ->assertJsonPath('data.name', 'ハバナクラブ')
        ->assertJsonPath('data.category_path.*.name', ['ラム', 'ダークラム'])
        ->assertJsonPath('warnings', []);

    $brand = Brand::query()->whereKey($response->json('data.id'))->sole();
    expect($brand->only(['category_id', 'name', 'name_search', 'name_eng', 'description', 'is_major', 'is_like', 'youtube', 'youtube_start_seconds', 'image']))
        ->toBe([
            'category_id' => $n['ダークラム']->id,
            'name' => 'ハバナクラブ',
            'name_search' => NameNormalizer::normalize('ハバナクラブ'),
            'name_eng' => 'Havana Club',
            'description' => "@n{$n['ラム']->id}@の一種",
            'is_major' => true,
            'is_like' => false,
            'youtube' => null,
            'youtube_start_seconds' => null,
            'image' => null,
        ])
        ->and((float) $brand->volume)->toBe(37.5);
});

it('TC-314-21: 画像つき(multipart)で is_major・is_like が "true"・"false" の文字列で届いても受ける', function () {
    ['nodes' => $n] = brandAdminTree();

    $response = $this->post('/api/admin/brands', brandAdminInput($n['ダークラム'], [
        'is_major' => 'true',
        'is_like' => 'false',
    ]), ['Accept' => 'application/json'])->assertCreated();

    $brand = Brand::query()->whereKey($response->json('data.id'))->sole();
    expect($brand->is_major)->toBeTrue()
        ->and($brand->is_like)->toBeFalse();
});

it('TC-314-08: 必須の項目が無いと 422', function (string $field) {
    ['nodes' => $n] = brandAdminTree();

    $this->postJson('/api/admin/brands', brandAdminInput($n['ラム'], [$field => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
    $this->postJson('/api/admin/brands', brandAdminInput($n['ラム'], [$field => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
    expect(Brand::query()->count())->toBe(1);
})->with(['category_id', 'name', 'name_eng', 'strength', 'description', 'is_major', 'is_like']);

it('TC-314-09: 度数は 0〜100 の数', function (mixed $strength, bool $ok) {
    ['nodes' => $n] = brandAdminTree();

    $response = $this->postJson('/api/admin/brands', brandAdminInput($n['ラム'], ['strength' => $strength]));

    $ok ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors('strength');
})->with([
    '数でない' => ['abc', false],
    '0' => [0, true],
    '100' => [100, true],
    '-0.1' => [-0.1, false],
    '100.1' => [100.1, false],
]);

it('TC-314-10: 所属は実在する根でないノード', function () {
    ['nodes' => $n] = brandAdminTree();

    $this->postJson('/api/admin/brands', brandAdminInput($n['洋酒']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');
    $this->postJson('/api/admin/brands', brandAdminInput($n['洋酒'], ['category_id' => $n['砂糖']->id + 1000]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');
});

it('TC-314-11: 名前・英名は 255 文字まで', function (string $field) {
    ['nodes' => $n] = brandAdminTree();

    $this->postJson('/api/admin/brands', brandAdminInput($n['ラム'], [$field => str_repeat('あ', 255)]))
        ->assertCreated();
    $this->postJson('/api/admin/brands', brandAdminInput($n['ラム'], [$field => str_repeat('あ', 256)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with(['name', 'name_eng']);

it('TC-314-12: 名前・英名がほかの銘柄と同じなら、保存したうえで警告を返す', function () {
    ['nodes' => $n, 'bacardi' => $bacardi] = brandAdminTree();

    $this->postJson('/api/admin/brands', brandAdminInput($n['ラム'], ['name' => 'バカルディ', 'name_eng' => 'Other']))
        ->assertCreated()
        ->assertJsonPath('warnings', [['field' => 'name', 'message' => '同じ名前が存在しています']]);
    $this->postJson('/api/admin/brands', brandAdminInput($n['ラム'], ['name' => '別の名前', 'name_eng' => 'Bacardi']))
        ->assertCreated()
        ->assertJsonPath('warnings', [['field' => 'name_eng', 'message' => '同じ名前が存在しています']]);
    expect(Brand::query()->count())->toBe(3);

    // 自分自身とは比べない
    $this->putJson("/api/admin/brands/{$bacardi->id}", brandAdminInput($n['ホワイトラム'], ['name' => '新しい名前', 'name_eng' => 'Bacardi Superior']))
        ->assertOk()
        ->assertJsonPath('warnings', []);
});

it('TC-314-13: YouTube は URL か動画 ID で受け、開始位置は時 0 から、分・秒は 0〜59', function (array $input, ?string $error, ?string $videoId, ?int $seconds) {
    ['nodes' => $n] = brandAdminTree();

    $response = $this->postJson('/api/admin/brands', brandAdminInput($n['ラム'], $input));

    if ($error !== null) {
        $response->assertUnprocessable()->assertJsonValidationErrors($error);

        return;
    }
    $response->assertCreated();
    $brand = Brand::query()->whereKey($response->json('data.id'))->sole();
    expect($brand->youtube)->toBe($videoId)->and($brand->youtube_start_seconds)->toBe($seconds);
})->with([
    'URL から動画 ID を取る' => [['youtube' => 'https://www.youtube.com/watch?v=abcdefghijk&t=1'], null, 'abcdefghijk', null],
    '短い URL' => [['youtube' => 'https://youtu.be/abcdefghijk'], null, 'abcdefghijk', null],
    '動画 ID' => [['youtube' => 'abcdefghijk'], null, 'abcdefghijk', null],
    'YouTube でない URL' => [['youtube' => 'https://example.com/watch?v=abcdefghijk'], 'youtube', null, null],
    '動画 ID の形でない値(引用符・空白・タグ)' => [['youtube' => 'abc"def <b>'], 'youtube', null, null],
    '動画 ID に使える記号(_ と -)' => [['youtube' => 'ab_cd-efghij'], null, 'ab_cd-efghij', null],
    '動画 ID の読めない URL' => [['youtube' => 'https://www.youtube.com/'], 'youtube', null, null],
    '時 0・分 59・秒 59' => [['youtube' => 'abcdefghijk', 'youtube_start_hour' => 0, 'youtube_start_minute' => 59, 'youtube_start_second' => 59], null, 'abcdefghijk', 3599],
    '分だけ(空の欄は 0)' => [['youtube' => 'abcdefghijk', 'youtube_start_minute' => 1], null, 'abcdefghijk', 60],
    '分 60' => [['youtube' => 'abcdefghijk', 'youtube_start_minute' => 60], 'youtube_start_minute', null, null],
    '秒 60' => [['youtube' => 'abcdefghijk', 'youtube_start_second' => 60], 'youtube_start_second', null, null],
    '時 -1' => [['youtube' => 'abcdefghijk', 'youtube_start_hour' => -1], 'youtube_start_hour', null, null],
    '動画が無ければ開始位置は持たない' => [['youtube_start_second' => 30], null, null, null],
]);

it('TC-314-14: YouTube を入れた・変えたときに公開日時を今にし、変えなければ残し、消したら空にする', function () {
    ['nodes' => $n, 'bacardi' => $bacardi] = brandAdminTree();
    $put = fn (array $input) => $this->putJson("/api/admin/brands/{$bacardi->id}", brandAdminInput($n['ホワイトラム'], $input))->assertOk();

    Carbon::setTestNow('2026-10-01 10:00:00');
    $put(['youtube' => 'https://www.youtube.com/watch?v=abcdefghijk']);
    expect($bacardi->refresh()->getRawOriginal('youtube_created_at'))->toBe('2026-10-01 10:00:00');

    Carbon::setTestNow('2026-10-02 10:00:00');
    $put(['youtube' => 'abcdefghijk', 'youtube_start_second' => 5]);
    expect($bacardi->refresh()->getRawOriginal('youtube_created_at'))->toBe('2026-10-01 10:00:00');

    $put(['youtube' => 'zyxwvutsrqp', 'youtube_start_second' => 5]);
    expect($bacardi->refresh()->getRawOriginal('youtube_created_at'))->toBe('2026-10-02 10:00:00');

    $put(['youtube_start_second' => 5]);
    expect($bacardi->refresh()->only(['youtube', 'youtube_created_at', 'youtube_start_seconds']))
        ->toBe(['youtube' => null, 'youtube_created_at' => null, 'youtube_start_seconds' => null]);
});

it('TC-314-15: 画像を上げると保存し、差し替えると前のファイルを消し、remove_image で画像だけを消す', function () {
    Storage::fake('public');
    ['nodes' => $n, 'bacardi' => $bacardi] = brandAdminTree();
    $png = function (): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'brand-image-') ?: throw new RuntimeException('一時ファイルを作れない');
        file_put_contents($path, base64_decode(BRAND_ADMIN_PNG, true));

        return new UploadedFile($path, 'a.png', null, null, true);
    };
    $put = fn (array $input) => $this->put("/api/admin/brands/{$bacardi->id}", brandAdminInput($n['ホワイトラム'], $input), ['Accept' => 'application/json']);

    $put(['image' => $png()])->assertOk();
    $first = $bacardi->refresh()->image;
    expect($first)->not->toBeNull();
    Storage::disk('public')->assertExists((string) $first);

    $put(['image' => $png()])->assertOk();
    $second = $bacardi->refresh()->image;
    Storage::disk('public')->assertMissing((string) $first);
    Storage::disk('public')->assertExists((string) $second);

    $put(['remove_image' => '1'])->assertOk()->assertJsonPath('data.image', null);
    Storage::disk('public')->assertMissing((string) $second);
});

it('TC-314-20: 外側のトランザクションの中で保存し、外側がロールバックされたら、置いた画像を消し、前の画像は残す', function () {
    Storage::fake('public');
    ['nodes' => $n, 'bacardi' => $bacardi] = brandAdminTree();
    Storage::disk('public')->put('old.png', (string) base64_decode(BRAND_ADMIN_PNG, true));
    $bacardi->forceFill(['image' => 'old.png'])->save();
    $path = tempnam(sys_get_temp_dir(), 'brand-image-') ?: throw new RuntimeException('一時ファイルを作れない');
    file_put_contents($path, base64_decode(BRAND_ADMIN_PNG, true));
    $input = brandAdminInput($n['ホワイトラム'], ['image' => new UploadedFile($path, 'a.png', null, null, true)]);

    $placed = null;
    try {
        DB::transaction(function () use ($bacardi, $input, &$placed) {
            $this->put("/api/admin/brands/{$bacardi->id}", $input, ['Accept' => 'application/json'])->assertOk();
            $placed = $bacardi->refresh()->image;
            expect($placed)->not->toBe('old.png');
            Storage::disk('public')->assertExists((string) $placed);

            throw new RuntimeException('外側を巻き戻す');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('外側を巻き戻す');
    }

    expect($placed)->toBeString()
        ->and($bacardi->refresh()->image)->toBe('old.png')
        ->and(Storage::disk('public')->allFiles())->toBe(['old.png']);
});

it('TC-314-16: 所属を変えると、その銘柄を使う材料・アフィリエイトのノードがそろい、材料の数が作り直される', function () {
    ['nodes' => $n] = brandAdminTree();
    $brand = Brand::factory()->create(['category_id' => $n['ジン']->id]);
    $affiliate = Affiliate::factory()->create(['category_id' => $n['ジン']->id, 'brand_id' => $brand->id]);
    $withBrand = brandAdminCocktail([$brand, $n['ラム']]);
    $withoutBrand = brandAdminCocktail([$n['ジン'], $n['ラム']]);
    expect($withBrand->recipe_count)->toBe(2);

    // 「その他」の根の下へ移すと、材料の数に数えなくなる
    $this->putJson("/api/admin/brands/{$brand->id}", brandAdminInput($n['砂糖'], ['name' => $brand->name, 'name_eng' => $brand->name_eng]))
        ->assertOk()
        ->assertJsonPath('data.category_id', $n['砂糖']->id);

    expect(CocktailIngredient::query()->where('brand_id', $brand->id)->pluck('category_id')->all())->toBe([$n['砂糖']->id])
        ->and($affiliate->refresh()->category_id)->toBe($n['砂糖']->id)
        ->and($withBrand->refresh()->recipe_count)->toBe(1)
        ->and($withoutBrand->refresh()->recipe_count)->toBe(2)
        ->and(CocktailIngredient::query()->where('cocktail_id', $withoutBrand->id)->pluck('category_id')->all())
        ->toBe([$n['ジン']->id, $n['ラム']->id]);
});

it('TC-82-27: 所属を変えると、その銘柄を使うカクテルの recipe_signature が新しいノードで作り直される(使わないカクテルは変わらない)', function () {
    ['nodes' => $n] = brandAdminTree();
    $brand = Brand::factory()->create(['category_id' => $n['ジン']->id]);
    $withBrand = brandAdminCocktail([$brand, $n['ラム']]);
    $withoutBrand = brandAdminCocktail([$n['ジン'], $n['ラム']]);
    app(RecipeSignature::class)->rewrite([$withBrand->id, $withoutBrand->id]);
    $before = [$withBrand->refresh()->recipe_signature, $withoutBrand->refresh()->recipe_signature];

    $this->putJson("/api/admin/brands/{$brand->id}", brandAdminInput($n['砂糖'], ['name' => $brand->name, 'name_eng' => $brand->name_eng]))
        ->assertOk();

    expect($withBrand->refresh()->recipe_signature)->not->toBe($before[0])
        ->toBe(RecipeSignature::of([
            ['category_id' => $n['砂糖']->id, 'brand_id' => $brand->id, 'amount' => '30ml', 'is_optional' => false],
            ['category_id' => $n['ラム']->id, 'brand_id' => null, 'amount' => '30ml', 'is_optional' => false],
        ]))
        ->and($withoutBrand->refresh()->recipe_signature)->toBe($before[1]);
});

it('TC-314-17: 有名な銘柄は、そのノードの部分木の中へは移せ、外へ移すと 422 で理由を返す', function () {
    ['nodes' => $n, 'bacardi' => $bacardi] = brandAdminTree();
    $cocktail = brandAdminCocktail([$bacardi]);

    $this->putJson("/api/admin/brands/{$bacardi->id}", brandAdminInput($n['ジン'], ['name' => 'バカルディ', 'name_eng' => 'Bacardi']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id' => '「ラム」の有名な銘柄なので、そのノードより下のノードにしか移せません']);
    expect($bacardi->refresh()->category_id)->toBe($n['ホワイトラム']->id)
        ->and(CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->value('category_id'))->toBe($n['ホワイトラム']->id);

    $this->putJson("/api/admin/brands/{$bacardi->id}", brandAdminInput($n['ダークラム'], ['name' => 'バカルディ', 'name_eng' => 'Bacardi']))
        ->assertOk();
    $this->putJson("/api/admin/brands/{$bacardi->id}", brandAdminInput($n['ラム'], ['name' => 'バカルディ', 'name_eng' => 'Bacardi']))
        ->assertOk();
    expect(CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->value('category_id'))->toBe($n['ラム']->id);
});

it('TC-314-18: 所属を変えない更新は、有名な銘柄のノードの部分木の外にある銘柄でも通る', function () {
    ['nodes' => $n, 'bacardi' => $bacardi] = brandAdminTree();
    // 移行した旧データには、有名な銘柄がノードの部分木の外にあるものがある(docs/decisions/strength.md のミドル 58)
    $n['ジン']->update(['famous_brand_id' => $bacardi->id]);

    $this->putJson("/api/admin/brands/{$bacardi->id}", brandAdminInput($n['ホワイトラム'], ['name' => 'バカルディ', 'name_eng' => 'Bacardi', 'strength' => 37.5]))
        ->assertOk()
        ->assertJsonPath('data.strength', 37.5);
});

it('TC-314-19: 無い銘柄の更新は 404', function () {
    ['nodes' => $n] = brandAdminTree();

    $this->putJson('/api/admin/brands/999999', brandAdminInput($n['ラム']))->assertNotFound();
});
