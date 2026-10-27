<?php

use App\Services\Affiliate\AmazonApiException;
use App\Services\Affiliate\AmazonClient;
use App\Services\Affiliate\AmazonItem;
use App\Services\Affiliate\AmazonRetryLaterException;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// Amazon Creators API のクライアント(#135)。本物の API は呼ばない(preventStrayRequests)。
//
// tests/Fixtures/amazon-creators-api/ の応答は、公式の資料の SearchItems・GetItems の応答の例
// (docs/amazon-creators-api.md の 5・6・7)を、日本向け(amazon.co.jp・JPY・ja_JP)に書き換えたもの。
// 資料の例は米国のものだけで、日本の displayAmount の書式は確かめていない。ASIN・タグは架空の値。
//
// 認証情報は Settings を通して読むので(画面で保存した値が DB にあるかを見る)、DB を使う。

uses(RefreshDatabase::class)->group('db');

const AMAZON_TOKEN_URL = 'https://api.amazon.co.jp/auth/o2/token';
const AMAZON_SEARCH_URL = 'https://creatorsapi.amazon/catalog/v1/searchItems';
const AMAZON_GET_ITEMS_URL = 'https://creatorsapi.amazon/catalog/v1/getItems';

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('settings', [
        AmazonClient::SETTING_CREDENTIAL_ID => 'test-credential-id',
        AmazonClient::SETTING_CREDENTIAL_SECRET => 'test-credential-secret',
        AmazonClient::SETTING_PARTNER_TAG => 'example-22',
    ]);
    $this->travelTo(Carbon::parse('2026-10-27 12:00:00'));
});

function amazonClient(): AmazonClient
{
    return app(AmazonClient::class);
}

/**
 * @return array<mixed>
 */
function amazonFixture(string $name): array
{
    $json = file_get_contents(base_path("tests/Fixtures/amazon-creators-api/{$name}.json"));
    assert(is_string($json));
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($decoded));

    return $decoded;
}

/**
 * トークンの取得先は、呼ばれるたびに token-1・token-2… と違うトークンを返す。
 *
 * @param  array<string, mixed>  $api  API の URL → 応答
 */
function fakeAmazon(array $api, int $expiresIn = 3600): void
{
    $issued = 0;
    Http::fake([
        AMAZON_TOKEN_URL => function () use (&$issued, $expiresIn) {
            $issued++;

            return Http::response(['access_token' => "token-{$issued}", 'token_type' => 'bearer', 'expires_in' => $expiresIn]);
        },
        ...$api,
    ]);
}

function tokenRequestCount(): int
{
    return Http::recorded(fn (Request $request) => $request->url() === AMAZON_TOKEN_URL)->count();
}

/**
 * @return list<string>
 */
function apiAuthorizations(): array
{
    $authorizations = [];
    foreach (Http::recorded(fn (Request $request) => $request->url() !== AMAZON_TOKEN_URL) as [$request]) {
        $authorization = $request->header('Authorization')[0] ?? null;
        $authorizations[] = is_string($authorization) ? $authorization : '';
    }

    return $authorizations;
}

/**
 * $call が投げた例外を返す。投げなければテストを失敗させる。
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $class
 * @return T
 */
function amazonThrown(string $class, Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        if (! $e instanceof $class) {
            throw $e;
        }

        return $e;
    }

    throw new RuntimeException("{$class} が投げられなかった");
}

it('TC-135-01: 検索の応答から、商品名・詳細と検索の URL・画像 3 種・価格を取り出す', function () {
    fakeAmazon([AMAZON_SEARCH_URL => Http::response(amazonFixture('search-items'))]);

    $result = amazonClient()->searchItems('ジン');

    expect($result)->not->toBeNull();
    assert($result !== null);
    expect($result->searchUrl)->toBe('https://www.amazon.co.jp/s/?field-keywords=%E3%82%B8%E3%83%B3&search-alias=grocery&tag=example-22&linkCode=osi')
        ->and($result->items)->toHaveCount(2)
        ->and($result->items[0])->toEqual(new AmazonItem(
            asin: 'B000TEST01',
            title: 'サンプル ジン 700ml',
            detailPageUrl: 'https://www.amazon.co.jp/dp/B000TEST01?tag=example-22&linkCode=osi',
            imageUrlSmall: 'https://m.media-amazon.com/images/I/EXAMPLE1._SL75_.jpg',
            imageUrlMedium: 'https://m.media-amazon.com/images/I/EXAMPLE1._SL160_.jpg',
            imageUrlLarge: 'https://m.media-amazon.com/images/I/EXAMPLE1._SL500_.jpg',
            price: 1980,
            displayPrice: '￥1,980',
        ));
});

it('TC-135-02: 検索は、日本のマーケットプレイス・パートナータグ・食料品の分類・取る項目を付けて、取ったトークンで送る', function () {
    fakeAmazon([AMAZON_SEARCH_URL => Http::response(amazonFixture('search-items'))]);

    amazonClient()->searchItems('ジン');

    Http::assertSent(fn (Request $request) => $request->url() === AMAZON_TOKEN_URL
        && $request['grant_type'] === 'client_credentials'
        && $request['client_id'] === 'test-credential-id'
        && $request['client_secret'] === 'test-credential-secret'
        && $request['scope'] === 'creatorsapi::default');
    Http::assertSent(fn (Request $request) => $request->url() === AMAZON_SEARCH_URL
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer token-1')
        && $request->hasHeader('x-marketplace', 'www.amazon.co.jp')
        && $request['keywords'] === 'ジン'
        && $request['searchIndex'] === 'GroceryAndGourmetFood'
        && $request['partnerTag'] === 'example-22'
        && $request['marketplace'] === 'www.amazon.co.jp'
        && $request['resources'] === [
            'itemInfo.title',
            'images.primary.small',
            'images.primary.medium',
            'images.primary.large',
            'offersV2.listings.price',
        ]);
});

it('TC-135-03: 出品(offersV2)の無い商品は、価格と表示用の価格が null。応答に無い画像も null', function () {
    fakeAmazon([AMAZON_SEARCH_URL => Http::response(amazonFixture('search-items'))]);

    $result = amazonClient()->searchItems('ジン');
    assert($result !== null);

    expect($result->items[1]->asin)->toBe('B000TEST02')
        ->and($result->items[1]->price)->toBeNull()
        ->and($result->items[1]->displayPrice)->toBeNull()
        ->and($result->items[1]->imageUrlSmall)->toBeNull()
        ->and($result->items[1]->imageUrlMedium)->toBe('https://m.media-amazon.com/images/I/EXAMPLE2._SL160_.jpg')
        ->and($result->items[1]->imageUrlLarge)->toBeNull();
});

it('TC-135-04: 検索で 1 件も見つからない(404 ResourceNotFoundException)と、商品 0 件・検索 URL なしの結果を返す', function () {
    fakeAmazon([AMAZON_SEARCH_URL => Http::response([
        'type' => 'ResourceNotFoundException',
        'message' => 'No results found for the search request.',
    ], 404)]);

    $result = amazonClient()->searchItems('存在しない銘柄');

    expect($result)->not->toBeNull();
    assert($result !== null);
    expect($result->items)->toBe([])
        ->and($result->searchUrl)->toBeNull();
});

it('TC-135-05: ASIN で取ると、取れた商品を応答の並びで返し、価格を取り出す。取れない ASIN(errors)は入らない', function () {
    fakeAmazon([AMAZON_GET_ITEMS_URL => Http::response(amazonFixture('get-items-partial'))]);

    $items = amazonClient()->getItems(['B000TEST01', 'B000TEST09', 'B000TEST03']);

    expect(array_map(fn (AmazonItem $item) => $item->asin, $items))->toBe(['B000TEST03', 'B000TEST01'])
        ->and($items[0]->title)->toBe('サンプル ウォッカ 750ml')
        ->and($items[0]->price)->toBe(1280)
        ->and($items[0]->displayPrice)->toBe('￥1,280')
        ->and($items[0]->imageUrlLarge)->toBe('https://m.media-amazon.com/images/I/EXAMPLE3._SL500_.jpg')
        ->and($items[1]->price)->toBeNull();
    Http::assertSent(fn (Request $request) => $request->url() === AMAZON_GET_ITEMS_URL
        && $request['itemIds'] === ['B000TEST01', 'B000TEST09', 'B000TEST03']
        && $request['itemIdType'] === 'ASIN'
        && $request['partnerTag'] === 'example-22'
        && $request->hasHeader('x-marketplace', 'www.amazon.co.jp'));
});

it('TC-135-06: ASIN が 1 つも取れない(404 ResourceNotFoundException)と、空を返す', function () {
    fakeAmazon([AMAZON_GET_ITEMS_URL => Http::response([
        'type' => 'ResourceNotFoundException',
        'message' => 'No items found for the requested item IDs.',
        'resourceType' => 'Item',
        'resourceId' => 'B000TEST09',
    ], 404)]);

    expect(amazonClient()->getItems(['B000TEST09']))->toBe([]);
});

it('TC-135-07: ASIN は 10 件までなら 1 回で送る', function () {
    fakeAmazon([AMAZON_GET_ITEMS_URL => Http::response(amazonFixture('get-items-partial'))]);
    $asins = array_map(fn (int $i) => sprintf('B000TEST%02d', $i), range(1, 10));

    amazonClient()->getItems($asins);

    Http::assertSent(fn (Request $request) => $request->url() === AMAZON_GET_ITEMS_URL && $request['itemIds'] === $asins);
});

it('TC-135-08: ASIN が 11 件だと、送らずに InvalidArgumentException', function () {
    fakeAmazon([AMAZON_GET_ITEMS_URL => Http::response(amazonFixture('get-items-partial'))]);
    $asins = array_map(fn (int $i) => sprintf('B000TEST%02d', $i), range(1, 11));

    expect(fn () => amazonClient()->getItems($asins))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('TC-135-09: ASIN が空なら、どこにも送らずに空を返す', function () {
    fakeAmazon([]);

    expect(amazonClient()->getItems([]))->toBe([]);
    Http::assertNothingSent();
});

it('TC-135-10: 2 回目の呼び出しは、キャッシュしたトークンを使い、トークンを取り直さない', function () {
    fakeAmazon([
        AMAZON_SEARCH_URL => Http::response(amazonFixture('search-items')),
        AMAZON_GET_ITEMS_URL => Http::response(amazonFixture('get-items-partial')),
    ]);

    amazonClient()->searchItems('ジン');
    $this->travel(30)->minutes();
    amazonClient()->getItems(['B000TEST03']);

    expect(tokenRequestCount())->toBe(1)
        ->and(apiAuthorizations())->toBe(['Bearer token-1', 'Bearer token-1']);
});

it('TC-135-11: 期限(取ってから expires_in 秒)の 61 秒前は、キャッシュしたトークンを使う', function () {
    fakeAmazon([AMAZON_SEARCH_URL => Http::response(amazonFixture('search-items'))]);

    amazonClient()->searchItems('ジン');
    $this->travel(3600 - AmazonClient::TOKEN_REFRESH_MARGIN_SECONDS - 1)->seconds();
    amazonClient()->searchItems('ジン');

    expect(tokenRequestCount())->toBe(1)
        ->and(apiAuthorizations())->toBe(['Bearer token-1', 'Bearer token-1']);
});

it('TC-135-12: 期限の 60 秒前ちょうど(取り直す時刻ちょうど)からは、トークンを取り直す', function () {
    fakeAmazon([AMAZON_SEARCH_URL => Http::response(amazonFixture('search-items'))]);

    amazonClient()->searchItems('ジン');
    $this->travel(3600 - AmazonClient::TOKEN_REFRESH_MARGIN_SECONDS)->seconds();
    amazonClient()->searchItems('ジン');

    expect(tokenRequestCount())->toBe(2)
        ->and(apiAuthorizations())->toBe(['Bearer token-1', 'Bearer token-2']);
});

it('TC-135-13: 期限を過ぎていれば、トークンを取り直す', function () {
    fakeAmazon([AMAZON_SEARCH_URL => Http::response(amazonFixture('search-items'))]);

    amazonClient()->searchItems('ジン');
    $this->travel(3600 + 1)->seconds();
    amazonClient()->searchItems('ジン');

    expect(tokenRequestCount())->toBe(2)
        ->and(apiAuthorizations())->toBe(['Bearer token-1', 'Bearer token-2']);
});

it('TC-135-14: キャッシュの期限の前でも、API が 401 TokenExpired を返したら、トークンを取り直して 1 回だけ送り直す', function () {
    fakeAmazon([AMAZON_SEARCH_URL => Http::sequence()
        ->push(['type' => 'UnauthorizedException', 'message' => 'Authentication token has expired.', 'reason' => 'TokenExpired'], 401)
        ->push(amazonFixture('search-items')),
    ]);

    $result = amazonClient()->searchItems('ジン');

    expect($result?->items)->toHaveCount(2)
        ->and(tokenRequestCount())->toBe(2)
        ->and(apiAuthorizations())->toBe(['Bearer token-1', 'Bearer token-2']);
});

it('TC-135-15: 送り直しても 401 なら、もう送り直さずに AmazonApiException', function () {
    fakeAmazon([AMAZON_SEARCH_URL => Http::response(
        ['type' => 'UnauthorizedException', 'message' => 'Authentication token has expired.', 'reason' => 'TokenExpired'],
        401,
    )]);

    expect(amazonThrown(AmazonApiException::class, fn () => amazonClient()->searchItems('ジン'))->status)->toBe(401)
        ->and(apiAuthorizations())->toHaveCount(2);
});

it('TC-135-26: TokenExpired 以外の 401 は送り直さないが、トークンを捨て、次の呼び出しで取り直す', function () {
    fakeAmazon([AMAZON_SEARCH_URL => Http::sequence()
        ->push(['type' => 'UnauthorizedException', 'message' => 'The access token is invalid.', 'reason' => 'InvalidToken'], 401)
        ->push(amazonFixture('search-items')),
    ]);

    expect(amazonThrown(AmazonApiException::class, fn () => amazonClient()->searchItems('ジン'))->status)->toBe(401)
        ->and(apiAuthorizations())->toBe(['Bearer token-1']);

    amazonClient()->searchItems('ジン');

    expect(tokenRequestCount())->toBe(2)
        ->and(apiAuthorizations())->toBe(['Bearer token-1', 'Bearer token-2']);
});

it('TC-135-16: 資格情報を変えたら、前の資格情報のトークンを使わない', function () {
    fakeAmazon([AMAZON_SEARCH_URL => Http::response(amazonFixture('search-items'))]);

    amazonClient()->searchItems('ジン');
    app(Settings::class)->set(AmazonClient::SETTING_CREDENTIAL_SECRET, 'rotated-secret');
    amazonClient()->searchItems('ジン');

    expect(tokenRequestCount())->toBe(2)
        ->and(apiAuthorizations())->toBe(['Bearer token-1', 'Bearer token-2']);
});

it('TC-135-17: API が 429 を返したら AmazonRetryLaterException。待つ秒数があれば持つ', function (mixed $retryAfter, ?int $expected) {
    $body = ['type' => 'ThrottleException', 'message' => 'The request was denied due to request throttling.'];
    if ($retryAfter !== null) {
        $body['retryAfterSeconds'] = $retryAfter;
    }
    fakeAmazon([AMAZON_SEARCH_URL => Http::response($body, 429)]);

    expect(amazonThrown(AmazonRetryLaterException::class, fn () => amazonClient()->searchItems('ジン'))->retryAfterSeconds)
        ->toBe($expected);
})->with([
    '数値' => [60, 60],
    '文字列' => ['60', 60],
    '無い' => [null, null],
]);

it('TC-135-18: API が 5xx を返したら AmazonRetryLaterException', function (int $status) {
    fakeAmazon([AMAZON_GET_ITEMS_URL => Http::response(['type' => 'InternalServerException', 'message' => 'An unexpected error occurred.'], $status)]);

    expect(fn () => amazonClient()->getItems(['B000TEST01']))->toThrow(AmazonRetryLaterException::class);
})->with([500, 503, 599]);

it('TC-135-19: API につながらなければ AmazonRetryLaterException', function () {
    fakeAmazon([AMAZON_SEARCH_URL => fn () => throw new ConnectionException('Connection timed out')]);

    expect(fn () => amazonClient()->searchItems('ジン'))->toThrow(AmazonRetryLaterException::class);
});

it('TC-135-20: トークンの取得先が 429・5xx を返したら AmazonRetryLaterException。API には送らない', function (int $status) {
    Http::fake([AMAZON_TOKEN_URL => Http::response(['error' => 'slow_down'], $status, ['Retry-After' => '30'])]);

    expect(amazonThrown(AmazonRetryLaterException::class, fn () => amazonClient()->searchItems('ジン'))->retryAfterSeconds)->toBe(30)
        ->and(apiAuthorizations())->toBe([]);
})->with([429, 500]);

it('TC-135-21: トークンの取得先が 4xx(資格情報の誤り)を返したら AmazonApiException。API には送らない', function (int $status) {
    Http::fake([AMAZON_TOKEN_URL => Http::response(['error' => 'invalid_client', 'error_description' => 'Client authentication failed'], $status)]);

    expect(amazonThrown(AmazonApiException::class, fn () => amazonClient()->searchItems('ジン'))->status)->toBe($status)
        ->and(apiAuthorizations())->toBe([]);
})->with([400, 401]);

it('TC-135-22: API が 429・5xx 以外の 4xx を返したら AmazonApiException(時間を置いても通らない)', function (int $status, string $type) {
    fakeAmazon([AMAZON_SEARCH_URL => Http::response(['type' => $type, 'message' => 'error', 'reason' => 'AssociateNotEligible'], $status)]);

    expect(amazonThrown(AmazonApiException::class, fn () => amazonClient()->searchItems('ジン'))->status)->toBe($status);
})->with([
    '使える条件を満たしていない' => [403, 'AccessDeniedException'],
    '要求の誤り' => [400, 'ValidationException'],
    '商品が見つからない以外の 404' => [404, 'NotFound'],
]);

it('TC-135-23: 資格情報・パートナータグのどれかが無ければ、どこにも送らずに、検索は null・取得は空を返す', function (string $missing, ?string $value) {
    // キーに「.」があるので config()->set('settings.<key>') だと入れ子になる。配列ごと置き換える
    $settings = config('settings');
    assert(is_array($settings));
    config()->set('settings', [...$settings, $missing => $value]);
    fakeAmazon([]);

    $client = amazonClient();

    expect($client->isConfigured())->toBeFalse()
        ->and($client->searchItems('ジン'))->toBeNull()
        ->and($client->getItems(['B000TEST01']))->toBe([]);
    Http::assertNothingSent();
})->with([
    'Credential ID' => AmazonClient::SETTING_CREDENTIAL_ID,
    'Credential Secret' => AmazonClient::SETTING_CREDENTIAL_SECRET,
    'パートナータグ' => AmazonClient::SETTING_PARTNER_TAG,
])->with([
    '無い' => null,
    '空文字' => '',
]);

it('TC-135-24: 3 つとも .env にあれば使える。画面で保存した値は .env より優先して送る', function () {
    app(Settings::class)->set(AmazonClient::SETTING_PARTNER_TAG, 'from-screen-22');
    fakeAmazon([AMAZON_SEARCH_URL => Http::response(amazonFixture('search-items'))]);

    expect(amazonClient()->isConfigured())->toBeTrue();
    amazonClient()->searchItems('ジン');

    Http::assertSent(fn (Request $request) => $request->url() === AMAZON_SEARCH_URL && $request['partnerTag'] === 'from-screen-22');
});
