<?php

use App\Models\Category;
use App\Models\Cocktail;
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

// 管理 API のカクテルの基本情報の取得・新規・更新と、フォームの選択肢(#291)。旧の DetailEditController@cocktail・
// EditCocktailValidate・FactoryHelper::generateFromRequest(材料を除く)と、SearchController@getListForRecipe の置き換え。
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
 * └ ジン
 *
 * 技法: ビルド・シェイク / グラス(分類「グラス」): タンブラー・カクテルグラス / グラスでない用語: シェーカー / 氷: クラッシュド・キューブド
 *
 * @return array{nodes: array<string, Category>, methods: list<Method>, glasses: list<Dictionary>, notGlass: Dictionary, ices: list<Ice>}
 */
function cocktailAdminFixture(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name): Category => $tree->addNode(
        $parent,
        Category::factory()->make([
            'name' => $name,
            'name_search' => NameNormalizer::normalize($name),
            'name_eng' => $name.'-eng',
        ])->getAttributes(),
    );

    $n = [];
    $n['洋酒'] = $add(null, '洋酒');
    $n['ラム'] = $add($n['洋酒'], 'ラム');
    $n['ホワイトラム'] = $add($n['ラム'], 'ホワイトラム');
    $n['ジン'] = $add($n['洋酒'], 'ジン');

    $glassCategory = DictionaryCategory::factory()->create(['id' => DictionaryCategory::GLASS_ID, 'name' => 'グラス', 'name_eng' => 'Glass']);
    $toolCategory = DictionaryCategory::factory()->create(['id' => DictionaryCategory::GLASS_ID + 1, 'name' => '道具', 'name_eng' => 'Tool']);

    return [
        'nodes' => $n,
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
 * 正しい入力。$overrides で項目を差し替える(null を入れた項目は送らない)。
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

it('TC-291-03: 新規は 201 で保存し、名前の検索用の形と、説明・補足の記法への変換を入れる。度数・材料の数は 0', function () {
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
        ->assertJsonPath('data.strength', 0);

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
        'recipe_count' => 0,
        'recipe_signature' => null,
        'youtube' => null,
        'youtube_created_at' => null,
        'youtube_start_seconds' => null,
        'image' => null,
        'image_txt' => '写真',
        'image_url' => 'https://example.com/a',
    ])->and($cocktail->strength())->toBe(0.0);
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

it('TC-291-13: 更新は基本情報だけを書き換え、材料と、材料から作る度数・材料の数・材料の組のハッシュは変えない。無い ID は 404', function () {
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
            'cocktail_id' => $cocktail->id, 'category_id' => $node->id, 'position' => $position + 1, 'is_optional' => false,
        ]);
    }
    app(RecipeCounter::class)->recount([$cocktail->id]);
    app(RecipeSignature::class)->rewrite([$cocktail->id]);
    $before = $cocktail->refresh()->only(['volume', 'recipe_count', 'recipe_signature']);
    $ingredientsBefore = CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->orderBy('position')->get(['category_id', 'amount'])->toArray();

    $this->putJson("/api/admin/cocktails/{$cocktail->id}", cocktailAdminInput($f, [
        'name' => '新しい名前',
        'base_category_id' => $n['ジン']->id,
        'is_draft' => true,
    ]))->assertOk()
        ->assertJsonPath('data.id', $cocktail->id)
        ->assertJsonPath('data.name', '新しい名前')
        ->assertJsonPath('data.is_draft', true)
        ->assertJsonPath('data.strength', 12.5);

    $cocktail->refresh();
    expect($cocktail->only(['name', 'base_category_id', 'is_draft']))->toBe(['name' => '新しい名前', 'base_category_id' => $n['ジン']->id, 'is_draft' => true])
        ->and($cocktail->only(['volume', 'recipe_count', 'recipe_signature']))->toBe($before)
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
