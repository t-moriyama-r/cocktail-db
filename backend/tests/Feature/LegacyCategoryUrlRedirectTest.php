<?php

use App\Models\Category;
use App\Models\LegacyIdMap;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyFixture;
use Tests\Support\LegacyImportCommand;

// 旧のカテゴリ・ミドルの詳細と、旧の管理画面の URL の 301(#78)。
// 旧と変えたところは docs/decisions/legacy-urls.md の「旧との差分」。
// 旧の ID と新しいノードの対応は、旧 DB の小さなデータ(LegacyFixture)を legacy:import で移して作る。旧 DB を使うので db グループ

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->withoutVite();
    LegacyFixture::load();
    LegacyImportCommand::run($this, ['--only' => 'categories'])->assertExitCode(0);
});

/**
 * 旧の表の行の ID と名前(ID の昇順)。
 *
 * @return array<int, string> 旧 ID => 名前
 */
function legacyRedirectRows(string $table): array
{
    $rows = [];
    foreach (DB::connection('legacy')->table($table)->orderBy('id')->get(['id', 'name']) as $row) {
        $rows[is_numeric($row->id) ? (int) $row->id : throw new RuntimeException("{$table} の id が数でない")] =
            is_string($row->name) ? $row->name : throw new RuntimeException("{$table} の name が文字列でない");
    }

    return $rows;
}

it('TC-78-01: 旧のすべてのカテゴリ・ミドルの詳細の URL が、1 回の 301 で、同じ名前のノードの /category/{新ID} に届く(:dataset)', function (string $legacyTable, string $legacyPath) {
    $rows = legacyRedirectRows($legacyTable);
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $legacyId => $name) {
        $response = $this->get("{$legacyPath}/{$legacyId}");
        $response->assertStatus(301);

        $location = (string) $response->headers->get('Location');
        $path = (string) parse_url($location, PHP_URL_PATH);
        $nodeId = preg_match('#^/category/([0-9]+)$#', $path, $m) === 1
            ? (int) $m[1]
            : throw new RuntimeException("{$legacyPath}/{$legacyId} の転送先が /category/{ID} でない: {$location}");
        expect(Category::query()->findOrFail($nodeId)->name)->toBe($name);

        // 移った先は転送せずにページを返す(301 が 1 回で終わる)
        $this->get($path)->assertOk();
    }
})->with([
    'カテゴリ' => ['m_category', '/detail/category'],
    'ミドル' => ['m_middle', '/detail/middle'],
]);

it('TC-78-02: 対応表に無い旧 ID は転送せず 404 を返す(:dataset)', function (string $path) {
    $this->get($path)
        ->assertNotFound()
        ->assertSee('<div id="root"></div>', false);
})->with([
    '無いカテゴリ' => '/detail/category/999',
    '無いミドル' => '/detail/middle/999',
    // ミドル 4 は旧に無い。カテゴリ 4 の対応を使って転送しない
    'カテゴリにだけある ID のミドル' => '/detail/middle/4',
    // 種別 1 の対応はあるが、種別の詳細の旧 URL は無い。カテゴリ 0 は旧に無い
    'カテゴリ 0' => '/detail/category/0',
]);

it('TC-78-03: 数字でない ID・int に収まらない桁の ID は 404 を返す(:dataset)', function (string $path) {
    $this->get($path)->assertNotFound();
})->with([
    '英字' => '/detail/category/abc',
    '負の数' => '/detail/middle/-1',
    '桁あふれ' => '/detail/category/99999999999999999999',
]);

it('TC-78-04: 対応先のノードが消えていたら、転送せず 404 を返す', function () {
    $node = app(CategoryTree::class)->addNode(null, Category::factory()->make()->getAttributes());
    LegacyIdMap::record('m_category', 'categories', [500 => $node->id]);
    $this->get('/detail/category/500')->assertStatus(301);

    LegacyIdMap::record('m_category', 'categories', [500 => $node->id + 1000]);
    $this->get('/detail/category/500')->assertNotFound();
});

it('TC-78-05: 旧の URL に付いたクエリは、移った先に持ち越さない', function () {
    $response = $this->get('/detail/category/1?utm_source=x');

    $response->assertStatus(301);
    expect(parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY))->toBeNull();
});

it('TC-78-06: 旧の管理画面の URL(/Cocktail/admin 以下)を GET すると、/admin へ 301 で移る(:dataset)', function (string $path) {
    $response = $this->get($path);

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toBe('/admin');
})->with([
    '管理画面の根' => '/Cocktail/admin',
    'ログイン' => '/Cocktail/admin/login',
    '旧の管理画面の API' => '/Cocktail/admin/get/category',
    'クエリ付き' => '/Cocktail/admin/login?next=1',
]);

it('TC-78-07: 旧の URL に似ているが違うパスは、これまでどおり SPA の HTML を 200 で返す(:dataset)', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    'カテゴリの ID 無し' => '/detail/category',
    'カテゴリの下' => '/detail/category/1/x',
    '管理画面の手前' => '/Cocktail',
    '管理画面に似た名前' => '/Cocktail/administrator',
    '大文字小文字違い' => '/cocktail/admin',
]);

it('TC-78-08: 旧の URL への GET 以外は転送せず 405 を返す(:dataset)', function (string $path) {
    $this->post($path)->assertStatus(405);
})->with([
    'カテゴリ' => '/detail/category/1',
    'ミドル' => '/detail/middle/1',
    '旧の管理画面の API' => '/Cocktail/admin/get/category',
]);

it('TC-78-09: カクテル・銘柄の旧 URL は ID が変わらないので転送しない(:dataset)', function (string $path) {
    $this->get($path)->assertStatus(404);
})->with([
    // 旧の ID 1 の行は移していない(--only=categories)ので、詳細ページの 404 になる。301 にはならない
    'カクテル' => '/detail/cocktail/1',
    '銘柄' => '/detail/brand/1',
]);
