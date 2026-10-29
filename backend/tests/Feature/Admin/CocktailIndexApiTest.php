<?php

use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Method;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// 管理画面のカクテルの一覧の API(GET /api/admin/cocktails。#289)。
// 旧は getCocktailList(公開済み)・getCocktailDraftList(下書き)が全件を返し、並べ替えと絞り込みは画面の JS
// (旧 ListComponent.vue・Helper.aiu)がしていた。並びと絞り込みの期待値は、旧の Helper を node で動かして、
// 同じ名前を渡して出た結果(PR の表の「旧での結果」)。

uses(RefreshDatabase::class)->group('db');

/**
 * カクテルを ID を決めて作る(旧は ID の順に受け取っていたので、同じ名前の並びは ID で決まる)。
 *
 * ベースと技法は、どのカクテルも ID 1 の 1 つを使う。カクテルごとに作ると、ロールバックしても戻らない
 * AUTO_INCREMENT が進み、後に流れる、移した後の ID の続きを確かめるテスト(TC-33-14)が落ちるため。
 */
function adminListCocktail(int $id, string $name, ?string $nameEng, bool $isDraft = false): Cocktail
{
    $base = Category::query()->find(1) ?? Category::factory()->create(['id' => 1]);
    $method = Method::query()->find(1) ?? Method::factory()->create(['id' => 1]);

    return Cocktail::factory()->create([
        'id' => $id, 'name' => $name, 'name_search' => $name, 'name_eng' => $nameEng, 'is_draft' => $isDraft,
        'base_category_id' => $base->id, 'method_id' => $method->id,
    ]);
}

/**
 * 旧の Helper を動かしたときと同じ 10 件(公開済み)。
 */
function adminListLegacyFixture(): void
{
    foreach ([
        [1, 'モヒート', 'Mojito'],
        [2, '雪国', 'Yukiguni'],
        [3, 'ギムレット', 'Gimlet'],
        [4, 'まてぃーに', 'Martini'],
        [5, 'Ｂ５２', 'B-52'],
        [6, 'ぎむれっと', 'Gimlet'],
        [7, 'apple', 'Apple'],
        [8, 'Apple', 'Apple'],
        [9, 'ヴェスパー', 'Vesper'],
        [10, 'ウォッカ', 'Vodka'],
    ] as [$id, $name, $nameEng]) {
        adminListCocktail($id, $name, $nameEng);
    }
}

/**
 * @return list<int>
 */
function adminListIds(TestCase $test, string $query): array
{
    /** @var list<int> */
    return $test->getJson('/api/admin/cocktails?'.$query)->assertOk()->json('data.*.id');
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('TC-289-01: status=published は公開済みだけを返す', function () {
    adminListCocktail(1, 'モヒート', 'Mojito');
    adminListCocktail(2, 'ドラフトの酒', 'Draft', isDraft: true);
    adminListCocktail(3, 'ギムレット', 'Gimlet');

    expect(adminListIds($this, 'status=published'))->toBe([3, 1]);
});

it('TC-289-02: status=draft は下書きだけを返す', function () {
    adminListCocktail(1, 'モヒート', 'Mojito');
    adminListCocktail(2, 'ドラフトの酒', 'Draft', isDraft: true);
    adminListCocktail(3, 'ギムレット', 'Gimlet');

    expect(adminListIds($this, 'status=draft'))->toBe([2]);
});

it('TC-289-03: 1 行は id・name・name_eng・is_draft・updated_at', function () {
    Carbon::setTestNow('2026-10-02 01:30:00');
    adminListCocktail(2, 'ドラフトの酒', 'Draft', isDraft: true);
    Carbon::setTestNow();

    $this->getJson('/api/admin/cocktails?status=draft')
        ->assertOk()
        ->assertExactJson([
            'data' => [[
                'id' => 2,
                'name' => 'ドラフトの酒',
                'name_eng' => 'Draft',
                'is_draft' => true,
                'updated_at' => '2026-10-02T01:30:00.000000Z',
            ]],
            'links' => [
                'first' => url('/api/admin/cocktails?status=draft&page=1'),
                'last' => url('/api/admin/cocktails?status=draft&page=1'),
                'prev' => null,
                'next' => null,
            ],
            'meta' => [
                'current_page' => 1,
                'from' => 1,
                'last_page' => 1,
                'links' => [
                    ['url' => null, 'label' => '&laquo; Previous', 'page' => null, 'active' => false],
                    ['url' => url('/api/admin/cocktails?status=draft&page=1'), 'label' => '1', 'page' => 1, 'active' => true],
                    ['url' => null, 'label' => 'Next &raquo;', 'page' => null, 'active' => false],
                ],
                'path' => url('/api/admin/cocktails'),
                'per_page' => 50,
                'to' => 1,
                'total' => 1,
            ],
        ]);
});

it('TC-289-04: status が無い・決まった値でないときは 422', function (string $query) {
    $this->getJson('/api/admin/cocktails'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
})->with([
    '無い' => [''],
    '空' => ['?status='],
    '決まった値でない' => ['?status=all'],
]);

it('TC-289-05: 名前の昇順(カタカナはひらがな・全角の英数字は半角・大文字は小文字にそろえて比べ、同じなら ID の順)', function () {
    adminListLegacyFixture();

    // 旧の Helper.aiu の結果: [7,8,5,10,3,6,4,1,9,2]
    expect(adminListIds($this, 'status=published'))->toBe([7, 8, 5, 10, 3, 6, 4, 1, 9, 2]);
});

it('TC-289-06: q で名前・英名を絞る(先頭が一致するもの。旧と同じ結果になる語)', function (string $q, array $expected) {
    adminListLegacyFixture();

    expect(adminListIds($this, 'status=published&q='.urlencode($q)))->toBe($expected);
})->with([
    'ひらがなでカタカナの名前も' => ['ぎむ', [3, 6]],
    'カタカナでひらがなの名前も' => ['ギム', [3, 6]],
    '全角の英字で英名を' => ['ＭＯＪ', [1]],
    '英名は大文字・小文字を区別しない' => ['moj', [1]],
    '全角の英字で、ひらがなの名前の英名を' => ['ＭＡＲ', [4]],
    '名前は全角の英数字を半角にそろえる' => ['Ｂ５', [5]],
    '名前は大文字・小文字を区別する' => ['b5', []],
    '名前か英名のどちらかに当たれば出す' => ['app', [7, 8]],
    '当たらなければ空' => ['zzz', []],
    'ヴはゔにそろえる' => ['ゔぇ', [9]],
]);

it('TC-289-07: q は名前・英名の途中に含むものも出す(部分一致)', function (string $q, array $expected) {
    adminListLegacyFixture();

    expect(adminListIds($this, 'status=published&q='.urlencode($q)))->toBe($expected);
})->with([
    '名前の途中' => ['む', [3, 6]],
    '英名の途中' => ['tini', [4]],
]);

it('TC-289-08: per_page は 1〜50。範囲の外は 422', function (int $perPage, ?int $count) {
    foreach (range(1, 51) as $id) {
        adminListCocktail($id, sprintf('かくてる%02d', $id), null);
    }

    $response = $this->getJson('/api/admin/cocktails?status=published&per_page='.$perPage);

    if ($count === null) {
        $response->assertUnprocessable()->assertJsonValidationErrors('per_page');

        return;
    }

    $response->assertOk()->assertJsonCount($count, 'data')->assertJsonPath('meta.per_page', $perPage);
})->with([
    '0' => [0, null],
    '1' => [1, 1],
    '50' => [50, 50],
    '51' => [51, null],
]);

it('TC-289-09: per_page を省くと 50 件ずつ。次のページに続きを返し、絞った件数を total に返す', function () {
    foreach (range(1, 51) as $id) {
        adminListCocktail($id, sprintf('かくてる%02d', $id), null);
    }
    adminListCocktail(52, 'べつのなまえ', null);

    $this->getJson('/api/admin/cocktails?status=published&q=かくてる')
        ->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('data.0.id', 1)
        ->assertJsonPath('meta.total', 51)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('links.next', url('/api/admin/cocktails?status=published&q=%E3%81%8B%E3%81%8F%E3%81%A6%E3%82%8B&page=2'));

    expect(adminListIds($this, 'status=published&q=かくてる&page=2'))->toBe([51]);
});

it('TC-289-10: 最後のページより後は、空の data を返す', function () {
    adminListCocktail(1, 'モヒート', 'Mojito');

    $this->getJson('/api/admin/cocktails?status=published&page=2')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 1);
});

it('TC-289-11: ログインしていなければ 401', function () {
    $this->app['auth']->forgetGuards();
    adminListCocktail(1, 'モヒート', 'Mojito');

    $this->getJson('/api/admin/cocktails?status=published')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('TC-289-12: 英名が無いカクテルも、名前で絞れる。英名には当たらないものとして扱う', function () {
    adminListCocktail(1, 'ジン', null);
    adminListCocktail(2, 'ラム', null);

    expect(adminListIds($this, 'status=published&q=ジ'))->toBe([1])
        ->and(adminListIds($this, 'status=published&q=gin'))->toBe([]);
});
