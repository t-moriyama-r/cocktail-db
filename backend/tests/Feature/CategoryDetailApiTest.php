<?php

use App\Models\Brand;
use App\Models\Category;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;

// ノードの詳細の API(GET /api/categories/{id}。#42)。
// 旧はカテゴリ(DetailController@category)とミドル(@middle)の 2 段に分かれていた。新は任意の深さのノードを 1 つの API で返す。
// 銘柄の一覧の範囲は docs/decisions/public-detail.md の決まったこと 2、旧と変えたところは docs/decisions/category-detail.md。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * 5 段の木と、ほかの枝。
 *
 * 蒸留酒(根)── ラム ── ホワイトラム ── プレミアム・ホワイトラム ── 樽熟成ホワイトラム
 *                    │              └── キューバン・ホワイトラム
 *                    └── ダークラム
 *            └── ジン
 *
 * @return array<string, Category>
 */
function categoryDetailTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, string $nameEng, int $sortOrder = 0): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'name_eng' => $nameEng, 'sort_order' => $sortOrder])->getAttributes(),
    );

    $n = [];
    $n['蒸留酒'] = $add(null, '蒸留酒', 'Spirits');
    $n['ラム'] = $add($n['蒸留酒'], 'ラム', 'Rum');
    $n['ジン'] = $add($n['蒸留酒'], 'ジン', 'Gin');
    // 兄弟の並び順(sort_order、同じなら ID)を確かめるため、ダークラムを先に作り sort_order を後ろにする
    $n['ダークラム'] = $add($n['ラム'], 'ダークラム', 'Dark Rum', 2);
    $n['ホワイトラム'] = $add($n['ラム'], 'ホワイトラム', 'White Rum', 1);
    $n['プレミアム・ホワイトラム'] = $add($n['ホワイトラム'], 'プレミアム・ホワイトラム', 'Premium White Rum');
    $n['キューバン・ホワイトラム'] = $add($n['ホワイトラム'], 'キューバン・ホワイトラム', 'Cuban White Rum');
    $n['樽熟成ホワイトラム'] = $add($n['プレミアム・ホワイトラム'], '樽熟成ホワイトラム', 'Aged White Rum');

    return $n;
}

/**
 * ノードを、応答のパンくず・子の 1 つの形にする。
 *
 * @return array{id: int, name: string, name_eng: string}
 */
function categoryDetailNodeRef(Category $node): array
{
    return ['id' => $node->id, 'name' => $node->name, 'name_eng' => $node->name_eng];
}

/**
 * $node に銘柄を作る。
 *
 * @param  array<string, mixed>  $attrs
 */
function categoryDetailBrand(Category $node, string $name, array $attrs = []): Brand
{
    return Brand::factory()->create(['category_id' => $node->id, 'name' => $name, 'name_eng' => "{$name} eng", ...$attrs]);
}

it('TC-42-01: ノードの基本情報(名前・英名・説明文の区切り・動画・画像・フラグ)を返す', function () {
    $n = categoryDetailTree();
    $n['ホワイトラム']->update([
        'description' => 'さとうきびから作る。', 'is_major' => true, 'is_like' => true,
        'youtube' => 'abcdefghijk', 'youtube_start_seconds' => 61,
        'image' => 'white-rum.jpg', 'image_txt' => '出典', 'image_url' => 'https://example.com/',
    ]);

    $this->getJson("/api/categories/{$n['ホワイトラム']->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $n['ホワイトラム']->id)
        ->assertJsonPath('data.name', 'ホワイトラム')
        ->assertJsonPath('data.name_eng', 'White Rum')
        ->assertJsonPath('data.description', [['type' => 'text', 'text' => 'さとうきびから作る。']])
        ->assertJsonPath('data.is_major', true)
        ->assertJsonPath('data.is_like', true)
        ->assertJsonPath('data.youtube', 'abcdefghijk')
        ->assertJsonPath('data.youtube_start_seconds', 61)
        ->assertJsonPath('data.image', 'white-rum.jpg')
        ->assertJsonPath('data.image_txt', '出典')
        ->assertJsonPath('data.image_url', 'https://example.com/');
});

it('TC-42-02: 3 段目のノードのパンくずは、根から自分までの 3 段', function () {
    $n = categoryDetailTree();

    $this->getJson("/api/categories/{$n['ホワイトラム']->id}")
        ->assertOk()
        ->assertJsonPath('data.path', [
            categoryDetailNodeRef($n['蒸留酒']),
            categoryDetailNodeRef($n['ラム']),
            categoryDetailNodeRef($n['ホワイトラム']),
        ]);
});

it('TC-42-03: 5 段目のノードのパンくずは、根から自分までの 5 段', function () {
    $n = categoryDetailTree();

    $this->getJson("/api/categories/{$n['樽熟成ホワイトラム']->id}")
        ->assertOk()
        ->assertJsonPath('data.path', [
            categoryDetailNodeRef($n['蒸留酒']),
            categoryDetailNodeRef($n['ラム']),
            categoryDetailNodeRef($n['ホワイトラム']),
            categoryDetailNodeRef($n['プレミアム・ホワイトラム']),
            categoryDetailNodeRef($n['樽熟成ホワイトラム']),
        ]);
});

it('TC-42-04: 根(大元)のノードも返し、パンくずは自分だけ', function () {
    $n = categoryDetailTree();

    $this->getJson("/api/categories/{$n['蒸留酒']->id}")
        ->assertOk()
        ->assertJsonPath('data.path', [categoryDetailNodeRef($n['蒸留酒'])])
        ->assertJsonPath('data.children', [categoryDetailNodeRef($n['ラム']), categoryDetailNodeRef($n['ジン'])]);
});

it('TC-42-05: 子のノードは直下だけを、兄弟の並び順(sort_order、同じなら ID)で返す', function () {
    $n = categoryDetailTree();

    $this->getJson("/api/categories/{$n['ラム']->id}")
        ->assertOk()
        // 孫(プレミアム・ホワイトラムなど)は入らない。ダークラムは先に作ったが sort_order が後ろ
        ->assertJsonPath('data.children', [categoryDetailNodeRef($n['ホワイトラム']), categoryDetailNodeRef($n['ダークラム'])]);
});

it('TC-42-06: 子の無いノードは、子のノードを空の並びで返す', function () {
    $n = categoryDetailTree();

    $this->getJson("/api/categories/{$n['樽熟成ホワイトラム']->id}")
        ->assertOk()
        ->assertJsonPath('data.children', []);
});

it('TC-42-07: 3 段目のノードの銘柄の一覧は、自分と配下すべてのノードの銘柄で、親・兄弟の銘柄は入らない', function () {
    $n = categoryDetailTree();
    $own = categoryDetailBrand($n['ホワイトラム'], 'バカルディ');
    $child = categoryDetailBrand($n['キューバン・ホワイトラム'], 'ハバナクラブ');
    $deep = categoryDetailBrand($n['樽熟成ホワイトラム'], '樽のラム');
    categoryDetailBrand($n['ラム'], '親のラム');
    categoryDetailBrand($n['ダークラム'], 'マイヤーズ');
    categoryDetailBrand($n['ジン'], 'ビーフィーター');

    $this->getJson("/api/categories/{$n['ホワイトラム']->id}")
        ->assertOk()
        // 並びは ID の順
        ->assertJsonPath('data.brands.*.id', [$own->id, $child->id, $deep->id])
        ->assertJsonPath('data.brands.*.name', ['バカルディ', 'ハバナクラブ', '樽のラム']);
});

it('TC-42-08: 5 段目のノードの銘柄の一覧は、自分の銘柄だけで、祖先の銘柄は入らない', function () {
    $n = categoryDetailTree();
    $own = categoryDetailBrand($n['樽熟成ホワイトラム'], '樽のラム');
    categoryDetailBrand($n['プレミアム・ホワイトラム'], 'プレミアム');
    categoryDetailBrand($n['ホワイトラム'], 'バカルディ');

    $this->getJson("/api/categories/{$n['樽熟成ホワイトラム']->id}")
        ->assertOk()
        ->assertJsonPath('data.brands.*.id', [$own->id]);
});

it('TC-42-09: 銘柄の一覧の 1 件は、名前・英名・度数・動画と、所属ノードの根からのパンくずを返す', function () {
    $n = categoryDetailTree();
    $brand = categoryDetailBrand($n['樽熟成ホワイトラム'], '樽のラム', [
        'name_eng' => 'Aged Rum', 'volume' => 40.5, 'youtube' => 'zyxwvutsrqp',
    ]);

    $this->getJson("/api/categories/{$n['ラム']->id}")
        ->assertOk()
        ->assertJsonPath('data.brands', [[
            'id' => $brand->id,
            'name' => '樽のラム',
            'name_eng' => 'Aged Rum',
            'strength' => 40.5,
            'youtube' => 'zyxwvutsrqp',
            'path' => [
                categoryDetailNodeRef($n['蒸留酒']),
                categoryDetailNodeRef($n['ラム']),
                categoryDetailNodeRef($n['ホワイトラム']),
                categoryDetailNodeRef($n['プレミアム・ホワイトラム']),
                categoryDetailNodeRef($n['樽熟成ホワイトラム']),
            ],
        ]]);
});

it('TC-42-15: 同じノードの銘柄が複数あっても、どの銘柄のパンくずも根から所属ノードまでの同じ段', function () {
    $n = categoryDetailTree();
    categoryDetailBrand($n['ホワイトラム'], 'バカルディ');
    categoryDetailBrand($n['ホワイトラム'], 'ロンリコ');
    $expected = [
        categoryDetailNodeRef($n['蒸留酒']),
        categoryDetailNodeRef($n['ラム']),
        categoryDetailNodeRef($n['ホワイトラム']),
    ];

    $this->getJson("/api/categories/{$n['ラム']->id}")
        ->assertOk()
        ->assertJsonPath('data.brands.0.path', $expected)
        ->assertJsonPath('data.brands.1.path', $expected);
});

it('TC-42-10: 銘柄の無いノードは、銘柄の一覧を空の並びで返す', function () {
    $n = categoryDetailTree();
    categoryDetailBrand($n['ラム'], '親のラム');

    $this->getJson("/api/categories/{$n['ダークラム']->id}")
        ->assertOk()
        ->assertJsonPath('data.brands', []);
});

it('TC-42-11: 有名な銘柄があれば、銘柄の一覧の 1 件と同じ形で返す', function () {
    $n = categoryDetailTree();
    $famous = categoryDetailBrand($n['ホワイトラム'], 'バカルディ', ['name_eng' => 'Bacardi', 'volume' => 40, 'youtube' => null]);
    $n['ラム']->update(['famous_brand_id' => $famous->id]);

    $this->getJson("/api/categories/{$n['ラム']->id}")
        ->assertOk()
        ->assertJsonPath('data.famous_brand', [
            'id' => $famous->id,
            'name' => 'バカルディ',
            'name_eng' => 'Bacardi',
            'strength' => 40,
            'youtube' => null,
            'path' => [
                categoryDetailNodeRef($n['蒸留酒']),
                categoryDetailNodeRef($n['ラム']),
                categoryDetailNodeRef($n['ホワイトラム']),
            ],
        ]);
});

it('TC-42-12: 有名な銘柄が無ければ null', function () {
    $n = categoryDetailTree();

    $this->getJson("/api/categories/{$n['ラム']->id}")
        ->assertOk()
        ->assertJsonPath('data.famous_brand', null);
});

it('TC-42-13: 無いノードの ID は 404', function () {
    $n = categoryDetailTree();
    $missing = $n['樽熟成ホワイトラム']->id + 1;

    $this->getJson("/api/categories/{$missing}")->assertNotFound();
});

it('TC-42-14: 数字でない ID・int に収まらない桁数の ID は 404(経路に合わない)', function () {
    $this->getJson('/api/categories/abc')->assertNotFound();
    $this->getJson('/api/categories/'.str_repeat('9', 19))->assertNotFound();
});
