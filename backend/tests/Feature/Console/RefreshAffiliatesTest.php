<?php

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

// 期限が近い・切れた商品の値を、最近見られた行から商品の ID で取り直す(affiliates:collect --refresh。#249)。
// 本物の API は呼ばない(preventStrayRequests)。差し替えるのは HTTP(Http::fake)・時刻・待ち(Sleep::fake)だけ。
// Amazon・楽天の応答は架空の値。
// 旧の収集(AffiliateController@main)は Amazon の旧 API(PA-API 5)・楽天の旧版の API を呼ぶもので、手元では動かせない。

uses(RefreshDatabase::class)->group('db');

const REFRESH_NOW = '2026-11-06 12:00:00';

/** 取り直す前の値を取った時刻の既定(REFRESH_NOW の 13 時間前) */
const REFRESH_OLD = '2026-11-05 23:00:00';

const REFRESH_AMAZON_TOKEN_URL = 'https://api.amazon.co.jp/auth/o2/token';

const REFRESH_AMAZON_GET_ITEMS_URL = 'https://creatorsapi.amazon/catalog/v1/getItems';

const REFRESH_AMAZON_SEARCH_URL = 'https://creatorsapi.amazon/catalog/v1/searchItems';

const REFRESH_RAKUTEN_URL = 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260701';

const REFRESH_AMAZON_SETTINGS = [
    'amazon.credential_id' => 'test-credential-id',
    'amazon.credential_secret' => 'test-credential-secret',
    'amazon.partner_tag' => 'example-22',
];

const REFRESH_RAKUTEN_SETTINGS = [
    'rakuten.application_id' => 'test-application-id',
    'rakuten.access_key' => 'test-access-key',
    'rakuten.affiliate_id' => 'g00test0.example0.g00test0.example1',
];

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    config()->set('settings', [...REFRESH_AMAZON_SETTINGS, ...REFRESH_RAKUTEN_SETTINGS]);
    $this->travelTo(Carbon::parse(REFRESH_NOW));
});

/**
 * Creators API の GetItems の応答の商品 1 件。$price が null なら出品が無い(価格の無い)商品。
 *
 * @return array<string, mixed>
 */
function refreshAmazonItem(string $asin, ?int $price): array
{
    return [
        'asin' => $asin,
        'detailPageURL' => "https://www.amazon.co.jp/dp/{$asin}?tag=example-22&linkCode=ogi",
        'images' => ['primary' => [
            'small' => ['url' => "https://m.media-amazon.com/images/I/{$asin}._SL75_.jpg"],
            'medium' => ['url' => "https://m.media-amazon.com/images/I/{$asin}._SL160_.jpg"],
            'large' => ['url' => "https://m.media-amazon.com/images/I/{$asin}._SL500_.jpg"],
        ]],
        'itemInfo' => ['title' => ['displayValue' => "新しい商品 {$asin}"]],
        'offersV2' => ['listings' => $price === null ? [] : [
            ['price' => ['money' => ['amount' => (float) $price, 'currency' => 'JPY', 'displayAmount' => '￥'.number_format($price)]]],
        ]],
    ];
}

/**
 * 楽天の商品検索 API(formatVersion=2)の応答の商品 1 件。$availability が 0 なら売り切れ。
 *
 * @return array<string, mixed>
 */
function refreshRakutenItem(string $itemCode, int $price, int $availability = 1): array
{
    $number = explode(':', $itemCode)[1];

    return [
        'itemName' => "新しい商品 {$itemCode}",
        'itemCode' => $itemCode,
        'itemPrice' => $price,
        'itemUrl' => "https://item.rakuten.co.jp/example-shop/{$number}/",
        'affiliateUrl' => "https://hb.afl.rakuten.co.jp/hgc/example/?pc=https%3A%2F%2Fitem.rakuten.co.jp%2Fexample-shop%2F{$number}%2F",
        'imageFlag' => 1,
        'smallImageUrls' => ["https://thumbnail.image.rakuten.co.jp/@0_mall/example-shop/cabinet/{$number}.jpg?_ex=64x64"],
        'availability' => $availability,
    ];
}

/**
 * 保存した応答(tests/Fixtures/。#135・#72 のテストと同じもの)。
 *
 * @return array<mixed>
 */
function refreshFixture(string $path): array
{
    $json = file_get_contents(base_path("tests/Fixtures/{$path}.json"));
    $decoded = is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : null;

    return is_array($decoded) ? $decoded : throw new RuntimeException("保存した応答を読めない: {$path}");
}

/**
 * Amazon と楽天の応答を差し替える。
 *
 * - $amazon: ASIN => 価格(null なら価格の無い商品)。無い ASIN は応答に入れない(取れなかった商品)。
 *   Closure なら、頼まれた ASIN の一覧を受けて応答を返す
 * - $rakuten: 商品のコード => 応答の商品(配列)か、エラーの状態コード(int)。無いコードは 0 件の応答
 * - 名前での検索(--new)には、#248 のテストと同じ保存した応答を返す
 *
 * @param  array<string, int|null>|(Closure(list<string>): mixed)  $amazon
 * @param  array<string, array<string, mixed>|int>  $rakuten
 */
function refreshFake(array|Closure $amazon = [], array $rakuten = []): void
{
    $fixture = fn (string $path): array => refreshFixture($path);

    Http::fake([
        REFRESH_AMAZON_TOKEN_URL => Http::response(['access_token' => 'token-1', 'token_type' => 'bearer', 'expires_in' => 3600]),
        REFRESH_AMAZON_SEARCH_URL => fn () => Http::response($fixture('amazon-creators-api/search-items')),
        REFRESH_AMAZON_GET_ITEMS_URL => function (Request $request) use ($amazon) {
            /** @var list<string> $asins */
            $asins = $request->data()['itemIds'] ?? [];
            if ($amazon instanceof Closure) {
                return $amazon($asins);
            }
            $items = [];
            foreach ($asins as $asin) {
                if (array_key_exists($asin, $amazon)) {
                    $items[] = refreshAmazonItem($asin, $amazon[$asin]);
                }
            }

            return $items === []
                ? Http::response(['type' => 'ResourceNotFoundException', 'message' => 'not found'], 404)
                : Http::response(['itemResults' => ['items' => $items]]);
        },
        REFRESH_RAKUTEN_URL.'*' => function (Request $request) use ($rakuten, $fixture) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (isset($query['keyword'])) {
                return Http::response($fixture('rakuten-ichiba-item-search/search'));
            }
            $code = $query['itemCode'] ?? null;
            $response = is_string($code) ? ($rakuten[$code] ?? null) : null;
            if (is_int($response)) {
                $error = match ($response) {
                    // 楽天は対象が無いとき 404(not_found)を返すことがある(docs/decisions/rakuten-client.md の 4)
                    404 => 'not_found',
                    429 => 'too_many_requests',
                    default => 'wrong_parameter',
                };

                return Http::response(['error' => $error, 'error_description' => 'error'], $response);
            }

            return Http::response(['count' => $response === null ? 0 : 1, 'Items' => $response === null ? [] : [$response]]);
        },
    ]);
}

/**
 * 送った要求を、送った順に [種類, 中身] で返す。取り直しは ['amazon', ASIN の一覧]・['rakuten', 商品のコード]、
 * 名前での検索は ['amazon-search', 語]・['rakuten-search', 語]。
 *
 * @return list<array{0: string, 1: mixed}>
 */
function refreshRequests(): array
{
    $requests = [];
    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        if ($request->url() === REFRESH_AMAZON_GET_ITEMS_URL) {
            $requests[] = ['amazon', $request->data()['itemIds'] ?? null];
        } elseif ($request->url() === REFRESH_AMAZON_SEARCH_URL) {
            $requests[] = ['amazon-search', $request->data()['keywords'] ?? null];
        } elseif (str_starts_with($request->url(), REFRESH_RAKUTEN_URL)) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $requests[] = isset($query['keyword'])
                ? ['rakuten-search', $query['keyword']]
                : ['rakuten', $query['itemCode'] ?? null];
        }
    }

    return $requests;
}

/**
 * 取り直す前の Amazon の値。取った時刻は $hoursAgo 時間前(null なら取った時刻なし)。
 *
 * @return array<string, mixed>
 */
function refreshOldAmazon(string $asin, ?float $hoursAgo = 13): array
{
    return [
        'amazon_asin' => $asin,
        'amazon_title' => "古い商品 {$asin}",
        'amazon_price' => 1000,
        'amazon_image_large_url' => 'https://m.media-amazon.com/images/I/OLD._SL500_.jpg',
        'amazon_image_medium_url' => 'https://m.media-amazon.com/images/I/OLD._SL160_.jpg',
        'amazon_image_small_url' => 'https://m.media-amazon.com/images/I/OLD._SL75_.jpg',
        'amazon_detail_url' => "https://www.amazon.co.jp/dp/{$asin}?tag=old-22",
        'amazon_search_url' => 'https://www.amazon.co.jp/s/?field-keywords=old',
        'amazon_fetched_at' => $hoursAgo === null ? null : now()->subSeconds((int) round($hoursAgo * 3600)),
    ];
}

/**
 * 取り直す前の楽天の値。取った時刻は $hoursAgo 時間前(null なら取った時刻なし)。
 *
 * @return array<string, mixed>
 */
function refreshOldRakuten(string $itemCode, ?float $hoursAgo = 13): array
{
    return [
        'rakuten_item_code' => $itemCode,
        'rakuten_title' => "古い商品 {$itemCode}",
        'rakuten_price' => 2000,
        'rakuten_image_url' => 'https://thumbnail.image.rakuten.co.jp/@0_mall/example-shop/cabinet/old.jpg',
        'rakuten_detail_url' => 'https://hb.afl.rakuten.co.jp/hgc/old/',
        'rakuten_search_url' => 'https://hb.afl.rakuten.co.jp/hgc/old/?pc=search',
        'rakuten_fetched_at' => $hoursAgo === null ? null : now()->subSeconds((int) round($hoursAgo * 3600)),
    ];
}

/**
 * 名前が $name のノードと、そのノードの行を作る。
 *
 * @param  array<string, mixed>  $values
 */
function refreshRow(array $values, string $name = 'ジン'): Affiliate
{
    $node = Category::factory()->create(['name' => $name]);

    return Affiliate::factory()->create(['category_id' => $node->id, ...$values]);
}

/**
 * affiliates:collect を、出力と終了コードを確かめられる形で呼ぶ。
 *
 * @param  array<mixed>  $options
 */
function refreshCommand(TestCase $test, array $options): PendingCommand
{
    $command = $test->artisan('affiliates:collect', $options);

    return $command instanceof PendingCommand
        ? $command
        : throw new RuntimeException('コンソールの出力を差し替えずにコマンドが動いた');
}

/**
 * 行のその提供元を、このテストで取り直したか(取った時刻が REFRESH_NOW 以降か)。楽天のクライアントは呼び出しのあいだに
 * 1 秒待つ(待ちは Sleep::fake で時計を進める)ので、何件目かによって取った時刻が REFRESH_NOW から数秒ずれる。
 */
function refreshWasRefreshed(Affiliate $affiliate, string $column): bool
{
    $value = $affiliate->fresh()?->getAttribute($column);

    return $value instanceof Carbon && $value->greaterThanOrEqualTo(REFRESH_NOW);
}

/** 行の Amazon・楽天の取った時刻(秒まで)。行が無ければ null */
function refreshFetchedAt(Affiliate $affiliate, string $column): ?string
{
    $value = $affiliate->fresh()?->getAttribute($column);

    return $value instanceof Carbon ? $value->toDateTimeString() : null;
}

it('TC-249-01: 見られた時刻の新しい行から選び、見られていない行はその後にする', function () {
    $unviewed = refreshRow([...refreshOldAmazon('B000UNVIEW'), ...refreshOldRakuten('example-shop:30000003'), 'last_viewed_at' => null], 'ラム');
    $older = refreshRow([...refreshOldAmazon('B000OLDVIE'), ...refreshOldRakuten('example-shop:20000002'), 'last_viewed_at' => now()->subHours(5)], 'ウォッカ');
    $newer = refreshRow([...refreshOldAmazon('B000NEWVIE'), ...refreshOldRakuten('example-shop:10000001'), 'last_viewed_at' => now()->subHour()], 'ジン');
    refreshFake(
        ['B000NEWVIE' => 1500, 'B000OLDVIE' => 1600, 'B000UNVIEW' => 1700],
        [
            'example-shop:10000001' => refreshRakutenItem('example-shop:10000001', 2500),
            'example-shop:20000002' => refreshRakutenItem('example-shop:20000002', 2600),
            'example-shop:30000003' => refreshRakutenItem('example-shop:30000003', 2700),
        ],
    );

    refreshCommand($this, ['--refresh' => true, '--limit' => '2'])->assertSuccessful();

    expect(refreshRequests())->toBe([
        ['amazon', ['B000NEWVIE', 'B000OLDVIE']],
        ['rakuten', 'example-shop:10000001'],
        ['rakuten', 'example-shop:20000002'],
    ])
        ->and(refreshWasRefreshed($newer, 'amazon_fetched_at'))->toBeTrue()
        ->and(refreshWasRefreshed($older, 'rakuten_fetched_at'))->toBeTrue()
        ->and(refreshFetchedAt($unviewed, 'amazon_fetched_at'))->toBe(REFRESH_OLD)
        ->and(refreshFetchedAt($unviewed, 'rakuten_fetched_at'))->toBe(REFRESH_OLD);
});

it('TC-249-02: 取れた値と取った時刻を保存し、検索結果のリンクは名前から組み立て、手で入れた値・見られた時刻は変えない', function () {
    $brand = Brand::factory()->create(['name' => 'サンプル ジン']);
    $viewedAt = now()->subHours(3);
    $affiliate = Affiliate::factory()->create([
        'category_id' => $brand->category_id,
        'brand_id' => $brand->id,
        ...refreshOldAmazon('B000TEST01'),
        ...refreshOldRakuten('example-shop:10000001'),
        'human_title' => '手で入れた題名',
        'human_price' => 3000,
        'human_image_url' => 'https://example.com/human.jpg',
        'human_amazon_detail_url' => 'https://www.amazon.co.jp/dp/B000HUMAN1',
        'human_amazon_search_url' => 'https://www.amazon.co.jp/s?k=human',
        'last_viewed_at' => $viewedAt,
    ]);
    refreshFake(
        ['B000TEST01' => 1980],
        ['example-shop:10000001' => refreshRakutenItem('example-shop:10000001', 2480)],
    );

    refreshCommand($this, ['--refresh' => true])->assertSuccessful();

    expect(collect($affiliate->refresh()->getAttributes())->except(['id', 'brand_key', 'created_at', 'updated_at'])->all())->toEqual([
        'category_id' => $brand->category_id,
        'brand_id' => $brand->id,
        'amazon_asin' => 'B000TEST01',
        'amazon_title' => '新しい商品 B000TEST01',
        'amazon_price' => 1980,
        'amazon_image_large_url' => 'https://m.media-amazon.com/images/I/B000TEST01._SL500_.jpg',
        'amazon_image_medium_url' => 'https://m.media-amazon.com/images/I/B000TEST01._SL160_.jpg',
        'amazon_image_small_url' => 'https://m.media-amazon.com/images/I/B000TEST01._SL75_.jpg',
        'amazon_detail_url' => 'https://www.amazon.co.jp/dp/B000TEST01?tag=example-22&linkCode=ogi',
        'amazon_search_url' => 'https://www.amazon.co.jp/s?k='.rawurlencode('サンプル ジン').'&tag=example-22',
        'amazon_fetched_at' => REFRESH_NOW,
        'rakuten_item_code' => 'example-shop:10000001',
        'rakuten_title' => '新しい商品 example-shop:10000001',
        'rakuten_price' => 2480,
        'rakuten_image_url' => 'https://thumbnail.image.rakuten.co.jp/@0_mall/example-shop/cabinet/10000001.jpg',
        'rakuten_detail_url' => 'https://hb.afl.rakuten.co.jp/hgc/example/?pc=https%3A%2F%2Fitem.rakuten.co.jp%2Fexample-shop%2F10000001%2F',
        'rakuten_search_url' => 'https://hb.afl.rakuten.co.jp/hgc/g00test0.example0.g00test0.example1/?pc='
            .rawurlencode('https://search.rakuten.co.jp/search/mall/').urlencode('サンプル ジン').rawurlencode('/510915/'),
        'rakuten_fetched_at' => REFRESH_NOW,
        'human_title' => '手で入れた題名',
        'human_price' => 3000,
        'human_image_url' => 'https://example.com/human.jpg',
        'human_amazon_detail_url' => 'https://www.amazon.co.jp/dp/B000HUMAN1',
        'human_amazon_search_url' => 'https://www.amazon.co.jp/s?k=human',
        'last_viewed_at' => $viewedAt->toDateTimeString(),
    ]);
});

it('TC-249-03: 取ってから 12 時間を過ぎた行(ちょうど 12 時間・取った時刻なしを含む)を選び、12 時間に満たない行は選ばない', function () {
    $justBefore = refreshRow([...refreshOldAmazon('B000BEFORE', 12 - 1 / 3600), ...refreshOldRakuten('example-shop:10000001', 12 - 1 / 3600)], 'A');
    $exactly = refreshRow([...refreshOldAmazon('B000EXACTL', 12), ...refreshOldRakuten('example-shop:20000002', 12)], 'B');
    $never = refreshRow([...refreshOldAmazon('B000NEVERF', null), ...refreshOldRakuten('example-shop:30000003', null)], 'C');
    $expired = refreshRow([...refreshOldAmazon('B000EXPIRE', 25), ...refreshOldRakuten('example-shop:40000004', 25)], 'D');
    refreshFake(
        ['B000EXACTL' => 1, 'B000NEVERF' => 2, 'B000EXPIRE' => 3],
        [
            'example-shop:20000002' => refreshRakutenItem('example-shop:20000002', 1),
            'example-shop:30000003' => refreshRakutenItem('example-shop:30000003', 2),
            'example-shop:40000004' => refreshRakutenItem('example-shop:40000004', 3),
        ],
    );

    refreshCommand($this, ['--refresh' => true])->assertSuccessful();

    $requests = refreshRequests();
    expect($requests[0][0])->toBe('amazon')
        ->and($requests[0][1])->toEqualCanonicalizing(['B000EXACTL', 'B000NEVERF', 'B000EXPIRE'])
        ->and(array_slice($requests, 1))->toEqualCanonicalizing([
            ['rakuten', 'example-shop:20000002'],
            ['rakuten', 'example-shop:30000003'],
            ['rakuten', 'example-shop:40000004'],
        ])
        ->and(refreshFetchedAt($justBefore, 'amazon_fetched_at'))->toBe('2026-11-06 00:00:01')
        ->and(refreshWasRefreshed($exactly, 'amazon_fetched_at'))->toBeTrue()
        ->and(refreshWasRefreshed($never, 'rakuten_fetched_at'))->toBeTrue()
        ->and(refreshWasRefreshed($expired, 'rakuten_fetched_at'))->toBeTrue();
});

it('TC-249-04: 手で入れた値だけの行(商品の ID が無い行)は選ばず、どこにも問い合わせない', function () {
    $human = refreshRow(['human_title' => '手で入れた題名', 'human_price' => 3000, 'last_viewed_at' => now()]);
    refreshFake();

    refreshCommand($this, ['--refresh' => true])->assertSuccessful();

    Http::assertNothingSent();
    expect($human->fresh()?->human_price)->toBe(3000);
});

it('TC-249-05: Amazon の ID で取れなかった(販売終了など)ときは、Amazon の ID と値を消し、楽天の値と行は残す', function () {
    $affiliate = refreshRow([...refreshOldAmazon('B000GONE01'), ...refreshOldRakuten('example-shop:10000001')]);
    refreshFake([], ['example-shop:10000001' => refreshRakutenItem('example-shop:10000001', 2480)]);

    refreshCommand($this, ['--refresh' => true])
        ->expectsOutputToContain('ID で取れなかったので ID と値を消した')
        ->assertSuccessful();

    $stored = $affiliate->fresh();
    expect($stored)->not->toBeNull()
        ->and(collect($stored?->getAttributes())->only([
            'amazon_asin', 'amazon_title', 'amazon_price', 'amazon_image_large_url', 'amazon_image_medium_url',
            'amazon_image_small_url', 'amazon_detail_url', 'amazon_search_url', 'amazon_fetched_at',
        ])->filter(fn ($value) => $value !== null)->all())->toBe([])
        ->and($stored?->rakuten_item_code)->toBe('example-shop:10000001')
        ->and($stored?->rakuten_price)->toBe(2480);
});

it('TC-249-06: 楽天の商品のコードで取れなかった(0 件・404・売り切れ)ときは、楽天の ID と値を消し、Amazon の値と行は残す', function (?int $response) {
    $affiliate = refreshRow([...refreshOldAmazon('B000TEST01'), ...refreshOldRakuten('example-shop:10000001')]);
    refreshFake(
        ['B000TEST01' => 1980],
        $response === null ? [] : ['example-shop:10000001' => $response === 404
            ? 404
            : refreshRakutenItem('example-shop:10000001', 2480, availability: 0)],
    );

    refreshCommand($this, ['--refresh' => true])->assertSuccessful();

    $stored = $affiliate->fresh();
    expect($stored)->not->toBeNull()
        ->and(collect($stored?->getAttributes())->only([
            'rakuten_item_code', 'rakuten_title', 'rakuten_price', 'rakuten_image_url',
            'rakuten_detail_url', 'rakuten_search_url', 'rakuten_fetched_at',
        ])->filter(fn ($value) => $value !== null)->all())->toBe([])
        ->and($stored?->amazon_asin)->toBe('B000TEST01')
        ->and($stored?->amazon_price)->toBe(1980);
})->with([
    '0 件' => [null],
    '404' => [404],
    '売り切れ' => [0],
]);

it('TC-249-07: 両方の ID が消えて手で入れた値も無い行は消し、次の --new が名前から探し直して行を作り直す', function () {
    $node = Category::factory()->create(['name' => 'ジン']);
    $affiliate = Affiliate::factory()->create([
        'category_id' => $node->id,
        ...refreshOldAmazon('B000GONE01'),
        ...refreshOldRakuten('example-shop:99999999'),
    ]);
    refreshFake();

    refreshCommand($this, ['--refresh' => true])
        ->expectsOutputToContain('ほかに値が無いので行も消した')
        ->assertSuccessful();
    expect($affiliate->fresh())->toBeNull();

    // --new は根の直下のノードだけを探す(#248)ので、根の下に置いたノードで確かめる
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, Category::factory()->make(['name' => '根'])->getAttributes());
    $child = $tree->addNode($root, Category::factory()->make(['name' => 'ウォッカ'])->getAttributes());
    Affiliate::factory()->create(['category_id' => $child->id, ...refreshOldAmazon('B000GONE02'), ...refreshOldRakuten('example-shop:99999998')]);

    refreshCommand($this, ['--refresh' => true])->assertSuccessful();
    refreshCommand($this, ['--new' => true])->assertSuccessful();

    $recreated = Affiliate::query()->where('category_id', $child->id)->sole();
    expect($recreated->amazon_asin)->toBe('B000TEST01')
        ->and($recreated->rakuten_item_code)->toBe('example-shop:10000001')
        ->and(array_values(array_filter(refreshRequests(), fn (array $request) => str_ends_with($request[0], '-search'))))->toBe([
            ['amazon-search', 'ウォッカ'],
            ['rakuten-search', 'ウォッカ'],
        ]);
});

it('TC-249-08: 手で入れた値のある行は、両方の ID が消えても行を残し、手で入れた値はそのまま', function () {
    $affiliate = refreshRow([
        ...refreshOldAmazon('B000GONE01'),
        ...refreshOldRakuten('example-shop:99999999'),
        'human_title' => '手で入れた題名',
        'human_price' => 3000,
    ]);
    refreshFake();

    refreshCommand($this, ['--refresh' => true])->assertSuccessful();

    $stored = $affiliate->fresh();
    expect($stored)->not->toBeNull()
        ->and($stored?->amazon_asin)->toBeNull()
        ->and($stored?->rakuten_item_code)->toBeNull()
        ->and($stored?->human_title)->toBe('手で入れた題名')
        ->and($stored?->human_price)->toBe(3000);
});

it('TC-249-09: Amazon が返した商品に価格が無い(出品が無い)ときも、取れなかった扱いで Amazon の ID と値を消す', function () {
    $noPrice = refreshRow(refreshOldAmazon('B000TEST01'), 'A');
    $found = refreshRow(refreshOldAmazon('B000TEST03'), 'B');
    $error = refreshRow(refreshOldAmazon('B000TEST09'), 'C');
    // 保存した GetItems の応答(#135)。B000TEST03 は価格あり、B000TEST01 は出品なし、B000TEST09 はエラー
    refreshFake(fn () => Http::response(refreshFixture('amazon-creators-api/get-items-partial')));
    config()->set('settings', REFRESH_AMAZON_SETTINGS);

    refreshCommand($this, ['--refresh' => true])->assertSuccessful();

    expect($noPrice->fresh()?->amazon_asin)->toBeNull()
        ->and($error->fresh()?->amazon_asin)->toBeNull()
        ->and($found->fresh()?->amazon_price)->toBe(1280)
        ->and(refreshWasRefreshed($found, 'amazon_fetched_at'))->toBeTrue();
});

it('TC-249-10: Amazon は 10 件ごとに GetItems を 1 回呼び、あいだに 1 秒あける。同じ ASIN の行は 1 回だけ頼み、どちらの行も取り直す', function () {
    $asins = [];
    for ($i = 1; $i <= 11; $i++) {
        $asin = sprintf('B000ITEM%02d', $i);
        $asins[$asin] = 1000 + $i;
        refreshRow(refreshOldAmazon($asin), "N{$i}");
    }
    $sameAsBrand = refreshRow(refreshOldAmazon('B000ITEM01'), 'N1 の銘柄');
    config()->set('settings', REFRESH_AMAZON_SETTINGS);
    refreshFake($asins);

    refreshCommand($this, ['--refresh' => true, '--limit' => '12'])->assertSuccessful();

    $requests = refreshRequests();
    expect($requests)->toHaveCount(2)
        ->and($requests[0][1])->toHaveCount(10)
        ->and($requests[1][1])->toHaveCount(1)
        ->and(array_merge(...array_map(fn (array $request) => is_array($request[1]) ? $request[1] : [], $requests)))
        ->toEqualCanonicalizing(array_keys($asins))
        ->and(Affiliate::query()->where('amazon_fetched_at', '>=', REFRESH_NOW)->count())->toBe(12)
        ->and($sameAsBrand->fresh()?->amazon_price)->toBe(1001);
    Sleep::assertSequence([Sleep::for(1000)->milliseconds()]);
});

it('TC-249-11: --limit を省くと設定の件数(既定は Amazon・楽天それぞれ 10 件)まで取り直す', function (?int $configured, int $expected) {
    if ($configured !== null) {
        config()->set('affiliates.refresh_limit', $configured);
    }
    $amazon = [];
    $rakuten = [];
    for ($i = 1; $i <= 12; $i++) {
        $asin = sprintf('B000ITEM%02d', $i);
        $code = sprintf('example-shop:%08d', $i);
        $amazon[$asin] = 1000 + $i;
        $rakuten[$code] = refreshRakutenItem($code, 2000 + $i);
        refreshRow([...refreshOldAmazon($asin), ...refreshOldRakuten($code)], "N{$i}");
    }
    refreshFake($amazon, $rakuten);

    refreshCommand($this, ['--refresh' => true])->assertSuccessful();

    expect(Affiliate::query()->where('amazon_fetched_at', '>=', REFRESH_NOW)->count())->toBe($expected)
        ->and(Affiliate::query()->where('rakuten_fetched_at', '>=', REFRESH_NOW)->count())->toBe($expected);
})->with([
    '既定' => [null, 10],
    '設定で 3 件' => [3, 3],
]);

it('TC-249-12: Amazon が 429 を返したら、Amazon はこの回を終えて値を書き換えず、楽天は続けて取り直す', function () {
    for ($i = 1; $i <= 11; $i++) {
        refreshRow([...refreshOldAmazon(sprintf('B000ITEM%02d', $i)), ...($i === 1 ? refreshOldRakuten('example-shop:10000001') : [])], "N{$i}");
    }
    refreshFake(
        fn () => Http::response(['type' => 'ThrottleException', 'message' => 'too many'], 429),
        ['example-shop:10000001' => refreshRakutenItem('example-shop:10000001', 2480)],
    );

    refreshCommand($this, ['--refresh' => true, '--limit' => '11'])
        ->expectsOutputToContain('次の回にまわす')
        ->assertSuccessful();

    expect(array_map(fn (array $request) => $request[0], refreshRequests()))->toBe(['amazon', 'rakuten'])
        ->and(Affiliate::query()->whereNotNull('amazon_asin')->count())->toBe(11)
        ->and(Affiliate::query()->where('amazon_fetched_at', '>=', REFRESH_NOW)->count())->toBe(0)
        ->and(Affiliate::query()->where('rakuten_fetched_at', '>=', REFRESH_NOW)->count())->toBe(1);
});

it('TC-249-13: 楽天が 429 を返したら、楽天はこの回を終え、その行の ID と値を残す', function () {
    $first = refreshRow([...refreshOldRakuten('example-shop:10000001'), 'last_viewed_at' => now()], 'A');
    $second = refreshRow(refreshOldRakuten('example-shop:20000002'), 'B');
    config()->set('settings', REFRESH_RAKUTEN_SETTINGS);
    refreshFake([], ['example-shop:10000001' => 429, 'example-shop:20000002' => refreshRakutenItem('example-shop:20000002', 1)]);

    refreshCommand($this, ['--refresh' => true])->assertSuccessful();

    expect(refreshRequests())->toBe([['rakuten', 'example-shop:10000001']])
        ->and($first->fresh()?->rakuten_item_code)->toBe('example-shop:10000001')
        ->and($first->fresh()?->rakuten_price)->toBe(2000)
        ->and($second->fresh()?->rakuten_price)->toBe(2000);
});

it('TC-249-14: 時間を置いても通らない楽天の失敗(400)は、その行を書き換えずに飛ばし、次の行へ進む', function () {
    $failed = refreshRow([...refreshOldRakuten('example-shop:10000001'), 'last_viewed_at' => now()], 'A');
    $next = refreshRow(refreshOldRakuten('example-shop:20000002'), 'B');
    config()->set('settings', REFRESH_RAKUTEN_SETTINGS);
    refreshFake([], ['example-shop:10000001' => 400, 'example-shop:20000002' => refreshRakutenItem('example-shop:20000002', 2480)]);

    refreshCommand($this, ['--refresh' => true])
        ->expectsOutputToContain('失敗したので飛ばす')
        ->assertSuccessful();

    expect($failed->fresh()?->rakuten_item_code)->toBe('example-shop:10000001')
        ->and(refreshFetchedAt($failed, 'rakuten_fetched_at'))->toBe(REFRESH_OLD)
        ->and($next->fresh()?->rakuten_price)->toBe(2480);
});

it('TC-249-15: 時間を置いても通らない Amazon の失敗(403)は、その GetItems の分を書き換えずに飛ばし、次の 10 件へ進む', function () {
    $amazon = [];
    for ($i = 1; $i <= 11; $i++) {
        $asin = sprintf('B000ITEM%02d', $i);
        $amazon[$asin] = 1000 + $i;
        // 1 件目は見られた時刻が新しいので、最初の 10 件に入る。11 件目は見られていないので次の回の分
        refreshRow([...refreshOldAmazon($asin), 'last_viewed_at' => $i <= 10 ? now()->subMinutes($i) : null], "N{$i}");
    }
    config()->set('settings', REFRESH_AMAZON_SETTINGS);
    $calls = 0;
    refreshFake(function (array $asins) use (&$calls, $amazon) {
        $calls++;
        if ($calls === 1) {
            return Http::response(['type' => 'AccessDeniedException', 'message' => 'denied'], 403);
        }

        return Http::response(['itemResults' => ['items' => array_map(fn (string $asin) => refreshAmazonItem($asin, $amazon[$asin]), $asins)]]);
    });

    refreshCommand($this, ['--refresh' => true, '--limit' => '11'])->assertSuccessful();

    expect(Affiliate::query()->whereNotNull('amazon_asin')->count())->toBe(11)
        ->and(Affiliate::query()->where('amazon_fetched_at', '>=', REFRESH_NOW)->pluck('amazon_asin')->all())->toBe(['B000ITEM11']);
});

it('TC-249-16: 認証情報の無い提供元には問い合わせず、その提供元の値は書き換えない', function (array $settings, array $expectedKinds) {
    $affiliate = refreshRow([...refreshOldAmazon('B000TEST01'), ...refreshOldRakuten('example-shop:10000001')]);
    config()->set('settings', $settings);
    refreshFake(['B000TEST01' => 1980], ['example-shop:10000001' => refreshRakutenItem('example-shop:10000001', 2480)]);

    refreshCommand($this, ['--refresh' => true])->assertSuccessful();

    expect(array_map(fn (array $request) => $request[0], refreshRequests()))->toBe($expectedKinds)
        ->and($affiliate->fresh()?->amazon_price)->toBe(in_array('amazon', $expectedKinds, true) ? 1980 : 1000)
        ->and($affiliate->fresh()?->rakuten_price)->toBe(in_array('rakuten', $expectedKinds, true) ? 2480 : 2000);
})->with([
    '両方とも無い' => [[], []],
    'Amazon だけ無い' => [REFRESH_RAKUTEN_SETTINGS, ['rakuten']],
    '楽天だけ無い' => [REFRESH_AMAZON_SETTINGS, ['amazon']],
]);

it('TC-249-17: 取っているあいだに行の ID が変わったら(管理画面で直した、など)、その行を書き換えない', function () {
    $affiliate = refreshRow(refreshOldRakuten('example-shop:10000001'));
    config()->set('settings', REFRESH_RAKUTEN_SETTINGS);
    Http::fake([
        REFRESH_RAKUTEN_URL.'*' => function () use ($affiliate) {
            Affiliate::query()->whereKey($affiliate->id)->update(['rakuten_item_code' => 'example-shop:20000002']);

            return Http::response(['count' => 0, 'Items' => []]);
        },
    ]);

    refreshCommand($this, ['--refresh' => true])->assertSuccessful();

    $stored = $affiliate->fresh();
    expect($stored)->not->toBeNull()
        ->and($stored?->rakuten_item_code)->toBe('example-shop:20000002')
        ->and($stored?->rakuten_price)->toBe(2000);
});

it('TC-249-18: --refresh と --new を一緒に指定すると、取り直し → 新しく探すの順に流し、あいだに 1 秒あける', function () {
    refreshRow([...refreshOldAmazon('B000TEST03'), ...refreshOldRakuten('example-shop:20000002')], '取り直す行');
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, Category::factory()->make(['name' => '根'])->getAttributes());
    $tree->addNode($root, Category::factory()->make(['name' => 'ジン'])->getAttributes());
    refreshFake(['B000TEST03' => 1280], ['example-shop:20000002' => refreshRakutenItem('example-shop:20000002', 2480)]);

    refreshCommand($this, ['--refresh' => true, '--new' => true])->assertSuccessful();

    expect(refreshRequests())->toBe([
        ['amazon', ['B000TEST03']],
        ['rakuten', 'example-shop:20000002'],
        ['amazon-search', 'ジン'],
        ['rakuten-search', 'ジン'],
    ]);
    // 取り直しの後の 1 秒だけ。楽天のクライアントの待ち(前の呼び出しから 1 秒)は、この 1 秒で足りるので重ならない
    Sleep::assertSequence([Sleep::for(1000)->milliseconds()]);
});

it('TC-249-19: --refresh・--new のどちらも無い・件数が 1 以上の整数でないときは、問い合わせずに止める', function (array $options, ?string $configured, string $message) {
    if ($configured !== null) {
        config()->set('affiliates.refresh_limit', $configured);
    }
    refreshRow([...refreshOldAmazon('B000TEST01'), ...refreshOldRakuten('example-shop:10000001')]);
    refreshFake();

    refreshCommand($this, $options)
        ->expectsOutputToContain($message)
        ->assertExitCode(2);

    Http::assertNothingSent();
})->with([
    'どちらも無い' => [[], null, '--refresh か --new を指定する'],
    '--limit=0' => [['--refresh' => true, '--limit' => '0'], null, '--limit は 1 以上の整数で指定する'],
    '--limit=abc' => [['--refresh' => true, '--limit' => 'abc'], null, '--limit は 1 以上の整数で指定する'],
    '設定の件数が abc' => [['--refresh' => true], 'abc', '--limit は 1 以上の整数で指定する'],
]);
