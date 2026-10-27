<?php

use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Method;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 同じ名前の別のカクテルの API(GET /api/cocktails/{id}/same-name。#40)。
// 期待値は、旧システム(手元で動かした旧の getSameNameCocktail)の応答と、docs/decisions/cocktail-detail.md の
// 旧との差分から置く。

uses(RefreshDatabase::class)->group('db');

/**
 * 英名を決めてカクテルを作る。
 *
 * @param  array<string, mixed>  $attrs
 */
function sameNameCocktail(?string $nameEng, array $attrs = []): Cocktail
{
    return Cocktail::factory()->create(['name_eng' => $nameEng, ...$attrs]);
}

/**
 * 同じ名前の API が、$expected のカクテルを、この順で返すことを確かめる。
 *
 * @param  list<Cocktail>  $expected
 */
function assertSameNames(TestCase $test, Cocktail $cocktail, array $expected): void
{
    $test->getJson("/api/cocktails/{$cocktail->id}/same-name")
        ->assertOk()
        ->assertJsonPath('data.*.id', array_map(fn (Cocktail $c): int => $c->id, $expected));
}

it('TC-40-13: 英名が同じほかのカクテルを、自分を除いて ID の順で返す', function () {
    // 旧の A.B.C.(466・2835・2836)と同じ形。2836 を開くと 466・2835 が出る
    $first = sameNameCocktail('A.B.C.');
    $other = sameNameCocktail('Abbey');
    $second = sameNameCocktail('A.B.C.');
    $third = sameNameCocktail('A.B.C.');

    assertSameNames($this, $third, [$first, $second]);
    assertSameNames($this, $other, []);
});

it('TC-40-14: 1 件ごとに、一覧の表に出す項目を返す', function () {
    $tree = app(CategoryTree::class);
    $gin = $tree->addNode(null, Category::factory()->make(['name' => 'ジン', 'name_eng' => 'Gin'])->getAttributes());
    $shake = Method::factory()->create(['name' => 'シェイク', 'name_eng' => 'Shake']);
    $target = sameNameCocktail('Sundown', ['base_category_id' => $gin->id]);
    $same = sameNameCocktail('Sundown', [
        'name' => 'サンダウン', 'base_category_id' => $gin->id, 'method_id' => $shake->id,
        'volume' => 32, 'sweet_degree' => null, 'rate' => null, 'youtube' => null, 'is_major' => false,
    ]);

    $this->getJson("/api/cocktails/{$target->id}/same-name")
        ->assertOk()
        ->assertJsonPath('data.0', [
            'id' => $same->id,
            'name' => 'サンダウン',
            'name_eng' => 'Sundown',
            'is_major' => false,
            'base' => ['id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin'],
            'method' => ['id' => $shake->id, 'name' => 'シェイク'],
            'recipe_count' => 0,
            'strength' => 32,
            'sweet_degree' => null,
            'rate' => null,
            'youtube' => null,
            'updated_at' => $same->updated_at?->toJSON(),
        ]);
});

it('TC-40-15: 下書きのカクテルは、同じ英名でも返さない', function () {
    // 旧の Sundown(6199 公開・6200 下書き)と同じ形
    $published = sameNameCocktail('Sundown');
    sameNameCocktail('Sundown', ['is_draft' => true]);

    assertSameNames($this, $published, []);
});

it('TC-40-16: 下書きのカクテルを指定すると 404', function () {
    sameNameCocktail('Sundown');
    $draft = sameNameCocktail('Sundown', ['is_draft' => true]);

    $this->getJson("/api/cocktails/{$draft->id}/same-name")->assertNotFound();
});

it('TC-40-17: 無い ID は 404', function () {
    $this->getJson('/api/cocktails/999999/same-name')->assertNotFound();
});

it('TC-40-18: 英名の無い(null・空の)カクテルは、ほかに英名の無いカクテルがあっても 0 件', function (?string $nameEng) {
    $target = sameNameCocktail($nameEng);
    sameNameCocktail($nameEng);

    assertSameNames($this, $target, []);
})->with(['null' => [null], '空の文字' => ['']]);
