<?php

use App\Enums\AffiliateNotFoundReason;
use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

// 行はあるが商品の ID を持たない提供元を、名前で探し直す(affiliates:collect --new。#696)。
// 本物の API は呼ばない(preventStrayRequests)。差し替えるのは HTTP(Http::fake)・時刻・待ち(Sleep::fake)だけ。
// Amazon・楽天の応答は、#248 のテストと同じ保存した応答(tests/Fixtures/。架空の値)。
// 旧の収集(AffiliateController@main)は Amazon の旧 API(PA-API 5)・楽天の旧版の API を呼ぶもので、手元では動かせない。

uses(RefreshDatabase::class)->group('db');

const RESEARCH_NOW = '2026-11-06 12:00:00';

const RESEARCH_AMAZON_TOKEN_URL = 'https://api.amazon.co.jp/auth/o2/token';

const RESEARCH_AMAZON_SEARCH_URL = 'https://creatorsapi.amazon/catalog/v1/searchItems';

const RESEARCH_RAKUTEN_SEARCH_URL = 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260701';

const RESEARCH_AMAZON_SETTINGS = [
    'amazon.credential_id' => 'test-credential-id',
    'amazon.credential_secret' => 'test-credential-secret',
    'amazon.partner_tag' => 'example-22',
];

const RESEARCH_RAKUTEN_SETTINGS = [
    'rakuten.application_id' => 'test-application-id',
    'rakuten.access_key' => 'test-access-key',
    'rakuten.affiliate_id' => 'g00test0.example0.g00test0.example1',
];

/** 保存した応答の先頭の商品(Amazon の search-items・楽天の search) */
const RESEARCH_FOUND_ASIN = 'B000TEST01';

const RESEARCH_FOUND_ITEM_CODE = 'example-shop:10000001';

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    config()->set('settings', [...RESEARCH_AMAZON_SETTINGS, ...RESEARCH_RAKUTEN_SETTINGS]);
    $this->travelTo(Carbon::parse(RESEARCH_NOW));
});

/**
 * @return array<mixed>
 */
function researchFixture(string $path): array
{
    $json = file_get_contents(base_path("tests/Fixtures/{$path}.json"));
    $decoded = is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : null;

    return is_array($decoded) ? $decoded : throw new RuntimeException("保存した応答を読めない: {$path}");
}

/**
 * Amazon と楽天の名前での検索の応答を差し替える。$amazon・$rakuten は、検索のたびに呼ぶ応答の作り方(検索の語を受ける)。
 */
function researchFake(?Closure $amazon = null, ?Closure $rakuten = null): void
{
    $amazon ??= fn () => Http::response(researchFixture('amazon-creators-api/search-items'));
    $rakuten ??= fn () => Http::response(researchFixture('rakuten-ichiba-item-search/search'));
    Http::fake([
        RESEARCH_AMAZON_TOKEN_URL => Http::response(['access_token' => 'token-1', 'token_type' => 'bearer', 'expires_in' => 3600]),
        RESEARCH_AMAZON_SEARCH_URL => fn (Request $request) => $amazon($request->data()['keywords'] ?? null),
        RESEARCH_RAKUTEN_SEARCH_URL.'*' => function (Request $request) use ($rakuten) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $rakuten($query['keyword'] ?? null);
        },
    ]);
}

/**
 * 送った名前での検索を、送った順に [提供元, 検索の語] で返す。
 *
 * @return list<array{0: string, 1: mixed}>
 */
function researchSearches(): array
{
    $searches = [];
    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        if ($request->url() === RESEARCH_AMAZON_SEARCH_URL) {
            $searches[] = ['amazon', $request->data()['keywords'] ?? null];
        } elseif (str_starts_with($request->url(), RESEARCH_RAKUTEN_SEARCH_URL)) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $searches[] = ['rakuten', $query['keyword'] ?? null];
        }
    }

    return $searches;
}

/**
 * @param  array<mixed>  $options
 */
function researchCommand(TestCase $test, array $options): PendingCommand
{
    $command = $test->artisan('affiliates:collect', $options);

    return $command instanceof PendingCommand
        ? $command
        : throw new RuntimeException('コンソールの出力を差し替えずにコマンドが動いた');
}

/**
 * 名前が $name のノードと、そのノードの行を作る。ノードは根に置かない(行があるので、#248 の新しく探す対象にはならない)。
 *
 * @param  array<string, mixed>  $values
 */
function researchRow(array $values, string $name = 'ジン'): Affiliate
{
    $node = Category::factory()->create(['name' => $name]);

    return Affiliate::factory()->create(['category_id' => $node->id, ...$values]);
}

/**
 * 取り直しで残っている Amazon の商品の値(ID あり)。
 *
 * @return array<string, mixed>
 */
function researchKeptAmazon(): array
{
    return [
        'amazon_asin' => 'B000KEEP01',
        'amazon_title' => '残っている Amazon の商品',
        'amazon_price' => 1500,
        'amazon_detail_url' => 'https://www.amazon.co.jp/dp/B000KEEP01',
        'amazon_fetched_at' => now()->subHours(2),
        'amazon_searched_at' => now()->subDays(30),
    ];
}

/**
 * 行の中身のうち、Amazon・楽天の値・探した時刻・理由の列。
 *
 * @return array<string, mixed>
 */
function researchProviderValues(Affiliate $affiliate, string $provider): array
{
    return collect($affiliate->refresh()->getAttributes())
        ->filter(fn ($value, $key) => str_starts_with((string) $key, "{$provider}_"))
        ->all();
}

it('TC-696-01: 空の行(どちらの ID も無く、まだ名前で探していない)を名前で探し直し、見つけた ID・値と探した時刻を保存し、理由を空にする', function () {
    $affiliate = researchRow([], 'サンプル ジン');
    researchFake();

    researchCommand($this, ['--new' => true])
        ->expectsOutputToContain('探し直した(Amazon: '.RESEARCH_FOUND_ASIN.'・楽天: '.RESEARCH_FOUND_ITEM_CODE.')')
        ->expectsOutputToContain('探し直した: 1 件(保存 1・行が変わっていた 0・次の回にまわした 0)')
        ->assertSuccessful();

    expect(researchSearches())->toBe([['amazon', 'サンプル ジン'], ['rakuten', 'サンプル ジン']])
        ->and(researchProviderValues($affiliate, 'amazon'))->toMatchArray([
            'amazon_asin' => RESEARCH_FOUND_ASIN,
            'amazon_price' => 1980,
            'amazon_fetched_at' => RESEARCH_NOW,
            'amazon_searched_at' => RESEARCH_NOW,
            'amazon_not_found_reason' => null,
        ])
        ->and(researchProviderValues($affiliate, 'rakuten'))->toMatchArray([
            'rakuten_item_code' => RESEARCH_FOUND_ITEM_CODE,
            'rakuten_price' => 2480,
            'rakuten_fetched_at' => RESEARCH_NOW,
            'rakuten_searched_at' => RESEARCH_NOW,
            'rakuten_not_found_reason' => null,
        ])
        ->and(Affiliate::query()->count())->toBe(1);
});

it('TC-696-02: 名前で探してから 7 日たった空の行(ちょうど 7 日を含む)は探し直し、7 日に満たない行は探さない', function (int $secondsAgo, bool $expected) {
    $affiliate = researchRow([
        'amazon_searched_at' => now()->subSeconds($secondsAgo),
        'amazon_not_found_reason' => AffiliateNotFoundReason::NoResults,
        'rakuten_searched_at' => now()->subSeconds($secondsAgo),
        'rakuten_not_found_reason' => AffiliateNotFoundReason::NoResults,
    ]);
    researchFake();

    researchCommand($this, ['--new' => true])->assertSuccessful();

    expect(researchSearches() !== [])->toBe($expected)
        ->and($affiliate->refresh()->amazon_asin)->toBe($expected ? RESEARCH_FOUND_ASIN : null)
        ->and($affiliate->amazon_searched_at?->toDateTimeString())->toBe($expected ? RESEARCH_NOW : now()->subSeconds($secondsAgo)->toDateTimeString());
})->with([
    '7 日と 1 秒前' => [7 * 86400 + 1, true],
    'ちょうど 7 日前' => [7 * 86400, true],
    '7 日に 1 秒足りない' => [7 * 86400 - 1, false],
]);

it('TC-696-03: 片方の ID だけが無い行は、ID の無い提供元だけを名前で探し、ID のある提供元の値は変えない', function () {
    $affiliate = researchRow(researchKeptAmazon());
    $before = researchProviderValues($affiliate, 'amazon');
    researchFake();

    researchCommand($this, ['--new' => true])
        ->expectsOutputToContain('探し直した(Amazon: 探していない・楽天: '.RESEARCH_FOUND_ITEM_CODE.')')
        ->assertSuccessful();

    expect(researchSearches())->toBe([['rakuten', 'ジン']])
        ->and(researchProviderValues($affiliate, 'amazon'))->toBe($before)
        ->and($affiliate->rakuten_item_code)->toBe(RESEARCH_FOUND_ITEM_CODE)
        ->and($affiliate->rakuten_searched_at?->toDateTimeString())->toBe(RESEARCH_NOW);
});

it('TC-696-04: 探し直しで見つからなかった提供元には、理由(0 件・価格が無い)と探した時刻を保存し、ID と値は空のまま', function (Closure $amazon, string $amazonReason) {
    $affiliate = researchRow([]);
    researchFake(
        amazon: $amazon,
        rakuten: fn () => Http::response(['count' => 0, 'page' => 1, 'Items' => []]),
    );

    researchCommand($this, ['--new' => true])
        ->expectsOutputToContain("探し直した(Amazon: 見つからない({$amazonReason})・楽天: 見つからない(no_results))")
        ->assertSuccessful();

    expect(researchProviderValues($affiliate, 'amazon'))->toBe([
        ...array_fill_keys(Affiliate::AMAZON_COLUMNS, null),
        'amazon_searched_at' => RESEARCH_NOW,
        'amazon_not_found_reason' => $amazonReason,
    ])
        ->and(researchProviderValues($affiliate, 'rakuten'))->toBe([
            ...array_fill_keys(Affiliate::RAKUTEN_COLUMNS, null),
            'rakuten_searched_at' => RESEARCH_NOW,
            'rakuten_not_found_reason' => 'no_results',
        ]);
})->with([
    'Amazon が 0 件(404)' => [fn () => fn () => Http::response(['type' => 'ResourceNotFoundException', 'message' => 'No results'], 404), 'no_results'],
    'Amazon の先頭に価格が無い' => [fn () => fn () => Http::response(['searchResult' => [
        'items' => [['asin' => 'B000TEST02', 'detailPageURL' => 'https://www.amazon.co.jp/dp/B000TEST02', 'itemInfo' => ['title' => ['displayValue' => '在庫切れの商品']]]],
        'searchURL' => 'https://www.amazon.co.jp/s/?field-keywords=x',
    ]]), 'no_price'],
]);

it('TC-696-05: 探し直しで時間を置いても通らない API の失敗(Amazon の 403)は、失敗の理由と探した時刻を残し、楽天は探して保存する。7 日のあいだは探し直さない', function () {
    $affiliate = researchRow([]);
    researchFake(amazon: fn () => Http::response(['type' => 'AccessDeniedException', 'message' => 'denied'], 403));

    researchCommand($this, ['--new' => true])
        ->expectsOutputToContain('探し直した(Amazon: 見つからない(api_error)・楽天: '.RESEARCH_FOUND_ITEM_CODE.')')
        ->assertSuccessful();

    expect($affiliate->refresh()->amazon_asin)->toBeNull()
        ->and($affiliate->amazon_not_found_reason)->toBe(AffiliateNotFoundReason::ApiError)
        ->and($affiliate->amazon_searched_at?->toDateTimeString())->toBe(RESEARCH_NOW)
        ->and($affiliate->rakuten_item_code)->toBe(RESEARCH_FOUND_ITEM_CODE);

    // 次の回(10 分後)は、Amazon を探してから 7 日たっていないので探さない
    $this->travel(10)->minutes();
    $before = count(researchSearches());
    researchCommand($this, ['--new' => true])
        ->expectsOutputToContain('探し直した: 0 件')
        ->assertSuccessful();

    expect(count(researchSearches()))->toBe($before);
});

it('TC-696-06: 探し直しで上限・一時的な失敗(楽天の 429)なら、行を書き換えずにこの回を終え、次の回に探し直す', function () {
    $first = researchRow(['last_viewed_at' => now()], 'A');
    $second = researchRow([], 'B');
    $rakutenCalls = 0;
    researchFake(rakuten: function () use (&$rakutenCalls) {
        $rakutenCalls++;

        return $rakutenCalls === 1
            ? Http::response(['error' => 'too_many_requests', 'error_description' => 'error'], 429)
            : Http::response(researchFixture('rakuten-ichiba-item-search/search'));
    });

    researchCommand($this, ['--new' => true])
        ->expectsOutputToContain('探し直した: 1 件(保存 0・行が変わっていた 0・次の回にまわした 1)')
        ->assertSuccessful();

    expect(researchSearches())->toBe([['amazon', 'A'], ['rakuten', 'A']])
        ->and(researchProviderValues($first, 'amazon'))->toMatchArray(['amazon_asin' => null, 'amazon_searched_at' => null])
        ->and(researchProviderValues($first, 'rakuten'))->toMatchArray(['rakuten_item_code' => null, 'rakuten_searched_at' => null])
        ->and($second->refresh()->amazon_searched_at)->toBeNull();

    $this->travel(10)->minutes();
    researchCommand($this, ['--new' => true])->assertSuccessful();

    expect($first->refresh()->rakuten_item_code)->toBe(RESEARCH_FOUND_ITEM_CODE)
        ->and($second->refresh()->rakuten_item_code)->toBe(RESEARCH_FOUND_ITEM_CODE);
});

it('TC-696-08: 手で入れた値のある行は、取れなかった理由のある提供元だけを探し直し、手で入れた値は変えない', function () {
    $human = ['human_title' => '手で入れた題名', 'human_price' => 3000, 'human_image_url' => 'https://example.com/human.jpg'];
    // Amazon は取り直しで ID が消えた(理由あり・探した時刻なし)。楽天は一度も探していない(理由なし)
    $affiliate = researchRow([...$human, 'amazon_not_found_reason' => AffiliateNotFoundReason::Gone]);
    researchFake();

    researchCommand($this, ['--new' => true])
        ->expectsOutputToContain('探し直した(Amazon: '.RESEARCH_FOUND_ASIN.'・楽天: 探していない)')
        ->assertSuccessful();

    expect(researchSearches())->toBe([['amazon', 'ジン']])
        ->and($affiliate->refresh()->amazon_asin)->toBe(RESEARCH_FOUND_ASIN)
        ->and($affiliate->amazon_not_found_reason)->toBeNull()
        ->and($affiliate->rakuten_item_code)->toBeNull()
        ->and($affiliate->rakuten_searched_at)->toBeNull()
        ->and(collect($affiliate->getAttributes())->only(array_keys($human))->all())->toBe($human);
});

it('TC-696-09: 行の無いノード・銘柄を先に探し、残りの件数で、見られた時刻の新しい行から探し直す', function () {
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, Category::factory()->make(['name' => '根'])->getAttributes());
    $node = $tree->addNode($root, Category::factory()->make(['name' => '新しいノード'])->getAttributes());
    $brand = Brand::factory()->for($node, 'category')->create(['name' => '新しい銘柄']);
    // 根の直下のノードには行を作っておき、行の無い銘柄だけを新しく探す対象にする
    Affiliate::factory()->create(['category_id' => $node->id, 'amazon_searched_at' => now(), 'rakuten_searched_at' => now()]);
    researchRow(['last_viewed_at' => null], '見られていない行');
    researchRow(['last_viewed_at' => now()->subDays(3)], '前に見られた行');
    researchRow(['last_viewed_at' => now()->subHour()], '最近見られた行');
    researchFake();

    researchCommand($this, ['--new' => true, '--limit' => '3'])
        ->expectsOutputToContain('探した: 1 件(保存 1')
        ->expectsOutputToContain('探し直した: 2 件(保存 2')
        ->assertSuccessful();

    expect(collect(researchSearches())->pluck(1)->unique()->values()->all())->toBe([$brand->name, '最近見られた行', '前に見られた行']);
});

it('TC-696-10: 認証情報の無い提供元は探し直さず、その提供元の ID だけが無い行は選ばない', function () {
    config()->set('settings', RESEARCH_AMAZON_SETTINGS);
    $rakutenOnly = researchRow(researchKeptAmazon(), 'Amazon はある行');
    $empty = researchRow([], '空の行');
    researchFake();

    researchCommand($this, ['--new' => true])
        ->expectsOutputToContain('探し直した: 1 件(保存 1')
        ->assertSuccessful();

    expect(researchSearches())->toBe([['amazon', '空の行']])
        ->and($empty->refresh()->amazon_asin)->toBe(RESEARCH_FOUND_ASIN)
        ->and($empty->rakuten_searched_at)->toBeNull()
        ->and($rakutenOnly->refresh()->rakuten_searched_at)->toBeNull();
});

it('TC-696-13: 探し直しているあいだに、その提供元の ID が入ったら(管理画面で直した、など)、その行を書き換えない', function () {
    $affiliate = researchRow([]);
    researchFake(rakuten: function () use ($affiliate) {
        Affiliate::query()->whereKey($affiliate->id)->update(['amazon_asin' => 'B000ADMIN1', 'rakuten_item_code' => 'admin-shop:1']);

        return Http::response(researchFixture('rakuten-ichiba-item-search/search'));
    });

    researchCommand($this, ['--new' => true])
        ->expectsOutputToContain('探し直しているあいだに行が変わった')
        ->assertSuccessful();

    expect($affiliate->refresh()->amazon_asin)->toBe('B000ADMIN1')
        ->and($affiliate->amazon_searched_at)->toBeNull()
        ->and($affiliate->rakuten_item_code)->toBe('admin-shop:1')
        ->and($affiliate->rakuten_searched_at)->toBeNull();
});

it('TC-696-21: 探し直す提供元があるときは、新規の対象が上限以上あっても、1 件分を探し直しに空ける', function () {
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, Category::factory()->make(['name' => '根'])->getAttributes());
    $node = $tree->addNode($root, Category::factory()->make(['name' => '行のあるノード'])->getAttributes());
    Affiliate::factory()->create(['category_id' => $node->id, 'amazon_searched_at' => now(), 'rakuten_searched_at' => now()]);
    Brand::factory()->for($node, 'category')->create(['name' => '銘柄A']);
    Brand::factory()->for($node, 'category')->create(['name' => '銘柄B']);
    researchRow(['last_viewed_at' => null], '探し直す行');
    researchFake();

    researchCommand($this, ['--new' => true, '--limit' => '2'])
        ->expectsOutputToContain('探した: 1 件(保存 1')
        ->expectsOutputToContain('探し直した: 1 件(保存 1')
        ->assertSuccessful();
});
