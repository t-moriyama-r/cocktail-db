<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Models\Method;
use App\Models\User;
use App\Services\CategoryTree;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

// 管理 API のノードの削除で、ほかのカクテル・ノード・技法・銘柄・用語の説明文から `@n<ID>@` で参照されている
// ノードを 409 で消させないこと(#629。カクテルの #579 と同じ作り)。ノードの削除は旧に無い。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Storage::fake('public');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function categoryDeleteRefNode(?Category $parent, string $name, array $attributes = []): Category
{
    return app(CategoryTree::class)->addNode($parent, Category::factory()->make([
        'name' => $name,
        'name_search' => NameNormalizer::normalize($name),
        ...$attributes,
    ])->getAttributes());
}

/**
 * 消そうとする葉のノード(洋酒 ── ホワイトラム)。画像のファイルを持つ。
 *
 * @return array{root: Category, target: Category}
 */
function categoryDeleteRefTarget(): array
{
    $root = categoryDeleteRefNode(null, '洋酒');
    $target = categoryDeleteRefNode($root, 'ホワイトラム', ['description' => 'サトウキビから作る', 'image' => 'white-rum.png']);
    Storage::disk('public')->put('white-rum.png', 'x');

    return ['root' => $root, 'target' => $target];
}

/**
 * $target が消えずに残っていること(行・閉包表の行・画像のファイル)。
 */
function categoryDeleteRefRemains(Category $target): void
{
    expect(Category::query()->whereKey($target->id)->exists())->toBeTrue()
        ->and(CategoryClosure::query()->where('descendant_id', $target->id)->count())->toBe(2);
    Storage::disk('public')->assertExists('white-rum.png');
}

it('TC-629-01: ほかのカクテル・ノード・技法・銘柄・用語の説明文から参照されているノードは消せず、409 で理由と参照している行を返す', function (string $kind, string $format, string $code, string $message) {
    ['root' => $root, 'target' => $target] = categoryDeleteRefTarget();
    $text = sprintf($format, $target->id);
    $referrer = match ($kind) {
        'cocktail_description' => Cocktail::factory()->create(['name' => 'ダイキリ', 'description' => $text]),
        'cocktail_note' => Cocktail::factory()->create(['name' => 'ダイキリ', 'note' => $text]),
        'cocktail_draft' => Cocktail::factory()->create(['name' => 'ダイキリ', 'is_draft' => true, 'description' => $text]),
        'node' => categoryDeleteRefNode($root, 'ダイキリ', ['description' => $text]),
        'method' => Method::factory()->create(['name' => 'ダイキリ', 'description' => $text]),
        'brand' => Brand::factory()->create(['category_id' => $root->id, 'name' => 'ダイキリ', 'description' => $text]),
        'dictionary' => Dictionary::factory()->create(['name' => 'ダイキリ', 'description' => $text]),
        default => throw new LogicException("知らない種類: {$kind}"),
    };
    $expected = [['code' => $code, 'message' => $message, 'referrers' => [['id' => $referrer->id, 'name' => 'ダイキリ']]]];

    $this->deleteJson("/api/admin/categories/{$target->id}")
        ->assertConflict()
        ->assertExactJson(['message' => 'このノードは消せません', 'reasons' => $expected]);

    categoryDeleteRefRemains($target);
    $this->getJson("/api/admin/categories/{$target->id}")->assertJsonPath('data.delete_blockers', $expected);
})->with([
    'カクテルの説明' => ['cocktail_description', '@n%d@で作る', 'referenced_by_cocktail', 'カクテルの説明から参照されています'],
    'カクテルの補足' => ['cocktail_note', '@n%d@で作る', 'referenced_by_cocktail', 'カクテルの説明から参照されています'],
    '下書きのカクテルから' => ['cocktail_draft', '@n%d@で作る', 'referenced_by_cocktail', 'カクテルの説明から参照されています'],
    '大文字の記法' => ['cocktail_description', '@N%d@で作る', 'referenced_by_cocktail', 'カクテルの説明から参照されています'],
    'ほかのノード' => ['node', '@n%d@で作る', 'referenced_by_node', 'ほかのノードの説明から参照されています'],
    '技法' => ['method', '@n%d@で作る', 'referenced_by_method', '技法の説明から参照されています'],
    '銘柄' => ['brand', '@n%d@で作る', 'referenced_by_brand', '銘柄の説明から参照されています'],
    '用語' => ['dictionary', '@n%d@で作る', 'referenced_by_dictionary', '用語の説明から参照されています'],
]);

it('TC-629-02: 木の形の理由と、いくつもの種類・行からの参照があるときは、理由を決まった順に、行を ID の順にすべて返す', function () {
    ['root' => $root, 'target' => $target] = categoryDeleteRefTarget();
    $ref = "@n{$target->id}@";
    Brand::factory()->create(['category_id' => $target->id, 'name' => 'バカルディ']);
    $term = Dictionary::factory()->create(['name' => '用語', 'description' => $ref]);
    $second = Cocktail::factory()->create(['name' => 'B', 'note' => $ref]);
    $first = Cocktail::factory()->create(['name' => 'A', 'description' => $ref]);
    $node = categoryDeleteRefNode($root, 'ゴールドラム', ['description' => $ref]);
    // 名前の順ではなく ID の順に並ぶことを見るため、B を先に作る
    expect($first->id)->toBeGreaterThan($second->id);
    $expected = [
        ['code' => 'brands', 'message' => 'このノードの銘柄があります'],
        ['code' => 'referenced_by_cocktail', 'message' => 'カクテルの説明から参照されています', 'referrers' => [
            ['id' => $second->id, 'name' => 'B'],
            ['id' => $first->id, 'name' => 'A'],
        ]],
        ['code' => 'referenced_by_node', 'message' => 'ほかのノードの説明から参照されています', 'referrers' => [['id' => $node->id, 'name' => 'ゴールドラム']]],
        ['code' => 'referenced_by_dictionary', 'message' => '用語の説明から参照されています', 'referrers' => [['id' => $term->id, 'name' => '用語']]],
    ];

    $this->deleteJson("/api/admin/categories/{$target->id}")
        ->assertConflict()
        ->assertExactJson(['message' => 'このノードは消せません', 'reasons' => $expected]);

    categoryDeleteRefRemains($target);
    $this->getJson("/api/admin/categories/{$target->id}")->assertJsonPath('data.delete_blockers', $expected);
});

it('TC-629-03: 説明文から参照されていないノードは 204 で消せる(自分の説明からの参照と、参照にならない似た文字は数えない)', function (string $column, string $format) {
    ['root' => $root, 'target' => $target] = categoryDeleteRefTarget();
    if ($column === 'self') {
        $target->update(['description' => sprintf($format, $target->id, $root->id)]);
    } else {
        Cocktail::factory()->create([$column => sprintf($format, $target->id, $root->id)]);
    }

    $this->getJson("/api/admin/categories/{$target->id}")->assertJsonPath('data.delete_blockers', []);
    $this->deleteJson("/api/admin/categories/{$target->id}")->assertNoContent();

    expect(Category::query()->whereKey($target->id)->exists())->toBeFalse()
        ->and(CategoryClosure::query()->where('descendant_id', $target->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing('white-rum.png');
})->with([
    '参照が無い' => ['description', 'ラムベースの定番'],
    '自分の説明からの参照だけ' => ['self', '@n%d@の一種'],
    'ほかのノードへの参照' => ['description', '@n%2$d@で作る'],
    'ID の桁が違う' => ['description', '@n%d0@で作る'],
    'ほかの種類の記法' => ['note', '@c%d@・@b%1$d@・@d%1$d@'],
    '前の記法と @ を分け合い、参照にならない' => ['description', '@n%2$d@n%1$d@'],
]);
