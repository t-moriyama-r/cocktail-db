<?php

use App\Enums\CategoryRole;
use App\Models\Affiliate;
use App\Models\AffiliateCollectFailure;
use App\Models\Brand;
use App\Models\Category;
use App\Services\Affiliate\AffiliateCollector;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

// まだ商品の行が無いノード・銘柄を名前で探し、商品の ID と値を保存する(affiliates:collect --new。#248)。
// 本物の API は呼ばない(preventStrayRequests)。差し替えるのは HTTP(Http::fake)・時刻・待ち(Sleep::fake)だけ。
//
// Amazon・楽天の応答は、AmazonClient(#135)・RakutenClient(#72)のテストと同じ保存した応答
// (tests/Fixtures/amazon-creators-api/・tests/Fixtures/rakuten-ichiba-item-search/)。どちらも架空の値。
// 期待値は、旧(AffiliateController@main・AffiliateFactory::exeSearch・AmazonFactory・RakutenFactory)のソースを読んで置いた
// (旧の収集は Amazon の旧 API(PA-API 5)・楽天の旧版の API を呼ぶもので、手元では動かせない)。

uses(RefreshDatabase::class)->group('db');

const COLLECT_NOW = '2026-11-05 12:00:00';

const COLLECT_AMAZON_TOKEN_URL = 'https://api.amazon.co.jp/auth/o2/token';

const COLLECT_AMAZON_SEARCH_URL = 'https://creatorsapi.amazon/catalog/v1/searchItems';

const COLLECT_RAKUTEN_SEARCH_URL = 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260701';

const COLLECT_AMAZON_SETTINGS = [
    'amazon.credential_id' => 'test-credential-id',
    'amazon.credential_secret' => 'test-credential-secret',
    'amazon.partner_tag' => 'example-22',
];

const COLLECT_RAKUTEN_SETTINGS = [
    'rakuten.application_id' => 'test-application-id',
    'rakuten.access_key' => 'test-access-key',
    'rakuten.affiliate_id' => 'g00test0.example0.g00test0.example1',
];

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    config()->set('settings', [...COLLECT_AMAZON_SETTINGS, ...COLLECT_RAKUTEN_SETTINGS]);
    $this->travelTo(Carbon::parse(COLLECT_NOW));
});

/**
 * @return array<mixed>
 */
function collectFixture(string $path): array
{
    $json = file_get_contents(base_path("tests/Fixtures/{$path}.json"));
    assert(is_string($json));
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($decoded));

    return $decoded;
}

/**
 * Amazon と楽天の応答を差し替える。$amazon・$rakuten は、検索のたびに呼ぶ応答の作り方(検索の語と要求を受ける)。
 */
function collectFake(?Closure $amazon = null, ?Closure $rakuten = null): void
{
    $amazon ??= fn () => Http::response(collectFixture('amazon-creators-api/search-items'));
    $rakuten ??= fn () => Http::response(collectFixture('rakuten-ichiba-item-search/search'));
    Http::fake([
        COLLECT_AMAZON_TOKEN_URL => Http::response(['access_token' => 'token-1', 'token_type' => 'bearer', 'expires_in' => 3600]),
        COLLECT_AMAZON_SEARCH_URL => fn (Request $request) => $amazon($request->data()['keywords'] ?? null, $request),
        COLLECT_RAKUTEN_SEARCH_URL.'*' => function (Request $request) use ($rakuten) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $rakuten($query['keyword'] ?? null, $request);
        },
    ]);
}

/**
 * 送った検索の要求を、送った順に [提供元, 検索の語, 分類・ジャンル] で返す。
 *
 * @return list<array{0: string, 1: mixed, 2: mixed}>
 */
function collectSearches(): array
{
    $searches = [];
    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        if ($request->url() === COLLECT_AMAZON_SEARCH_URL) {
            $data = $request->data();
            $searches[] = ['amazon', $data['keywords'] ?? null, $data['searchIndex'] ?? null];
        } elseif (str_starts_with($request->url(), COLLECT_RAKUTEN_SEARCH_URL)) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $searches[] = ['rakuten', $query['keyword'] ?? null, $query['genreId'] ?? null];
        }
    }

    return $searches;
}

/**
 * affiliates:collect を、出力と終了コードを確かめられる形で呼ぶ。
 *
 * @param  array<mixed>  $options
 */
function collectCommand(TestCase $test, array $options): PendingCommand
{
    $command = $test->artisan('affiliates:collect', $options);

    return $command instanceof PendingCommand
        ? $command
        : throw new RuntimeException('コンソールの出力を差し替えずにコマンドが動いた');
}

/**
 * 根 → 根の直下(旧のカテゴリ)→ 根の 2 段下(旧のミドル)の木を作る。
 *
 * @return array{root: Category, node: Category, middle: Category}
 */
function collectTree(string $prefix = '', ?CategoryRole $role = null): array
{
    $tree = app(CategoryTree::class);
    $attrs = fn (string $name, ?CategoryRole $role = null) => Category::factory()->make(['name' => $name, 'role' => $role])->getAttributes();
    $root = $tree->addNode(null, $attrs("{$prefix}根", $role));
    $node = $tree->addNode($root, $attrs("{$prefix}ジン"));
    $middle = $tree->addNode($node, $attrs("{$prefix}ドライジン"));

    return ['root' => $root, 'node' => $node, 'middle' => $middle];
}

/**
 * 行の中身のうち、商品の値の列(ID・作成日時などを除く)。
 *
 * @return array<string, mixed>
 */
function collectValues(Affiliate $affiliate): array
{
    return collect($affiliate->getAttributes())
        ->except(['id', 'brand_key', 'created_at', 'updated_at'])
        ->all();
}

it('TC-248-01: 銘柄を名前で探し、Amazon・楽天の先頭の商品の ID・値と取った時刻を、銘柄の行に保存する', function () {
    ['middle' => $middle] = collectTree();
    $brand = Brand::factory()->for($middle, 'category')->create(['name' => 'サンプル ジン']);
    collectFake();

    collectCommand($this, ['--new' => true])->assertSuccessful();

    $affiliate = Affiliate::query()->where('brand_id', $brand->id)->sole();
    expect(collectValues($affiliate))->toEqual([
        'category_id' => $middle->id,
        'brand_id' => $brand->id,
        'amazon_asin' => 'B000TEST01',
        'amazon_title' => 'サンプル ジン 700ml',
        'amazon_price' => 1980,
        'amazon_image_large_url' => 'https://m.media-amazon.com/images/I/EXAMPLE1._SL500_.jpg',
        'amazon_image_medium_url' => 'https://m.media-amazon.com/images/I/EXAMPLE1._SL160_.jpg',
        'amazon_image_small_url' => 'https://m.media-amazon.com/images/I/EXAMPLE1._SL75_.jpg',
        'amazon_detail_url' => 'https://www.amazon.co.jp/dp/B000TEST01?tag=example-22&linkCode=osi',
        'amazon_search_url' => 'https://www.amazon.co.jp/s/?field-keywords=%E3%82%B8%E3%83%B3&search-alias=grocery&tag=example-22&linkCode=osi',
        'amazon_fetched_at' => COLLECT_NOW,
        'rakuten_item_code' => 'example-shop:10000001',
        'rakuten_title' => 'サンプル ジン 700ml',
        'rakuten_price' => 2480,
        'rakuten_image_url' => 'https://thumbnail.image.rakuten.co.jp/@0_mall/example-shop/cabinet/gin-001.jpg',
        'rakuten_detail_url' => 'https://hb.afl.rakuten.co.jp/hgc/g00test0.example0.g00test0.example1/?pc=https%3A%2F%2Fitem.rakuten.co.jp%2Fexample-shop%2Fgin-001%2F&m=http%3A%2F%2Fm.rakuten.co.jp%2Fexample-shop%2Fi%2F10000001%2F',
        'rakuten_search_url' => 'https://hb.afl.rakuten.co.jp/hgc/g00test0.example0.g00test0.example1/?pc='
            .rawurlencode('https://search.rakuten.co.jp/search/mall/').urlencode('サンプル ジン').rawurlencode('/510915/'),
        'rakuten_fetched_at' => COLLECT_NOW,
        // 名前で探した時刻と、見つからなかった理由(#696)。どちらも見つかったので、理由は空
        'amazon_searched_at' => COLLECT_NOW,
        'amazon_not_found_reason' => null,
        'rakuten_searched_at' => COLLECT_NOW,
        'rakuten_not_found_reason' => null,
        'human_title' => null,
        'human_price' => null,
        'human_image_url' => null,
        'human_amazon_detail_url' => null,
        'human_amazon_search_url' => null,
        'last_viewed_at' => null,
    ]);
});

it('TC-248-02: 根の直下のノード(有名な銘柄なし)を名前で探し、銘柄の無いノードの行に保存する', function () {
    ['node' => $node] = collectTree();
    collectFake();

    collectCommand($this, ['--new' => true])->assertSuccessful();

    $affiliate = Affiliate::query()->sole();
    expect($affiliate->category_id)->toBe($node->id)
        ->and($affiliate->brand_id)->toBeNull()
        ->and($affiliate->amazon_asin)->toBe('B000TEST01')
        ->and($affiliate->rakuten_item_code)->toBe('example-shop:10000001')
        ->and(collectSearches())->toBe([
            ['amazon', 'ジン', 'GroceryAndGourmetFood'],
            ['rakuten', 'ジン', '510915'],
        ]);
});

it('TC-248-03: 行のある銘柄・ノード、有名な銘柄のあるノード、根、根の 2 段下のノードは探さない', function () {
    ['root' => $root, 'node' => $node, 'middle' => $middle] = collectTree('A');
    ['node' => $famousNode] = collectTree('B');
    $famous = Brand::factory()->for($famousNode, 'category')->create(['name' => '有名な銘柄']);
    $famousNode->update(['famous_brand_id' => $famous->id]);
    // 行は、名前で探したばかり(7 日たっていない)の空の行にする。7 日たった空の行は探し直す(#696。TC-696-01)
    $searched = ['amazon_searched_at' => now(), 'rakuten_searched_at' => now()];
    Affiliate::query()->create(['category_id' => $famousNode->id, 'brand_id' => $famous->id, ...$searched]);
    $collected = Brand::factory()->for($middle, 'category')->create(['name' => '行のある銘柄']);
    Affiliate::query()->create(['category_id' => $middle->id, 'brand_id' => $collected->id, ...$searched]);
    Affiliate::query()->create(['category_id' => $node->id, 'brand_id' => null, ...$searched]);
    collectFake();

    collectCommand($this, ['--new' => true, '--limit' => '10'])
        ->expectsOutputToContain('探した: 0 件')
        ->assertSuccessful();

    expect(collectSearches())->toBe([])
        ->and(Affiliate::query()->count())->toBe(3)
        ->and(Affiliate::query()->whereIn('category_id', [$root->id, $middle->id])->whereNull('brand_id')->exists())->toBeFalse();
});

it('TC-248-04: 銘柄 → ノードの順に、それぞれ ID の順で、--limit の件数まで探す', function () {
    $nodes = [collectTree('A')['node'], collectTree('B')['node'], collectTree('C')['node']];
    $brandB = Brand::factory()->for($nodes[0], 'category')->create(['name' => '銘柄B']);
    $brandA = Brand::factory()->for($nodes[0], 'category')->create(['name' => '銘柄A']);
    collectFake();

    collectCommand($this, ['--new' => true, '--limit' => '3'])
        ->expectsOutputToContain('探した: 3 件(保存 3')
        ->assertSuccessful();

    expect(array_column(collectSearches(), 1))->toBe(['銘柄B', '銘柄B', '銘柄A', '銘柄A', 'Aジン', 'Aジン'])
        ->and(Affiliate::query()->orderBy('id')->get(['category_id', 'brand_id'])->toArray())->toBe([
            ['category_id' => $nodes[0]->id, 'brand_id' => $brandB->id],
            ['category_id' => $nodes[0]->id, 'brand_id' => $brandA->id],
            ['category_id' => $nodes[0]->id, 'brand_id' => null],
        ]);
});

it('TC-248-05: --limit を省くと 5 件まで探す', function () {
    $node = collectTree()['node'];
    Brand::factory()->count(6)->for($node, 'category')->create();
    collectFake();

    collectCommand($this, ['--new' => true])->assertSuccessful();

    expect(Affiliate::query()->count())->toBe(AffiliateCollector::DEFAULT_NEW_LIMIT)
        ->and(AffiliateCollector::DEFAULT_NEW_LIMIT)->toBe(5);
});

it('TC-248-06: Amazon の先頭の商品に価格が無い・楽天が 0 件のときは、どちらも見つからない扱いで、空の行を作る', function () {
    $node = collectTree()['node'];
    // 先頭を、価格の無い商品(在庫切れ)にする。旧は先頭が Offers を持たなければ、次の商品を見ずに見つからない扱いにしていた
    $amazon = ['searchResult' => [
        'items' => [
            ['asin' => 'B000TEST02', 'detailPageURL' => 'https://www.amazon.co.jp/dp/B000TEST02', 'itemInfo' => ['title' => ['displayValue' => '在庫切れの商品']]],
            ['asin' => 'B000TEST03', 'detailPageURL' => 'https://www.amazon.co.jp/dp/B000TEST03', 'offersV2' => ['listings' => [['price' => ['money' => ['amount' => 1500, 'currency' => 'JPY']]]]]],
        ],
        'searchURL' => 'https://www.amazon.co.jp/s/?field-keywords=x',
    ]];
    collectFake(
        amazon: fn () => Http::response($amazon),
        rakuten: fn () => Http::response(['count' => 0, 'page' => 1, 'Items' => []]),
    );

    collectCommand($this, ['--new' => true])
        ->expectsOutputToContain('保存した(Amazon: 見つからない・楽天: 見つからない)')
        ->assertSuccessful();

    // 商品の ID と値は空。名前で探した時刻と、見つからなかった理由(#696)だけが入る
    $affiliate = Affiliate::query()->sole();
    expect($affiliate->category_id)->toBe($node->id)
        ->and(collect(collectValues($affiliate))->except(['category_id'])->filter(fn ($value) => $value !== null)->all())->toBe([
            'amazon_searched_at' => COLLECT_NOW,
            'amazon_not_found_reason' => 'no_price',
            'rakuten_searched_at' => COLLECT_NOW,
            'rakuten_not_found_reason' => 'no_results',
        ]);
});

it('TC-248-07: Amazon が 1 件も見つけない(404)ときも、楽天で見つけた商品を保存する', function () {
    collectTree();
    collectFake(amazon: fn () => Http::response(['type' => 'ResourceNotFoundException', 'message' => 'No results'], 404));

    collectCommand($this, ['--new' => true])->assertSuccessful();

    $affiliate = Affiliate::query()->sole();
    expect($affiliate->amazon_asin)->toBeNull()
        ->and($affiliate->amazon_search_url)->toBeNull()
        ->and($affiliate->amazon_fetched_at)->toBeNull()
        ->and($affiliate->rakuten_item_code)->toBe('example-shop:10000001')
        ->and($affiliate->rakuten_fetched_at?->toDateTimeString())->toBe(COLLECT_NOW);
});

it('TC-248-08: 楽天は「洋酒ではない」根(ソフトドリンク・その他)の下のノードでも、ジャンル「ビール・洋酒」で絞って探す', function () {
    collectTree('S', CategoryRole::SoftDrink);
    collectTree('O', CategoryRole::Other);
    collectFake();

    collectCommand($this, ['--new' => true])->assertSuccessful();

    expect(collectSearches())->toBe([
        ['amazon', 'Sジン', 'GroceryAndGourmetFood'],
        ['rakuten', 'Sジン', '510915'],
        ['amazon', 'Oジン', 'GroceryAndGourmetFood'],
        ['rakuten', 'Oジン', '510915'],
    ]);
});

it('TC-248-09: 手で入れた値だけの行があるノード・銘柄は探さず、手で入れた値はそのまま残る', function () {
    $node = collectTree()['node'];
    $brand = Brand::factory()->for($node, 'category')->create();
    $human = [
        'human_title' => '手で入れた商品',
        'human_price' => 1234,
        'human_image_url' => 'https://example.com/human.jpg',
        'human_amazon_detail_url' => 'https://www.amazon.co.jp/dp/B0HUMAN001',
        'human_amazon_search_url' => 'https://www.amazon.co.jp/s?k=human',
    ];
    Affiliate::query()->create(['category_id' => $node->id, 'brand_id' => null, ...$human]);
    Affiliate::query()->create(['category_id' => $node->id, 'brand_id' => $brand->id, ...$human]);
    collectFake();

    collectCommand($this, ['--new' => true])->assertSuccessful();

    expect(collectSearches())->toBe([]);
    foreach (Affiliate::query()->get() as $affiliate) {
        expect(collect(collectValues($affiliate))->only(array_keys($human))->all())->toBe($human)
            ->and($affiliate->amazon_asin)->toBeNull()
            ->and($affiliate->rakuten_item_code)->toBeNull();
    }
});

it('TC-248-10: 探しているあいだに手で入れた行ができたら、その行を書き換えない', function () {
    $node = collectTree()['node'];
    collectFake(rakuten: function () use ($node) {
        // 楽天に問い合わせているあいだに、管理画面で手で入れた値の行が作られた
        Affiliate::query()->create(['category_id' => $node->id, 'brand_id' => null, 'human_title' => '手で入れた商品', 'human_price' => 1234]);

        return Http::response(collectFixture('rakuten-ichiba-item-search/search'));
    });

    collectCommand($this, ['--new' => true])
        ->expectsOutputToContain('探しているあいだに行ができていたので、書き換えない')
        ->assertSuccessful();

    $affiliate = Affiliate::query()->sole();
    expect($affiliate->human_title)->toBe('手で入れた商品')
        ->and($affiliate->human_price)->toBe(1234)
        ->and($affiliate->amazon_asin)->toBeNull()
        ->and($affiliate->rakuten_item_code)->toBeNull();
});

it('TC-248-11: Amazon・楽天とも認証情報が無ければ、どこにも問い合わせず、行も作らない', function () {
    collectTree();
    config()->set('settings', []);
    collectFake();

    collectCommand($this, ['--new' => true])
        ->expectsOutputToContain('Amazon・楽天とも認証情報が設定に無いので、何もしない')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(Affiliate::query()->count())->toBe(0);
});

it('TC-248-12: Amazon の認証情報が無ければ Amazon には問い合わせず、楽天だけで探して保存する', function () {
    collectTree();
    config()->set('settings', COLLECT_RAKUTEN_SETTINGS);
    collectFake();

    collectCommand($this, ['--new' => true])->assertSuccessful();

    $affiliate = Affiliate::query()->sole();
    expect(collectSearches())->toBe([['rakuten', 'ジン', '510915']])
        ->and(Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://api.amazon.co.jp/'))->count())->toBe(0)
        ->and($affiliate->amazon_asin)->toBeNull()
        ->and($affiliate->rakuten_item_code)->toBe('example-shop:10000001');
});

it('TC-248-13: 楽天の認証情報が無ければ楽天には問い合わせず、Amazon だけで探して保存する', function () {
    collectTree();
    config()->set('settings', COLLECT_AMAZON_SETTINGS);
    collectFake();

    collectCommand($this, ['--new' => true])->assertSuccessful();

    $affiliate = Affiliate::query()->sole();
    expect(collectSearches())->toBe([['amazon', 'ジン', 'GroceryAndGourmetFood']])
        ->and($affiliate->amazon_asin)->toBe('B000TEST01')
        ->and($affiliate->rakuten_item_code)->toBeNull();
});

it('TC-248-14: 楽天が 429 を返したら、その件を保存せずにこの回を終え、次の回に同じ件を探して保存する', function () {
    $nodes = [collectTree('A')['node'], collectTree('B')['node']];
    // 1 回目の楽天の検索だけ 429 にする(2 回目の回では上限が解けている)
    $rakutenResponses = Http::sequence()
        ->push(['error' => 'too_many_requests', 'error_description' => 'number of allowed requests has been exceeded for this API. please try again soon.'], 429)
        ->push(collectFixture('rakuten-ichiba-item-search/search'))
        ->push(collectFixture('rakuten-ichiba-item-search/search'));
    collectFake(rakuten: fn (mixed $keyword, Request $request) => $rakutenResponses($request));

    collectCommand($this, ['--new' => true])
        ->expectsOutputToContain('次の回にまわす')
        ->expectsOutputToContain('探した: 1 件(保存 0・行ができていた 0・失敗 0・次の回にまわした 1)')
        ->assertSuccessful();

    expect(Affiliate::query()->count())->toBe(0)
        ->and(collectSearches())->toBe([['amazon', 'Aジン', 'GroceryAndGourmetFood'], ['rakuten', 'Aジン', '510915']]);

    collectCommand($this, ['--new' => true])->assertSuccessful();

    expect(Affiliate::query()->orderBy('id')->pluck('category_id')->all())->toBe([$nodes[0]->id, $nodes[1]->id])
        ->and(Affiliate::query()->whereNull('amazon_asin')->orWhereNull('rakuten_item_code')->count())->toBe(0);
});

it('TC-248-15: Amazon が 429 を返したら、楽天にも問い合わせず、その件を保存せずにこの回を終える', function () {
    collectTree('A');
    collectTree('B');
    collectFake(amazon: fn () => Http::response(['type' => 'ThrottleException', 'message' => 'Rate exceeded'], 429));

    collectCommand($this, ['--new' => true])
        ->expectsOutputToContain('次の回にまわした 1')
        ->assertSuccessful();

    expect(Affiliate::query()->count())->toBe(0)
        ->and(collectSearches())->toBe([['amazon', 'Aジン', 'GroceryAndGourmetFood']]);
});

it('TC-248-16: 時間を置いても通らない API の失敗(Amazon の 403)は、その件を保存せずに飛ばし、次の件へ進む', function () {
    $nodes = [collectTree('A')['node'], collectTree('B')['node']];
    collectFake(amazon: fn (?string $keyword) => $keyword === 'Aジン'
        ? Http::response(['type' => 'AccessDeniedException', 'message' => 'not eligible'], 403)
        : Http::response(collectFixture('amazon-creators-api/search-items')));

    collectCommand($this, ['--new' => true])
        ->expectsOutputToContain('失敗したので飛ばす')
        ->expectsOutputToContain('探した: 2 件(保存 1・行ができていた 0・失敗 1・次の回にまわした 0)')
        ->assertSuccessful();

    expect(Affiliate::query()->pluck('category_id')->all())->toBe([$nodes[1]->id]);
});

it('TC-248-19: 探しているあいだにノードが消されたら、保存せずに失敗として飛ばし、次の件へ進む', function () {
    $nodes = [collectTree('A')['node'], collectTree('B')['node']];
    collectFake(rakuten: function (?string $keyword) use ($nodes) {
        if ($keyword === 'Aジン') {
            $nodes[0]->delete();
        }

        return Http::response(collectFixture('rakuten-ichiba-item-search/search'));
    });

    collectCommand($this, ['--new' => true])->assertSuccessful();

    expect(Affiliate::query()->pluck('category_id')->all())->toBe([$nodes[1]->id]);
});

it('TC-248-17:件と件のあいだに 1 秒あけて探す', function () {
    collectTree('A');
    collectTree('B');
    collectTree('C');
    collectFake();

    collectCommand($this, ['--new' => true])->assertSuccessful();

    // 楽天のクライアントは前の呼び出しから 1 秒たつまで待つ(#72)が、件のあいだの 1 秒で足りるので、それ以上は待たない
    Sleep::assertSequence([
        Sleep::for(1000)->milliseconds(),
        Sleep::for(1000)->milliseconds(),
    ]);
});

it('TC-248-18: --new が無い・--limit が 1 以上の整数でないときは、問い合わせずに止める', function (array $options, string $message) {
    collectTree();
    collectFake();

    collectCommand($this, $options)
        ->expectsOutputToContain($message)
        ->assertExitCode(2);

    Http::assertNothingSent();
    expect(Affiliate::query()->count())->toBe(0);
})->with([
    '--new なし' => [[], '--new を指定する'],
    '--limit=0' => [['--new' => true, '--limit' => '0'], '--limit は 1 以上の整数で指定する'],
    '--limit=abc' => [['--new' => true, '--limit' => 'abc'], '--limit は 1 以上の整数で指定する'],
    '--limit=-1' => [['--new' => true, '--limit' => '-1'], '--limit は 1 以上の整数で指定する'],
]);

// 失敗が続く件を、後の回で一時的に選ばない(#695)。待つ時間は 1 時間から失敗のたびに倍にし、1 日で止める。
// 決まりは docs/decisions/affiliate-freshness.md の 7。旧には失敗の扱いが無い(旧に無い)。

/**
 * 名前が $failing の件だけ、Amazon が 403(時間を置いても通らない失敗)を返すようにする。
 *
 * @param  list<string>  $failing
 */
function collectFakeFailing(array $failing): void
{
    collectFake(amazon: fn (?string $keyword) => in_array($keyword, $failing, true)
        ? Http::response(['type' => 'AccessDeniedException', 'message' => 'not eligible'], 403)
        : Http::response(collectFixture('amazon-creators-api/search-items')));
}

/**
 * Http::recorded() を空にして、この後に送った検索だけを collectSearches() で見られるようにする。
 *
 * @param  list<string>  $failing
 */
function collectRefake(array $failing = []): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    collectFakeFailing($failing);
}

it('TC-695-01: 先頭の件が失敗し続けても、次の回は失敗した件を選ばず、後ろの件を探して保存する', function () {
    $nodes = [collectTree('A')['node'], collectTree('B')['node'], collectTree('C')['node']];
    collectFakeFailing(['Aジン']);

    collectCommand($this, ['--new' => true, '--limit' => '1'])
        ->expectsOutputToContain('Aジン): 失敗したので飛ばす(Creators API の searchItems が 403 AccessDeniedException を返した)。2026-11-05 13:00:00 までは選ばない')
        ->expectsOutputToContain('探した: 1 件(保存 0・行ができていた 0・失敗 1・次の回にまわした 0)')
        ->assertSuccessful();
    expect(Affiliate::query()->count())->toBe(0);

    $this->travel(10)->minutes();
    collectRefake(['Aジン']);
    collectCommand($this, ['--new' => true, '--limit' => '1'])->assertSuccessful();

    $this->travel(10)->minutes();
    collectCommand($this, ['--new' => true, '--limit' => '1'])->assertSuccessful();

    expect(array_column(collectSearches(), 1))->toBe(['Bジン', 'Bジン', 'Cジン', 'Cジン'])
        ->and(Affiliate::query()->orderBy('id')->pluck('category_id')->all())->toBe([$nodes[1]->id, $nodes[2]->id]);
});

it('TC-695-02: 失敗した件は、1 時間たつまでは選ばず、1 時間たったらまた選ぶ', function () {
    $node = collectTree()['node'];
    collectFakeFailing(['ジン']);
    collectCommand($this, ['--new' => true])->assertSuccessful();

    $this->travelTo(Carbon::parse(COLLECT_NOW)->addMinutes(59));
    collectRefake();
    collectCommand($this, ['--new' => true])
        ->expectsOutputToContain('探した: 0 件')
        ->assertSuccessful();
    expect(collectSearches())->toBe([]);

    $this->travelTo(Carbon::parse(COLLECT_NOW)->addMinutes(60));
    collectCommand($this, ['--new' => true])
        ->expectsOutputToContain('探した: 1 件(保存 1')
        ->assertSuccessful();

    expect(array_column(collectSearches(), 1))->toBe(['ジン', 'ジン'])
        ->and(Affiliate::query()->sole()->category_id)->toBe($node->id);
});

it('TC-695-03: 同じ名前で失敗が続くと、選ばない時間を 1・2・4・8・16 時間と倍にし、24 時間で止める', function () {
    collectTree();
    collectFakeFailing(['ジン']);
    $at = Carbon::parse(COLLECT_NOW);
    $waits = [];
    foreach (range(1, 7) as $_) {
        $this->travelTo($at);
        collectCommand($this, ['--new' => true])->assertSuccessful();
        $failure = AffiliateCollectFailure::query()->sole();
        $waits[] = [$failure->failure_count, (int) $at->diffInMinutes($failure->retry_after)];
        $at = $failure->retry_after->copy();
    }

    expect($waits)->toBe([[1, 60], [2, 120], [3, 240], [4, 480], [5, 960], [6, 1440], [7, 1440]])
        ->and(AffiliateCollectFailure::query()->sole()->last_message)->toContain('403 AccessDeniedException');
});

it('TC-695-04: 名前が変わったら、待たずにまた選ぶ(照合順序では同じと見なす「ハ」→「パ」も)。名前が変わった後の失敗は 1 回目と数える', function () {
    $node = collectTree()['node'];
    $node->update(['name' => 'ハイボール']);
    collectFakeFailing(['ハイボール', 'パイボール']);
    collectCommand($this, ['--new' => true])->assertSuccessful();
    $this->travelTo(AffiliateCollectFailure::query()->sole()->retry_after);
    collectCommand($this, ['--new' => true])->assertSuccessful();
    expect(AffiliateCollectFailure::query()->sole()->failure_count)->toBe(2);

    $this->travel(10)->minutes();
    $node->update(['name' => 'パイボール']);
    collectRefake(['ハイボール', 'パイボール']);
    collectCommand($this, ['--new' => true])->assertSuccessful();

    $failure = AffiliateCollectFailure::query()->sole();
    expect(array_column(collectSearches(), 1))->toBe(['パイボール'])
        ->and($failure->name)->toBe('パイボール')
        ->and($failure->failure_count)->toBe(1)
        ->and($failure->retry_after->toDateTimeString())->toBe(now()->addHour()->toDateTimeString());
});

it('TC-695-05: 失敗した件を、待ちが明けて保存できたら、失敗の記録を消す', function () {
    $node = collectTree()['node'];
    collectFakeFailing(['ジン']);
    collectCommand($this, ['--new' => true])->assertSuccessful();
    expect(AffiliateCollectFailure::query()->count())->toBe(1);

    $this->travel(60)->minutes();
    collectRefake();
    collectCommand($this, ['--new' => true])->assertSuccessful();

    expect(AffiliateCollectFailure::query()->count())->toBe(0)
        ->and(Affiliate::query()->sole()->category_id)->toBe($node->id);
});

it('TC-695-06: 上限・一時的な失敗(429)は記録せず、次の回にまた選ぶ', function () {
    collectTree();
    collectFake(amazon: fn () => Http::response(['type' => 'ThrottleException', 'message' => 'Rate exceeded'], 429));

    collectCommand($this, ['--new' => true])->assertSuccessful();
    expect(AffiliateCollectFailure::query()->count())->toBe(0);

    $this->travel(10)->minutes();
    collectRefake();
    collectCommand($this, ['--new' => true])->assertSuccessful();

    expect(array_column(collectSearches(), 1))->toBe(['ジン', 'ジン'])
        ->and(Affiliate::query()->count())->toBe(1);
});

it('TC-695-07: 銘柄の失敗は、その銘柄だけを選ばない(同じノードの行・ほかの銘柄は選ぶ)', function () {
    $node = collectTree()['node'];
    $failing = Brand::factory()->for($node, 'category')->create(['name' => '失敗する銘柄']);
    $other = Brand::factory()->for($node, 'category')->create(['name' => 'ほかの銘柄']);
    collectFakeFailing(['失敗する銘柄']);
    collectCommand($this, ['--new' => true, '--limit' => '1'])->assertSuccessful();

    $this->travel(10)->minutes();
    collectRefake(['失敗する銘柄']);
    collectCommand($this, ['--new' => true, '--limit' => '2'])->assertSuccessful();

    $failure = AffiliateCollectFailure::query()->sole();
    expect(array_column(collectSearches(), 1))->toBe(['ほかの銘柄', 'ほかの銘柄', 'ジン', 'ジン'])
        ->and([$failure->category_id, $failure->brand_id])->toBe([$node->id, $failing->id])
        ->and(Affiliate::query()->orderBy('id')->get(['category_id', 'brand_id'])->toArray())->toBe([
            ['category_id' => $node->id, 'brand_id' => $other->id],
            ['category_id' => $node->id, 'brand_id' => null],
        ]);
});

it('TC-695-08: 探しているあいだに消されたノードは、失敗の記録を残さずに次の件へ進む', function () {
    $nodes = [collectTree('A')['node'], collectTree('B')['node']];
    collectFake(amazon: function (?string $keyword) use ($nodes) {
        if ($keyword === 'Aジン') {
            $nodes[0]->delete();

            return Http::response(['type' => 'AccessDeniedException', 'message' => 'not eligible'], 403);
        }

        return Http::response(collectFixture('amazon-creators-api/search-items'));
    });

    collectCommand($this, ['--new' => true])
        ->expectsOutputToContain('探した: 2 件(保存 1・行ができていた 0・失敗 1')
        ->assertSuccessful();

    expect(AffiliateCollectFailure::query()->count())->toBe(0)
        ->and(Affiliate::query()->pluck('category_id')->all())->toBe([$nodes[1]->id]);
});

it('TC-695-09: ノード・銘柄を消すと、その失敗の記録も消える', function () {
    ['node' => $node, 'middle' => $middle] = collectTree('A');
    $otherNode = collectTree('B')['node'];
    $brand = Brand::factory()->for($otherNode, 'category')->create(['name' => '銘柄']);
    collectFakeFailing(['Aジン', '銘柄']);
    collectCommand($this, ['--new' => true])->assertSuccessful();
    expect(AffiliateCollectFailure::query()->count())->toBe(2);

    $brand->delete();
    $tree = app(CategoryTree::class);
    $tree->deleteLeaf($middle);
    $tree->deleteLeaf($node->refresh());

    expect(AffiliateCollectFailure::query()->count())->toBe(0);
});
