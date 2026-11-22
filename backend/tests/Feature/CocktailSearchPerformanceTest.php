<?php

use App\Enums\CategoryRole;
use App\Models\Category;
use App\Services\CategoryTree;
use Database\Seeders\SearchPerformanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 条件検索の API(POST /api/cocktails/search)の性能(#96)。移行後と同じ規模のデータ(SearchPerformanceSeeder。
// カクテル 5,700・材料 27,000・ノード 600)で、重い条件の組み合わせが 300ms 以内に返ることを確かめる。
// 重い条件には、あるもの検索(#165。NOT EXISTS と閉包表)と、ノードを多く選んだ AND(#164)を必ず含める。
// 時間は、1 回目(読み込みの準備)を除いた 5 回の中央値で比べる。CI の機械の揺れで 1 回だけ遅いものを落とさないため。

uses(RefreshDatabase::class)->group('db');

/**
 * 1 つの条件の応答時間の上限(ミリ秒)。issue #96 の受け入れ条件。
 */
const SEARCH_PERF_LIMIT_MS = 300;

/**
 * 根でないノード(材料に使うノード)の ID。$roles を渡すと、その役割の根の配下に絞る(null は役割の無い根 = 洋酒)。
 *
 * @param  list<CategoryRole|null>|null  $roles
 * @return list<int>
 */
function searchPerfNodeIds(?array $roles = null): array
{
    $roots = app(CategoryTree::class)->roots()
        ->filter(fn (Category $root): bool => $roles === null || in_array($root->role, $roles, true))
        ->pluck('id');

    return searchPerfInts(DB::table('category_closures')
        ->whereIn('ancestor_id', $roots)
        ->where('depth', '>', 0)
        ->orderBy('descendant_id')
        ->pluck('descendant_id'));
}

/**
 * ID の並びを整数の list にする。
 *
 * @param  Collection<array-key, mixed>  $ids
 * @return list<int>
 */
function searchPerfInts(Collection $ids): array
{
    return array_values($ids
        ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : throw new UnexpectedValueException('ID が数でない'))
        ->all());
}

/**
 * 根の ID。
 *
 * @return list<int>
 */
function searchPerfRootIds(): array
{
    return searchPerfInts(app(CategoryTree::class)->roots()->pluck('id')->sort());
}

/**
 * 技法の ID(テストごとに採番が進むので、入れた後に読む)。
 *
 * @return list<int>
 */
function searchPerfMethodIds(): array
{
    return searchPerfInts(DB::table('methods')->orderBy('id')->pluck('id'));
}

/**
 * 条件 $body で検索の API を呼び、1 回目を除いた $runs 回の中央値(ミリ秒)を返す。どの回も 200 で返ることも確かめる。
 *
 * @param  array<string, mixed>  $body
 */
function searchPerfMedianMs(TestCase $test, array $body, int $runs = 5): float
{
    $test->postJson('/api/cocktails/search', $body)->assertOk();

    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $test->postJson('/api/cocktails/search', $body)->assertOk();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);

    return $times[intdiv($runs, 2)];
}

/**
 * 条件ごとに中央値を測り、上限を超えたものをまとめて落とす。落ちたときは、すべての条件の時間をメッセージに出す。
 *
 * @param  array<string, array<string, mixed>>  $conditions
 */
function searchPerfAssertWithinLimit(TestCase $test, array $conditions): void
{
    $lines = [];
    $slow = [];
    foreach ($conditions as $name => $body) {
        $ms = searchPerfMedianMs($test, $body);
        $lines[] = sprintf('%s: %.0fms', $name, $ms);
        if ($ms > SEARCH_PERF_LIMIT_MS) {
            $slow[] = $name;
        }
    }

    expect($slow)->toBe([], implode("\n", $lines));
}

beforeEach(function () {
    $this->seed(SearchPerformanceSeeder::class);
});

it('TC-96-01: 性能を測るデータが、移行後と同じ規模(カクテル 5,700・材料 27,000・ノード 600)になっている', function () {
    expect(DB::table('categories')->count())->toBe(SearchPerformanceSeeder::NODES)
        ->and(DB::table('cocktails')->count())->toBe(SearchPerformanceSeeder::COCKTAILS)
        ->and(DB::table('cocktail_ingredients')->count())->toBe(SearchPerformanceSeeder::INGREDIENTS)
        // 下書きは除かれるので、公開のカクテルがほぼすべて当たる条件で測れているか
        ->and($this->postJson('/api/cocktails/search', ['category_ids' => searchPerfNodeIds()])->json('meta.total'))
        ->toBeGreaterThan(5000);
});

it('TC-96-02: OR 検索の重い条件(全ノード・根だけ・並べ替え・絞り込み)が 300ms 以内に返る', function () {
    $all = searchPerfNodeIds();

    searchPerfAssertWithinLimit($this, [
        '全ノード' => ['category_ids' => $all],
        '全ノード・名前順' => ['category_ids' => $all, 'sort' => 'name'],
        '全ノード・ベースの降順' => ['category_ids' => $all, 'sort' => 'base', 'order' => 'desc'],
        '根だけ・度数順' => ['category_ids' => searchPerfRootIds(), 'sort' => 'strength'],
        '全ノード・技法・かんたん・評価順' => ['category_ids' => $all, 'method_ids' => array_slice(searchPerfMethodIds(), 0, 3), 'is_easy' => true, 'sort' => 'rate'],
    ]);
});

it('TC-96-03: ノードを多く選んだ AND 検索(まとまり 20 個)が 300ms 以内に返る', function () {
    $all = searchPerfNodeIds();
    $liquor = searchPerfNodeIds([null]);
    $roots = searchPerfRootIds();
    $max = 20;

    searchPerfAssertWithinLimit($this, [
        // 全ノードを 20 のまとまりに分ける(当たるものは少ない)
        '全ノードを 20 に分けたまとまり' => ['mode' => 'and', 'category_groups' => array_map(
            fn (int $i): array => array_values(array_filter($all, fn (int $k): bool => $k % $max === $i, ARRAY_FILTER_USE_KEY)),
            range(0, $max - 1),
        )],
        // 洋酒のノード 50 個ずつのまとまり 20 個(ID の総数が上限の 1000。どのまとまりも多くのカクテルに当たる)
        '洋酒 50 個ずつのまとまり 20 個・名前順' => ['mode' => 'and', 'sort' => 'name', 'category_groups' => array_map(
            fn (int $i): array => array_slice([...array_slice($liquor, $i * 5), ...array_slice($liquor, 0, $i * 5)], 0, 50),
            range(0, $max - 1),
        )],
        // 根(配下がすべて一致する)とノードの組 20 個
        '根とノードの組 20 個' => ['mode' => 'and', 'category_groups' => array_map(
            fn (int $i): array => [$roots[$i % count($roots)], $all[$i]],
            range(0, $max - 1),
        )],
    ]);
});

it('TC-96-04: あるもの検索の重い条件(手持ちが多い・少ない・根だけ・並べ替え・絞り込み)が 300ms 以内に返る', function () {
    $all = searchPerfNodeIds();
    $liquor = searchPerfNodeIds([null]);
    $soft = searchPerfNodeIds([CategoryRole::SoftDrink]);

    searchPerfAssertWithinLimit($this, [
        '全ノード' => ['mode' => 'having', 'category_ids' => $all],
        '全ノード・名前順' => ['mode' => 'having', 'category_ids' => $all, 'sort' => 'name'],
        '洋酒とソフトドリンク' => ['mode' => 'having', 'category_ids' => [...$liquor, ...$soft]],
        '少ない手持ち(10 個)' => ['mode' => 'having', 'category_ids' => [...array_slice($liquor, 0, 6), ...array_slice($soft, 0, 4)]],
        '根だけ' => ['mode' => 'having', 'category_ids' => searchPerfRootIds()],
        '全ノード・全技法・かんたん・ベース順' => [
            'mode' => 'having', 'category_ids' => $all, 'method_ids' => searchPerfMethodIds(), 'is_easy' => true, 'sort' => 'base',
        ],
    ]);
});
