<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\LegacyIdMap;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LegacyFixture;
use Tests\Support\LegacyImportCommand;

// 旧の SNS カードの URL(/card/{command}/{id})の 301(#113)。
// 旧と変えたところは docs/decisions/legacy-urls.md の「旧との差分」。
// カテゴリ・ミドルの旧 ID と新しいノードの対応は、旧 DB の小さなデータ(LegacyFixture)を legacy:import で移して作る。旧 DB を使うので db グループ

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->withoutVite();
    LegacyFixture::load();
    LegacyImportCommand::run($this, ['--only' => 'categories'])->assertExitCode(0);
});

/**
 * 転送先(Location)のパス。
 *
 * @param  TestResponse<Response>  $response
 */
function legacyCardLocationPath(TestResponse $response): string
{
    return (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);
}

it('TC-113-01: カクテルの旧 SNS カードの URL が、1 回の 301 で /detail/cocktail/{ID} に届く(:dataset)', function (string $command) {
    $cocktail = Cocktail::factory()->create();

    $response = $this->get("/card/{$command}/{$cocktail->id}");

    $response->assertStatus(301);
    expect(legacyCardLocationPath($response))->toBe("/detail/cocktail/{$cocktail->id}");
    $this->get("/detail/cocktail/{$cocktail->id}")->assertOk();
})->with([
    '小文字' => 'cocktail',
    '先頭が大文字' => 'Cocktail',
]);

it('TC-113-02: 銘柄の旧 SNS カードの URL が、1 回の 301 で /detail/brand/{ID} に届く(:dataset)', function (string $command) {
    $brand = Brand::factory()->create();

    $response = $this->get("/card/{$command}/{$brand->id}");

    $response->assertStatus(301);
    expect(legacyCardLocationPath($response))->toBe("/detail/brand/{$brand->id}");
    $this->get("/detail/brand/{$brand->id}")->assertOk();
})->with([
    '小文字' => 'brand',
    '先頭が大文字' => 'Brand',
]);

it('TC-113-03: 旧のすべてのカテゴリ・ミドルの SNS カードの URL が、1 回の 301 で、同じ名前のノードの /category/{新ID} に届く(:dataset)', function (string $legacyTable, string $command) {
    $rows = DB::connection('legacy')->table($legacyTable)->orderBy('id')->get(['id', 'name']);
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $legacyId = is_numeric($row->id) ? (int) $row->id : throw new RuntimeException("{$legacyTable} の id が数でない");
        $response = $this->get("/card/{$command}/{$legacyId}");
        $response->assertStatus(301);

        $path = legacyCardLocationPath($response);
        $nodeId = preg_match('#^/category/([0-9]+)$#', $path, $m) === 1
            ? (int) $m[1]
            : throw new RuntimeException("/card/{$command}/{$legacyId} の転送先が /category/{ID} でない: {$path}");
        expect($nodeId)->toBe(LegacyIdMap::newIdOf($legacyTable, 'categories', $legacyId))
            ->and(Category::query()->findOrFail($nodeId)->name)->toBe($row->name);

        // 移った先は転送せずにページを返す(301 が 1 回で終わる)
        $this->get($path)->assertOk();
    }
})->with([
    'カテゴリ・小文字' => ['m_category', 'category'],
    'カテゴリ・先頭が大文字' => ['m_category', 'Category'],
    'ミドル・小文字' => ['m_middle', 'middle'],
    'ミドル・先頭が大文字' => ['m_middle', 'Middle'],
]);

it('TC-113-04: 旧が受けていなかった表記の command は、転送せず 404 を返す(:dataset)', function (string $command) {
    $cocktail = Cocktail::factory()->create();

    $this->get("/card/{$command}/{$cocktail->id}")
        ->assertNotFound()
        ->assertSee('<div id="root"></div>', false);
})->with([
    'すべて大文字' => 'COCKTAIL',
    '途中が大文字' => 'cockTail',
    '用語' => 'dictionary',
    '種別' => 'kind',
    '知らない語' => 'detail',
]);

it('TC-113-05: 無い ID は、転送せず 404 を返す(:dataset)', function (string $path) {
    $this->get($path)
        ->assertNotFound()
        ->assertSee('<div id="root"></div>', false);
})->with([
    '無いカクテル' => '/card/cocktail/999999',
    '無い銘柄' => '/card/brand/999999',
    '無いカテゴリ' => '/card/category/999',
    '無いミドル' => '/card/middle/999',
    // ミドル 4 は旧に無い。カテゴリ 4 の対応を使って転送しない
    'カテゴリにだけある ID のミドル' => '/card/middle/4',
    'カテゴリ 0' => '/card/category/0',
]);

it('TC-113-06: 下書きのカクテルは、転送せず 404 を返す', function () {
    $draft = Cocktail::factory()->create(['is_draft' => true]);

    $this->get("/card/cocktail/{$draft->id}")->assertNotFound();
});

it('TC-113-07: 数字でない ID・int に収まらない桁の ID は 404 を返す(:dataset)', function (string $path) {
    $this->get($path)->assertNotFound();
})->with([
    '英字' => '/card/cocktail/abc',
    '数字の後に英字' => '/card/brand/1abc',
    '負の数' => '/card/middle/-1',
    '小数' => '/card/cocktail/1.0',
    '桁あふれ' => '/card/category/99999999999999999999',
]);

it('TC-113-08: 対応先のノードが消えていたら、転送せず 404 を返す', function () {
    $node = app(CategoryTree::class)->addNode(null, Category::factory()->make()->getAttributes());
    LegacyIdMap::record('m_middle', 'categories', [500 => $node->id]);
    $this->get('/card/middle/500')->assertStatus(301);

    LegacyIdMap::record('m_middle', 'categories', [500 => $node->id + 1000]);
    $this->get('/card/middle/500')->assertNotFound();
});

it('TC-113-09: 先頭に 0 の付いた ID は、0 を除いた ID の詳細へ移る', function () {
    $cocktail = Cocktail::factory()->create();

    $response = $this->get("/card/cocktail/0{$cocktail->id}");

    $response->assertStatus(301);
    expect(legacyCardLocationPath($response))->toBe("/detail/cocktail/{$cocktail->id}");
});

it('TC-113-10: 旧の URL に付いたクエリは、移った先に持ち越さない(:dataset)', function (string $command) {
    $id = $command === 'cocktail' ? Cocktail::factory()->create()->id : 1;

    $response = $this->get("/card/{$command}/{$id}?utm_source=x");

    $response->assertStatus(301);
    expect(parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY))->toBeNull();
})->with([
    'カクテル' => 'cocktail',
    'カテゴリ' => 'category',
]);

it('TC-113-11: 旧の SNS カードの URL への GET 以外は転送せず 405 を返す', function () {
    $cocktail = Cocktail::factory()->create();

    $this->post("/card/cocktail/{$cocktail->id}")->assertStatus(405);
});

it('TC-113-12: 旧の SNS カードの URL に似ているが違うパスは、これまでどおり SPA の HTML を 200 で返す(:dataset)', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    'card だけ' => '/card',
    'ID 無し' => '/card/cocktail',
    'ID の下' => '/card/cocktail/1/x',
]);
