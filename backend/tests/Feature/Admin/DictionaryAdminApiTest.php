<?php

use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use App\Models\Method;
use App\Models\User;
use App\Services\CategoryTree;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// 管理 API の用語辞典の一覧・取得・新規・更新・削除(#315)。旧は用語の保存を止めていた(旧 routes/web.php の
// 「面倒なのでサポート切り」)ので、一覧のほかは旧に無い。

uses(RefreshDatabase::class)->group('db');

/** 1x1 の PNG(画像の種類は中身から推定させるので、本物の画像のバイト列にする) */
const DICTIONARY_ADMIN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/**
 * 用語を 1 つ作る。名前の検索用の形は名前から作る。
 *
 * @param  array<string, mixed>  $attributes
 */
function dictionaryAdminTerm(DictionaryCategory $category, string $name, array $attributes = []): Dictionary
{
    return Dictionary::factory()->create([
        'dictionary_category_id' => $category->id,
        'name' => $name,
        'name_search' => NameNormalizer::normalize($name),
        'name_eng' => $name.'-eng',
        ...$attributes,
    ]);
}

/**
 * 正しい入力。$overrides で項目を差し替える(null を入れた項目は送らない)。
 *
 * @param  array<array-key, mixed>  $overrides  項目の名前 → 値(データセットから受けるので、鍵の型は絞らない)
 * @return array<array-key, mixed>
 */
function dictionaryAdminInput(DictionaryCategory $category, array $overrides = []): array
{
    $input = array_merge([
        'dictionary_category_id' => $category->id,
        'name' => 'シェイク',
        'name_eng' => 'Shake',
        'description' => 'シェイカーで振る',
    ], $overrides);

    return array_filter($input, fn (mixed $value): bool => $value !== null);
}

/**
 * 中身が PNG の、上げるファイル。
 */
function dictionaryAdminPng(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'dictionary-image-') ?: throw new RuntimeException('一時ファイルを作れない');
    file_put_contents($path, base64_decode(DICTIONARY_ADMIN_PNG, true));

    return new UploadedFile($path, 'a.png', null, null, true);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->category = DictionaryCategory::factory()->create(['name' => '製法', 'name_eng' => 'Method']);
});

it('TC-315-01: ログインしていないと、一覧・取得・新規・更新・削除は 401 で、行は変わらない', function (string $method, string $uri) {
    $term = dictionaryAdminTerm($this->category, 'ステア');
    auth()->logout();

    $this->json($method, str_replace('{id}', (string) $term->id, $uri), dictionaryAdminInput($this->category))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
    expect(Dictionary::query()->pluck('name')->all())->toBe(['ステア']);
})->with([
    '一覧' => ['GET', '/api/admin/dictionaries'],
    '取得' => ['GET', '/api/admin/dictionaries/{id}'],
    '新規' => ['POST', '/api/admin/dictionaries'],
    '更新' => ['PUT', '/api/admin/dictionaries/{id}'],
    '削除' => ['DELETE', '/api/admin/dictionaries/{id}'],
]);

it('TC-315-02: 一覧は分類を ID の順に返し、用語の無い分類も空の並びで返す。用語は ID・名前・英名', function () {
    $glass = DictionaryCategory::factory()->create(['name' => 'グラス', 'name_eng' => 'Glass']);
    $empty = DictionaryCategory::factory()->create(['name' => '器具', 'name_eng' => 'Tool']);
    $stir = dictionaryAdminTerm($this->category, 'ステア', ['description' => '混ぜる', 'youtube' => 'abcdefghijk']);
    $cocktailGlass = dictionaryAdminTerm($glass, 'カクテルグラス');

    $this->getJson('/api/admin/dictionaries')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['id' => $this->category->id, 'name' => '製法', 'name_eng' => 'Method', 'dictionaries' => [
                ['id' => $stir->id, 'name' => 'ステア', 'name_eng' => 'ステア-eng'],
            ]],
            ['id' => $glass->id, 'name' => 'グラス', 'name_eng' => 'Glass', 'dictionaries' => [
                ['id' => $cocktailGlass->id, 'name' => 'カクテルグラス', 'name_eng' => 'カクテルグラス-eng'],
            ]],
            ['id' => $empty->id, 'name' => '器具', 'name_eng' => 'Tool', 'dictionaries' => []],
        ]]);
});

it('TC-315-03: 一覧の用語は、カタカナとひらがな・全角と半角の英数字をそろえた名前の順(大文字と小文字は区別し、同じ名前は ID の順)', function () {
    // ひらがなにそろえると「しぇいく」<「すてあ」。「ビルド」は「びるど」。英字は大文字が先(A < a)で、全角は半角にそろう
    foreach (['ビルド', 'すてあ', 'ｂ', 'シェイク', 'a', 'Ｂ', 'ステア'] as $name) {
        dictionaryAdminTerm($this->category, $name);
    }

    $this->getJson('/api/admin/dictionaries')
        ->assertOk()
        ->assertJsonPath('data.0.dictionaries.*.name', ['Ｂ', 'a', 'ｂ', 'シェイク', 'すてあ', 'ステア', 'ビルド']);
});

it('TC-315-04: 取得は、説明に指す先の名前を添え、開始位置を時・分・秒に分けて返す。無い ID は 404', function () {
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, Category::factory()->make(['name' => '洋酒', 'name_search' => '洋酒'])->getAttributes());
    $rum = $tree->addNode($root, Category::factory()->make(['name' => 'ラム', 'name_search' => 'ラム'])->getAttributes());
    $term = dictionaryAdminTerm($this->category, 'シェイク', [
        'name_eng' => 'Shake',
        'description' => "@n{$rum->id}@を振る",
        'youtube' => 'abcdefghijk',
        'youtube_start_seconds' => 3723,
        'image' => 'x.png',
        'image_txt' => '出典',
        'image_url' => 'https://example.com/',
    ]);

    $this->getJson("/api/admin/dictionaries/{$term->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $term->id)
        ->assertJsonPath('data.dictionary_category_id', $this->category->id)
        ->assertJsonPath('data.name', 'シェイク')
        ->assertJsonPath('data.name_eng', 'Shake')
        ->assertJsonPath('data.description', "@n{$rum->id}@{!ラム!}を振る")
        ->assertJsonPath('data.youtube', 'abcdefghijk')
        ->assertJsonPath('data.youtube_start_hour', 1)
        ->assertJsonPath('data.youtube_start_minute', 2)
        ->assertJsonPath('data.youtube_start_second', 3)
        ->assertJsonPath('data.image', 'x.png')
        ->assertJsonPath('data.image_txt', '出典')
        ->assertJsonPath('data.image_url', 'https://example.com/');

    $this->getJson('/api/admin/dictionaries/'.($term->id + 1000))->assertNotFound();
});

it('TC-315-05: 新規は 201 で保存し、名前の検索用の形と、説明の記法への変換を入れる', function () {
    $stir = dictionaryAdminTerm($this->category, 'ステア');

    $response = $this->postJson('/api/admin/dictionaries', dictionaryAdminInput($this->category, [
        'name' => 'シェイク・スタイル',
        'description' => 'ステアより強く混ぜる',
    ]))->assertCreated()
        ->assertJsonPath('data.name', 'シェイク・スタイル')
        ->assertJsonPath('data.description', "@d{$stir->id}@{!ステア!}より強く混ぜる");

    $term = Dictionary::query()->whereKey($response->json('data.id'))->sole();
    expect($term->only(['dictionary_category_id', 'name', 'name_search', 'name_eng', 'description', 'youtube', 'youtube_start_seconds', 'image', 'image_txt', 'image_url']))
        ->toBe([
            'dictionary_category_id' => $this->category->id,
            'name' => 'シェイク・スタイル',
            'name_search' => 'シェイクスタイル',
            'name_eng' => 'Shake',
            'description' => "@d{$stir->id}@より強く混ぜる",
            'youtube' => null,
            'youtube_start_seconds' => null,
            'image' => null,
            'image_txt' => null,
            'image_url' => null,
        ]);
});

it('TC-315-06: 必須の項目が無い・空だと 422 で、行は増えない', function (string $field) {
    $this->postJson('/api/admin/dictionaries', dictionaryAdminInput($this->category, [$field => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
    $this->postJson('/api/admin/dictionaries', dictionaryAdminInput($this->category, [$field => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
    expect(Dictionary::query()->count())->toBe(0);
})->with(['dictionary_category_id', 'name', 'name_eng', 'description']);

it('TC-315-07: 分類は実在するもの', function () {
    $this->postJson('/api/admin/dictionaries', dictionaryAdminInput($this->category, ['dictionary_category_id' => $this->category->id + 1000]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dictionary_category_id');
    $this->postJson('/api/admin/dictionaries', dictionaryAdminInput($this->category, ['dictionary_category_id' => 'abc']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dictionary_category_id');
});

it('TC-315-08: 名前・英名・画像のリンクの文字は 255 文字まで', function (string $field) {
    $this->postJson('/api/admin/dictionaries', dictionaryAdminInput($this->category, [$field => str_repeat('あ', 255)]))
        ->assertCreated();
    $this->postJson('/api/admin/dictionaries', dictionaryAdminInput($this->category, [$field => str_repeat('あ', 256)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with(['name', 'name_eng', 'image_txt']);

it('TC-315-09: 説明の記法を書き間違えると 422 で、行は増えない', function (string $description) {
    $this->postJson('/api/admin/dictionaries', dictionaryAdminInput($this->category, ['description' => $description]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('description');
    expect(Dictionary::query()->count())->toBe(0);
})->with([
    '閉じていない記法' => ['@d12 を見る'],
    '名前の見つからない記法' => ['@d無い用語@ を見る'],
]);

it('TC-315-10: YouTube は URL か動画 ID で受け、開始位置は時 0 から、分・秒は 0〜59', function (array $input, ?string $error, ?string $videoId, ?int $seconds) {
    $response = $this->postJson('/api/admin/dictionaries', dictionaryAdminInput($this->category, $input));

    if ($error !== null) {
        $response->assertUnprocessable()->assertJsonValidationErrors($error);
        expect(Dictionary::query()->count())->toBe(0);

        return;
    }
    $response->assertCreated();
    $term = Dictionary::query()->whereKey($response->json('data.id'))->sole();
    expect($term->youtube)->toBe($videoId)->and($term->youtube_start_seconds)->toBe($seconds);
})->with([
    'URL から動画 ID を取る' => [['youtube' => 'https://www.youtube.com/watch?v=abcdefghijk&t=1'], null, 'abcdefghijk', null],
    '短い URL' => [['youtube' => 'https://youtu.be/abcdefghijk'], null, 'abcdefghijk', null],
    '動画 ID' => [['youtube' => 'abcdefghijk'], null, 'abcdefghijk', null],
    'YouTube でない URL' => [['youtube' => 'https://example.com/watch?v=abcdefghijk'], 'youtube', null, null],
    '時 0・分 59・秒 59' => [['youtube' => 'abcdefghijk', 'youtube_start_hour' => 0, 'youtube_start_minute' => 59, 'youtube_start_second' => 59], null, 'abcdefghijk', 3599],
    '分 60' => [['youtube' => 'abcdefghijk', 'youtube_start_minute' => 60], 'youtube_start_minute', null, null],
    '秒 60' => [['youtube' => 'abcdefghijk', 'youtube_start_second' => 60], 'youtube_start_second', null, null],
    '時 -1' => [['youtube' => 'abcdefghijk', 'youtube_start_hour' => -1], 'youtube_start_hour', null, null],
    '動画が無ければ開始位置は持たない' => [['youtube_start_second' => 30], null, null, null],
]);

it('TC-315-11: 画像のリンクの URL は http・https の URL', function (string $url, bool $ok) {
    $response = $this->postJson('/api/admin/dictionaries', dictionaryAdminInput($this->category, ['image_url' => $url]));

    $ok ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors('image_url');
})->with([
    'https' => ['https://example.com/a', true],
    'URL でない' => ['example', false],
    'http・https でない' => ['ftp://example.com/a', false],
]);

it('TC-315-12: 画像を上げると保存し、差し替えると前のファイルを消し、remove_image で画像だけを消す', function () {
    Storage::fake('public');
    $term = dictionaryAdminTerm($this->category, 'ステア');
    $put = fn (array $input) => $this->put("/api/admin/dictionaries/{$term->id}", dictionaryAdminInput($this->category, $input), ['Accept' => 'application/json']);

    $put(['image' => dictionaryAdminPng()])->assertOk();
    $first = $term->refresh()->image;
    expect($first)->not->toBeNull();
    Storage::disk('public')->assertExists((string) $first);

    $put(['image' => dictionaryAdminPng()])->assertOk();
    $second = $term->refresh()->image;
    Storage::disk('public')->assertMissing((string) $first);
    Storage::disk('public')->assertExists((string) $second);

    $put(['remove_image' => '1'])->assertOk()->assertJsonPath('data.image', null);
    Storage::disk('public')->assertMissing((string) $second);
});

it('TC-315-13: 更新は 200 で行を書き換え、省いた任意の項目は空にする。無い ID は 404', function () {
    $other = DictionaryCategory::factory()->create();
    $term = dictionaryAdminTerm($this->category, 'ステア', ['youtube' => 'abcdefghijk', 'youtube_start_seconds' => 5, 'image_txt' => '出典']);

    $this->putJson("/api/admin/dictionaries/{$term->id}", dictionaryAdminInput($other, ['name' => 'ステアー', 'name_eng' => 'Stir']))
        ->assertOk()
        ->assertJsonPath('data.name', 'ステアー');

    expect($term->refresh()->only(['dictionary_category_id', 'name', 'name_search', 'name_eng', 'description', 'youtube', 'youtube_start_seconds', 'image_txt']))
        ->toBe([
            'dictionary_category_id' => $other->id,
            'name' => 'ステアー',
            'name_search' => 'ステアー',
            'name_eng' => 'Stir',
            'description' => 'シェイカーで振る',
            'youtube' => null,
            'youtube_start_seconds' => null,
            'image_txt' => null,
        ]);

    $this->putJson('/api/admin/dictionaries/'.($term->id + 1000), dictionaryAdminInput($this->category))->assertNotFound();
});

it('TC-315-14: 参照されていない用語は 204 で消え、画像のファイルも消える。消した用語はもう 404', function () {
    Storage::fake('public');
    Storage::disk('public')->put('term.png', (string) base64_decode(DICTIONARY_ADMIN_PNG, true));
    $term = dictionaryAdminTerm($this->category, 'ステア', ['image' => 'term.png']);
    $kept = dictionaryAdminTerm($this->category, 'シェイク');

    $this->deleteJson("/api/admin/dictionaries/{$term->id}")->assertNoContent();

    expect(Dictionary::query()->pluck('id')->all())->toBe([$kept->id]);
    Storage::disk('public')->assertMissing('term.png');
    $this->deleteJson("/api/admin/dictionaries/{$term->id}")->assertNotFound();
});

it('TC-315-15: カクテルのグラス・技法の用語として参照されている用語は消せず、409 で理由を返す', function (bool $glass, bool $method, array $reasons) {
    Storage::fake('public');
    Storage::disk('public')->put('term.png', (string) base64_decode(DICTIONARY_ADMIN_PNG, true));
    $term = dictionaryAdminTerm($this->category, 'カクテルグラス', ['image' => 'term.png']);
    if ($glass) {
        Cocktail::factory()->create(['glass_dictionary_id' => $term->id]);
    }
    if ($method) {
        Method::factory()->create(['dictionary_id' => $term->id]);
    }

    $this->deleteJson("/api/admin/dictionaries/{$term->id}")
        ->assertConflict()
        ->assertExactJson(['message' => 'この用語は消せません', 'reasons' => $reasons]);

    expect(Dictionary::query()->whereKey($term->id)->exists())->toBeTrue();
    Storage::disk('public')->assertExists('term.png');
})->with([
    'グラス' => [true, false, [
        ['code' => 'glass', 'message' => 'カクテルのグラスに使われています'],
    ]],
    '技法' => [false, true, [
        ['code' => 'method', 'message' => '技法を説明する用語になっています'],
    ]],
    'グラスと技法' => [true, true, [
        ['code' => 'glass', 'message' => 'カクテルのグラスに使われています'],
        ['code' => 'method', 'message' => '技法を説明する用語になっています'],
    ]],
]);
