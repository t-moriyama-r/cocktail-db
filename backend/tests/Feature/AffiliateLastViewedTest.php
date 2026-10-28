<?php

use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

// 商品の欄が見られた時刻(affiliates.last_viewed_at)を、アフィリエイト表示 API(GET /api/affiliates。#47)で残す(#250)。
// 取り直し(#249)が、見られているページから先に取り直すために使う。旧は見られた時刻を記録していない(旧に無い)。

uses(RefreshDatabase::class)->group('db');

const VIEWED_NOW = '2026-10-27 12:00:00';

beforeEach(function () {
    $this->travelTo(Carbon::parse(VIEWED_NOW));
});

/**
 * ノードと、そのノードの行を作る。
 *
 * @param  array<string, mixed>  $values
 */
function viewedNodeAffiliate(array $values = []): Affiliate
{
    $node = Category::factory()->create(['name' => 'ジン']);

    return Affiliate::factory()->create(['category_id' => $node->id, ...$values]);
}

/** DB に残った last_viewed_at(秒まで)。null なら null */
function storedLastViewedAt(Affiliate $affiliate): ?string
{
    return $affiliate->fresh()?->last_viewed_at?->toDateTimeString();
}

it('TC-250-01: 一度も見られていない行は、API を呼ぶと last_viewed_at が今の時刻になる', function () {
    $affiliate = viewedNodeAffiliate(['last_viewed_at' => null]);

    $this->getJson('/api/affiliates?category_id='.$affiliate->category_id)->assertOk();

    expect(storedLastViewedAt($affiliate))->toBe(VIEWED_NOW);
});

it('TC-250-02: 前に見られてから 1 日たっていなければ更新せず、ちょうど 1 日からは今の時刻にする', function (int $secondsAgo, bool $updated) {
    $viewedAt = Carbon::parse(VIEWED_NOW)->subSeconds($secondsAgo)->toDateTimeString();
    $affiliate = viewedNodeAffiliate(['last_viewed_at' => $viewedAt]);

    $this->getJson('/api/affiliates?category_id='.$affiliate->category_id)->assertOk();

    expect(storedLastViewedAt($affiliate))->toBe($updated ? VIEWED_NOW : $viewedAt);
})->with([
    '1 日の 1 秒前' => [24 * 3600 - 1, false],
    'ちょうど 1 日' => [24 * 3600, true],
    '1 日の 1 秒後' => [24 * 3600 + 1, true],
]);

it('TC-250-03: 1 日のうちに 2 回呼んでも、書き込むのは最初の 1 回だけ', function () {
    $affiliate = viewedNodeAffiliate(['last_viewed_at' => null]);
    $url = '/api/affiliates?category_id='.$affiliate->category_id;

    $this->getJson($url)->assertOk();
    $this->travel(23)->hours();
    $this->getJson($url)->assertOk();

    expect(storedLastViewedAt($affiliate))->toBe(VIEWED_NOW);
});

it('TC-250-04: 手で入れた値だけの行も、見られた時刻を残す', function () {
    $affiliate = viewedNodeAffiliate([
        'human_title' => '手入力の商品',
        'human_price' => 1000,
        'last_viewed_at' => null,
    ]);

    $this->getJson('/api/affiliates?category_id='.$affiliate->category_id)->assertOk();

    expect(storedLastViewedAt($affiliate))->toBe(VIEWED_NOW);
});

it('TC-250-05: 残すのは返した 1 件の行だけで、同じノードのほかの行は変えない', function () {
    $node = Category::factory()->create(['name' => 'ジン']);
    $brand = Brand::factory()->create(['category_id' => $node->id, 'name' => 'ビーフィーター']);
    $node->update(['famous_brand_id' => $brand->id]);
    $nodeRow = Affiliate::factory()->create(['category_id' => $node->id, 'last_viewed_at' => null]);
    $brandRow = Affiliate::factory()->create(['category_id' => $node->id, 'brand_id' => $brand->id, 'last_viewed_at' => null]);

    // 銘柄を指定しなくても、ノードに有名な銘柄があればその銘柄の行を返す(#47)
    $this->getJson('/api/affiliates?category_id='.$node->id)
        ->assertOk()
        ->assertJsonPath('data.brand_id', $brand->id);

    expect(storedLastViewedAt($brandRow))->toBe(VIEWED_NOW)
        ->and(storedLastViewedAt($nodeRow))->toBeNull();
});

it('TC-250-06: 見られた時刻を残しても、行の更新日時(updated_at)は変えない', function () {
    $updatedAt = '2026-10-01 09:00:00';
    $affiliate = viewedNodeAffiliate(['last_viewed_at' => null, 'updated_at' => $updatedAt]);

    $this->getJson('/api/affiliates?category_id='.$affiliate->category_id)->assertOk();

    expect($affiliate->fresh()?->updated_at?->toDateTimeString())->toBe($updatedAt);
});

it('TC-250-07: 行が無くて 404 のときは、どの行にも書き込まない', function () {
    $other = viewedNodeAffiliate(['last_viewed_at' => null]);
    $emptyNode = Category::factory()->create(['name' => 'ラム']);

    $this->getJson('/api/affiliates?category_id='.$emptyNode->id)->assertNotFound();

    expect(storedLastViewedAt($other))->toBeNull();
});
