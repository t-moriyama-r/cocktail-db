<?php

use App\Models\Brand;
use App\Models\Category;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;

// 銘柄の詳細の API(GET /api/brands/{id}。#43)。
// 旧は DetailController@brand。銘柄の一覧の範囲は docs/decisions/public-detail.md の決まったこと 2、旧と変えたところは
// docs/decisions/brand-detail.md。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * 5 段の木と、ほかの枝。
 *
 * 蒸留酒(根)── ラム ── ホワイトラム ── プレミアム・ホワイトラム ── 樽熟成ホワイトラム
 *            │      └── ダークラム
 *            └── ジン
 *
 * @return array<string, Category>
 */
function brandDetailTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, string $nameEng): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'name_eng' => $nameEng])->getAttributes(),
    );

    $n = [];
    $n['蒸留酒'] = $add(null, '蒸留酒', 'Spirits');
    $n['ラム'] = $add($n['蒸留酒'], 'ラム', 'Rum');
    $n['ジン'] = $add($n['蒸留酒'], 'ジン', 'Gin');
    $n['ホワイトラム'] = $add($n['ラム'], 'ホワイトラム', 'White Rum');
    $n['ダークラム'] = $add($n['ラム'], 'ダークラム', 'Dark Rum');
    $n['プレミアム・ホワイトラム'] = $add($n['ホワイトラム'], 'プレミアム・ホワイトラム', 'Premium White Rum');
    $n['樽熟成ホワイトラム'] = $add($n['プレミアム・ホワイトラム'], '樽熟成ホワイトラム', 'Aged White Rum');

    return $n;
}

/**
 * ノードを、応答のパンくずの 1 段の形にする。
 *
 * @return array{id: int, name: string, name_eng: string}
 */
function brandDetailNodeRef(Category $node): array
{
    return ['id' => $node->id, 'name' => $node->name, 'name_eng' => $node->name_eng];
}

/**
 * $node に銘柄を作る。
 *
 * @param  array<string, mixed>  $attrs
 */
function brandDetailBrand(Category $node, string $name, array $attrs = []): Brand
{
    return Brand::factory()->create(['category_id' => $node->id, 'name' => $name, 'name_eng' => "{$name} eng", ...$attrs]);
}

it('TC-43-01: 銘柄の基本情報(名前・英名・度数・説明文の区切り・フラグ・動画・画像)を返す', function () {
    $n = brandDetailTree();
    $brand = brandDetailBrand($n['ホワイトラム'], 'バカルディ', [
        'name_eng' => 'Bacardi', 'volume' => 40, 'description' => 'キューバ生まれ。',
        'is_major' => true, 'is_like' => true,
        'youtube' => 'abcdefghijk', 'youtube_start_seconds' => 61,
        'image' => 'bacardi.jpg', 'image_txt' => '出典', 'image_url' => 'https://example.com/',
    ]);

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $brand->id)
        ->assertJsonPath('data.name', 'バカルディ')
        ->assertJsonPath('data.name_eng', 'Bacardi')
        ->assertJsonPath('data.strength', 40)
        ->assertJsonPath('data.description', [['type' => 'text', 'text' => 'キューバ生まれ。']])
        ->assertJsonPath('data.is_major', true)
        ->assertJsonPath('data.is_like', true)
        ->assertJsonPath('data.youtube', 'abcdefghijk')
        ->assertJsonPath('data.youtube_start_seconds', 61)
        ->assertJsonPath('data.image', 'bacardi.jpg')
        ->assertJsonPath('data.image_txt', '出典')
        ->assertJsonPath('data.image_url', 'https://example.com/');
});

it('TC-43-02: 説明文の参照は、リンクの区切りにして返す', function () {
    $n = brandDetailTree();
    $other = brandDetailBrand($n['ダークラム'], 'マイヤーズ');
    $brand = brandDetailBrand($n['ホワイトラム'], 'バカルディ', ['description' => "比べるなら@b{$other->id}@"]);

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.description', [
            ['type' => 'text', 'text' => '比べるなら'],
            ['type' => 'brand', 'id' => $other->id, 'text' => 'マイヤーズ', 'has_youtube' => false],
        ]);
});

it('TC-43-03: 説明文が無ければ null', function () {
    $n = brandDetailTree();
    $brand = brandDetailBrand($n['ホワイトラム'], 'バカルディ', ['description' => null]);

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.description', null);
});

it('TC-43-04: パンくずは、根から所属ノードまで', function () {
    $n = brandDetailTree();
    $brand = brandDetailBrand($n['ホワイトラム'], 'バカルディ');

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.path', [
            brandDetailNodeRef($n['蒸留酒']),
            brandDetailNodeRef($n['ラム']),
            brandDetailNodeRef($n['ホワイトラム']),
        ]);
});

it('TC-43-05: 同じノードのほかの銘柄は、所属ノードに直接ぶら下がる銘柄から自分を除いたもの', function () {
    $n = brandDetailTree();
    $before = brandDetailBrand($n['ホワイトラム'], 'ロンリコ');
    $brand = brandDetailBrand($n['ホワイトラム'], 'バカルディ');
    $after = brandDetailBrand($n['ホワイトラム'], 'ハバナクラブ');
    brandDetailBrand($n['プレミアム・ホワイトラム'], 'プレミアム');
    brandDetailBrand($n['ラム'], '親のラム');
    brandDetailBrand($n['ダークラム'], 'マイヤーズ');

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        // 並びは ID の順。子・親・兄弟のノードの銘柄は入らない
        ->assertJsonPath('data.same_node_brands.*.id', [$before->id, $after->id]);
});

it('TC-43-06: 同じノードにほかの銘柄が無ければ、空の並び', function () {
    $n = brandDetailTree();
    $brand = brandDetailBrand($n['ダークラム'], 'マイヤーズ');
    brandDetailBrand($n['ラム'], '親のラム');

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.same_node_brands', []);
});

it('TC-43-07: 親ノードの配下の銘柄は、兄弟のノードの銘柄で、同じノード・別の枝の銘柄は入らない', function () {
    $n = brandDetailTree();
    $brand = brandDetailBrand($n['ホワイトラム'], 'バカルディ');
    brandDetailBrand($n['ホワイトラム'], 'ロンリコ');
    $sibling = brandDetailBrand($n['ダークラム'], 'マイヤーズ');
    brandDetailBrand($n['ジン'], 'ビーフィーター');

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.parent_subtree_brands.*.id', [$sibling->id]);
});

it('TC-43-08: 親ノードの配下の銘柄には、親ノードに直接ぶら下がる銘柄も入る', function () {
    $n = brandDetailTree();
    $brand = brandDetailBrand($n['ホワイトラム'], 'バカルディ');
    $parentOwn = brandDetailBrand($n['ラム'], '親のラム');
    $sibling = brandDetailBrand($n['ダークラム'], 'マイヤーズ');

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.parent_subtree_brands.*.id', [$parentOwn->id, $sibling->id]);
});

it('TC-43-09: 親ノードの配下の銘柄には、所属ノードの子孫のノードの銘柄も入る', function () {
    $n = brandDetailTree();
    $brand = brandDetailBrand($n['ホワイトラム'], 'バカルディ');
    $deep = brandDetailBrand($n['樽熟成ホワイトラム'], '樽のラム');
    $sibling = brandDetailBrand($n['ダークラム'], 'マイヤーズ');

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        // 並びは ID の順
        ->assertJsonPath('data.parent_subtree_brands.*.id', [$deep->id, $sibling->id]);
});

it('TC-43-10: いちばん上の段の銘柄では、親は大元なので、自分のノードの配下の銘柄だけが入り、ジンなど大元の配下のほかの銘柄は入らない', function () {
    $n = brandDetailTree();
    $brand = brandDetailBrand($n['ラム'], 'ラムの銘柄');
    $same = brandDetailBrand($n['ラム'], 'ラムの銘柄 2');
    $white = brandDetailBrand($n['ホワイトラム'], 'バカルディ');
    brandDetailBrand($n['ジン'], 'ビーフィーター');

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.same_node_brands.*.id', [$same->id])
        ->assertJsonPath('data.parent_subtree_brands.*.id', [$white->id]);
});

it('TC-43-11: 根(大元)に所属する銘柄では、親が無いので親ノードの配下の銘柄は空', function () {
    $n = brandDetailTree();
    $brand = brandDetailBrand($n['蒸留酒'], '根の銘柄');
    brandDetailBrand($n['ラム'], 'ラムの銘柄');

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.path', [brandDetailNodeRef($n['蒸留酒'])])
        ->assertJsonPath('data.parent_subtree_brands', []);
});

it('TC-43-12: 一覧の銘柄 1 件は、名前・英名・度数・動画と、所属ノードの根からのパンくずを返す', function () {
    $n = brandDetailTree();
    $brand = brandDetailBrand($n['ダークラム'], 'マイヤーズ');
    $deep = brandDetailBrand($n['樽熟成ホワイトラム'], '樽のラム', [
        'name_eng' => 'Aged Rum', 'volume' => 40.5, 'youtube' => 'zyxwvutsrqp',
    ]);

    $this->getJson("/api/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.parent_subtree_brands', [[
            'id' => $deep->id,
            'name' => '樽のラム',
            'name_eng' => 'Aged Rum',
            'strength' => 40.5,
            'youtube' => 'zyxwvutsrqp',
            'path' => [
                brandDetailNodeRef($n['蒸留酒']),
                brandDetailNodeRef($n['ラム']),
                brandDetailNodeRef($n['ホワイトラム']),
                brandDetailNodeRef($n['プレミアム・ホワイトラム']),
                brandDetailNodeRef($n['樽熟成ホワイトラム']),
            ],
        ]]);
});

it('TC-43-13: 無い銘柄の ID は 404', function () {
    $this->getJson('/api/brands/999999')->assertNotFound();
});

it('TC-43-14: 経路に合わない ID(数字でない・19 桁)は 404', function () {
    $this->getJson('/api/brands/abc')->assertNotFound();
    $this->getJson('/api/brands/1234567890123456789')->assertNotFound();
});
