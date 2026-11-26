<?php

use App\Models\Category;
use App\Models\LegacyIdMap;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LegacyNodeMap;

// 旧の管理画面のカテゴリ・ミドルの編集・新規の URL(#817)。旧のブックマークから来た管理者を、新のノードの編集フォームへ 302 で移す。
// 決めたことと旧と挙動を変えたところは docs/decisions/legacy-admin-urls.md。
// 旧 ID と新しいノードの対応(legacy_id_maps)を DB で引くので db グループ

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->withoutVite();

    $tree = app(CategoryTree::class);
    $categoryNode = $tree->addNode(null, Category::factory()->make()->getAttributes());
    $middleNode = $tree->addNode($categoryNode, Category::factory()->make()->getAttributes());
    // 旧のカテゴリ 3 とミドル 7 を、振り直した新 ID のノードに対応させる。行き先の新 ID は、対応表(LegacyNodeMap)から引いて確かめる
    LegacyIdMap::record('m_category', 'categories', [3 => $categoryNode->id]);
    LegacyIdMap::record('m_middle', 'categories', [7 => $middleNode->id]);
});

it('TC-817-01: 旧のカテゴリ・ミドルの編集の URL を GET すると、対応する新のノードの編集フォームへ 302 で移る(:dataset)', function (string $path, string $legacyTable, int $legacyId) {
    $response = $this->get($path);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe('/admin/categories/'.LegacyNodeMap::id($legacyTable, $legacyId));
})->with([
    'カテゴリ' => ['/admin/edit/Category/3', 'm_category', 3],
    'ミドル' => ['/admin/edit/Middle/7', 'm_middle', 7],
    'カテゴリ(末尾のスラッシュ付き)' => ['/admin/edit/Category/3/', 'm_category', 3],
    'ミドル(末尾のスラッシュ付き)' => ['/admin/edit/Middle/7/', 'm_middle', 7],
]);

it('TC-817-02: 旧のカテゴリ・ミドルの新規の URL を GET すると、新のノードの新規のフォームへ 302 で移る(:dataset)', function (string $path) {
    $response = $this->get($path);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe('/admin/categories/new');
})->with([
    'カテゴリ' => '/admin/edit/Category',
    'カテゴリ(末尾のスラッシュ付き。旧のリンクの形)' => '/admin/edit/Category/',
    'ミドル' => '/admin/edit/Middle',
    'ミドル(末尾のスラッシュ付き。旧のリンクの形)' => '/admin/edit/Middle/',
]);

it('TC-817-03: 旧の URL に付いてきたクエリは、移った先の URL に持ち越さない', function () {
    $edit = $this->get('/admin/edit/Category/3?from=menu');
    $edit->assertStatus(302);
    expect($edit->headers->get('Location'))->toBe('/admin/categories/'.LegacyNodeMap::id('m_category', 3));

    $new = $this->get('/admin/edit/Middle/?parent=1');
    $new->assertStatus(302);
    expect($new->headers->get('Location'))->toBe('/admin/categories/new');
});

it('TC-817-04: 対応の無い旧 ID・数字でない ID・旧に無い形のパスは、移さずに SPA の HTML を返す(画面は 404 の画面)(:dataset)', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    '無いカテゴリ' => '/admin/edit/Category/999',
    '無いミドル' => '/admin/edit/Middle/999',
    // ミドル 3 は対応が無い。カテゴリ 3 の対応を使って移さない
    'カテゴリにだけある ID のミドル' => '/admin/edit/Middle/3',
    '数字でない ID' => '/admin/edit/Category/abc',
    '負の ID' => '/admin/edit/Middle/-1',
    '桁あふれ' => '/admin/edit/Category/99999999999999999999',
    '編集の下' => '/admin/edit/Category/3/x',
    '大文字小文字違い' => '/admin/edit/category/3',
]);

it('TC-817-05: 対応先のノードが消えていたら、移さずに SPA の HTML を返す', function () {
    LegacyIdMap::record('m_category', 'categories', [500 => LegacyNodeMap::id('m_category', 3) + 1000]);

    $this->get('/admin/edit/Category/500')
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
});

it('TC-817-06: 旧の URL への GET 以外は移さず 405 を返す(:dataset)', function (string $path) {
    $this->post($path)->assertStatus(405);
})->with([
    'カテゴリの編集' => '/admin/edit/Category/3',
    'ミドルの編集' => '/admin/edit/Middle/7',
    'カテゴリの新規' => '/admin/edit/Category',
    'ミドルの新規' => '/admin/edit/Middle/',
]);
