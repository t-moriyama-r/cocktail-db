<?php

use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Method;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// トップに出すカクテルの件数・一覧・新着動画の API(GET /api/cocktails/count・GET /api/cocktails・GET /api/cocktails/latest-video。#39)。
// 期待値は、旧 CommonController(本番の laravel13)のソースと、docs/decisions/cocktail-lists.md の旧との差分から置く。
// 旧システムを動かす環境が無いので、旧での実測は取っていない(PR 本文の表に「未確認」と書いた)。

uses(RefreshDatabase::class)->group('db');

/**
 * 一覧の API が返したカクテルの ID。
 *
 * @return list<int>
 */
function listedIds(TestCase $test, string $query): array
{
    /** @var list<int> $ids */
    $ids = $test->getJson("/api/cocktails?{$query}")->assertOk()->json('data.*.id');

    return $ids;
}

/**
 * 更新日時を決めてカクテルを作る(updated_at は保存のたびに今の時刻になるので、作った後に書き換える)。
 *
 * @param  array<string, mixed>  $attrs
 */
function cocktailUpdatedAt(string $updatedAt, array $attrs = []): Cocktail
{
    $cocktail = Cocktail::factory()->create($attrs);
    $cocktail->timestamps = false;
    $cocktail->forceFill(['updated_at' => Carbon::parse($updatedAt)])->save();

    return $cocktail;
}

it('TC-39-01: 件数は、公開しているカクテルだけを数える', function () {
    Cocktail::factory()->count(3)->create();
    Cocktail::factory()->count(2)->create(['is_draft' => true]);

    $this->getJson('/api/cocktails/count')->assertOk()->assertExactJson(['count' => 3]);
});

it('TC-39-02: 公開しているカクテルが無ければ、件数は 0', function () {
    Cocktail::factory()->create(['is_draft' => true]);

    $this->getJson('/api/cocktails/count')->assertOk()->assertExactJson(['count' => 0]);
});

it('TC-39-03: 最近は、更新日時の新しい順に limit 件。同じ日時なら ID の大きい順', function () {
    $old = cocktailUpdatedAt('2026-01-01 00:00:00');
    $newest = cocktailUpdatedAt('2026-03-01 00:00:00');
    $sameA = cocktailUpdatedAt('2026-02-01 00:00:00');
    $sameB = cocktailUpdatedAt('2026-02-01 00:00:00');

    expect(listedIds($this, 'sort=recent&limit=3'))->toBe([$newest->id, $sameB->id, $sameA->id])
        ->and(listedIds($this, 'sort=recent&limit=50'))->toBe([$newest->id, $sameB->id, $sameA->id, $old->id]);
});

it('TC-39-04: 最近に、下書きは更新日時が新しくても出ない', function () {
    $published = cocktailUpdatedAt('2026-01-01 00:00:00');
    cocktailUpdatedAt('2026-12-01 00:00:00', ['is_draft' => true]);

    expect(listedIds($this, 'sort=recent&limit=5'))->toBe([$published->id]);
});

it('TC-39-05: limit を省くと 10 件', function (string $sort) {
    Cocktail::factory()->count(11)->create(['rate' => 5]);

    expect(listedIds($this, "sort={$sort}"))->toHaveCount(10);
})->with(['recent', 'recommend', 'random']);

it('TC-39-06: おすすめは、評価が 3 以上の公開しているカクテルだけから選ぶ', function () {
    $rate3 = Cocktail::factory()->create(['rate' => 3]);
    $rate5 = Cocktail::factory()->create(['rate' => 5]);
    Cocktail::factory()->create(['rate' => 2]);
    Cocktail::factory()->create(['rate' => null]);
    Cocktail::factory()->create(['rate' => 5, 'is_draft' => true]);

    expect(listedIds($this, 'sort=recommend&limit=50'))->toEqualCanonicalizing([$rate3->id, $rate5->id]);
});

it('TC-39-07: ランダムは、公開しているカクテルから limit 件を、重ならずに選ぶ', function () {
    $published = Cocktail::factory()->count(5)->create();
    Cocktail::factory()->count(5)->create(['is_draft' => true]);
    $publishedIds = $published->modelKeys();

    $picked = listedIds($this, 'sort=random&limit=3');
    expect($picked)->toHaveCount(3)
        ->and(array_unique($picked))->toHaveCount(3)
        ->and(array_diff($picked, $publishedIds))->toBe([])
        ->and(listedIds($this, 'sort=random&limit=50'))->toEqualCanonicalizing($publishedIds);
});

it('TC-39-08: 一覧の 1 件は、一覧の表に出す項目を返す', function (string $sort) {
    $gin = app(CategoryTree::class)->addNode(null, Category::factory()->make(['name' => 'ジン', 'name_eng' => 'Gin'])->getAttributes());
    $shake = Method::factory()->create(['name' => 'シェイク', 'name_eng' => 'Shake']);
    $cocktail = Cocktail::factory()->create([
        'name' => 'ギムレット', 'name_eng' => 'Gimlet', 'base_category_id' => $gin->id, 'method_id' => $shake->id,
        'volume' => 30.5, 'sweet_degree' => 2, 'rate' => 4, 'youtube' => 'abcdefghijk', 'is_major' => true,
    ]);

    $this->getJson("/api/cocktails?sort={$sort}&limit=1")
        ->assertOk()
        ->assertJsonPath('data.0', [
            'id' => $cocktail->id,
            'name' => 'ギムレット',
            'name_eng' => 'Gimlet',
            'is_major' => true,
            'base' => ['id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin'],
            'method' => ['id' => $shake->id, 'name' => 'シェイク', 'name_eng' => 'Shake'],
            'recipe_count' => 0,
            'strength' => 30.5,
            'sweet_degree' => 2,
            'rate' => 4,
            'youtube' => 'abcdefghijk',
            'updated_at' => $cocktail->updated_at?->toJSON(),
        ]);
})->with(['recent', 'recommend', 'random']);

it('TC-39-09: limit は 1 と 50 を受け付ける', function (int $limit) {
    Cocktail::factory()->count(51)->create();

    expect(listedIds($this, "sort=recent&limit={$limit}"))->toHaveCount($limit);
})->with([1, 50]);

it('TC-39-10: limit が 1〜50 の整数でなければ 422', function (string $limit) {
    $this->getJson("/api/cocktails?sort=recent&limit={$limit}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('limit');
})->with(['0', '51', '-1', 'abc', '1.5']);

it('TC-39-11: sort が無い・recent・recommend・random のどれでもなければ 422', function (string $query) {
    $this->getJson("/api/cocktails?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sort');
})->with(['無い' => 'limit=5', '空' => 'sort=', '知らない値' => 'sort=popular', '大文字' => 'sort=RECENT']);

it('TC-39-12: 新着動画は、動画の公開日時がいちばん新しい公開のカクテル。下書きと動画の無いものは選ばない', function () {
    Cocktail::factory()->create(['youtube' => 'old00000000', 'youtube_created_at' => '2026-01-01 00:00:00']);
    $newest = Cocktail::factory()->create(['youtube' => 'new00000000', 'youtube_created_at' => '2026-02-01 00:00:00']);
    Cocktail::factory()->create(['youtube' => 'draft000000', 'youtube_created_at' => '2026-03-01 00:00:00', 'is_draft' => true]);
    Cocktail::factory()->create(['youtube' => null, 'youtube_created_at' => '2026-04-01 00:00:00']);
    Cocktail::factory()->create(['youtube' => '', 'youtube_created_at' => '2026-05-01 00:00:00']);

    $this->getJson('/api/cocktails/latest-video')->assertOk()->assertJsonPath('data.id', $newest->id);
});

it('TC-39-13: 新着動画の公開日時が同じなら、ID の大きいほう', function () {
    Cocktail::factory()->create(['youtube' => 'a0000000000', 'youtube_created_at' => '2026-02-01 00:00:00']);
    $later = Cocktail::factory()->create(['youtube' => 'b0000000000', 'youtube_created_at' => '2026-02-01 00:00:00']);

    $this->getJson('/api/cocktails/latest-video')->assertOk()->assertJsonPath('data.id', $later->id);
});

it('TC-39-14: 新着動画は、名前・英名・動画・ベース・説明文(区切りの並び)を返す', function () {
    $gin = app(CategoryTree::class)->addNode(null, Category::factory()->make(['name' => 'ジン', 'name_eng' => 'Gin'])->getAttributes());
    $cocktail = Cocktail::factory()->create([
        'name' => 'ギムレット', 'name_eng' => 'Gimlet', 'base_category_id' => $gin->id,
        'youtube' => 'abcdefghijk', 'youtube_created_at' => '2026-02-01 12:34:56',
        'description' => "ベースは@n{$gin->id}@。\n2行目",
    ]);

    $this->getJson('/api/cocktails/latest-video')
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $cocktail->id,
            'name' => 'ギムレット',
            'name_eng' => 'Gimlet',
            'youtube' => 'abcdefghijk',
            'youtube_created_at' => Carbon::parse('2026-02-01 12:34:56')->toJSON(),
            'base' => ['id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin'],
            'description' => [
                ['type' => 'text', 'text' => 'ベースは'],
                ['type' => 'node', 'id' => $gin->id, 'text' => 'ジン', 'has_youtube' => false],
                ['type' => 'text', 'text' => "。\n2行目"],
            ],
        ]]);
});

it('TC-39-15: 動画のある公開のカクテルが無ければ、新着動画は 404', function () {
    Cocktail::factory()->create(['youtube' => null]);
    Cocktail::factory()->create(['youtube' => 'draft000000', 'youtube_created_at' => '2026-03-01 00:00:00', 'is_draft' => true]);

    $this->getJson('/api/cocktails/latest-video')->assertNotFound();
});
