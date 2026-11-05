<?php

use App\Services\Affiliate\RakutenApiException;
use App\Services\Affiliate\RakutenClient;
use App\Services\Affiliate\RakutenItem;
use App\Services\Affiliate\RakutenNotConfiguredException;
use App\Services\Affiliate\RakutenRetryLaterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// 楽天市場の商品検索 API のクライアント(#72)。本物の API は呼ばない(preventStrayRequests)。
//
// tests/Fixtures/rakuten-ichiba-item-search/ の応答は、formatVersion=2 の応答の形(Items が商品の一覧で、
// smallImageUrls が URL の文字列の一覧。旧の RakutenFactory が読んでいた形と、楽天のリファレンスの出力の項目)
// に合わせて手で書いたもの。商品・店舗・アフィリエイト ID はすべて架空の値。本物の応答は、規約 第10条(9)
// (誰でも見られるところに API で得た情報を置かない。docs/rakuten-api.md の 3.2)のため、このリポジトリに置かない。
//
// 設定は Settings を通して読むので(画面で保存した値が DB にあるかを見る)、DB を使う。

uses(RefreshDatabase::class)->group('db');

const RAKUTEN_SEARCH_URL = 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260701';

const RAKUTEN_CONFIGURED = [
    RakutenClient::SETTING_APPLICATION_ID => 'test-application-id',
    RakutenClient::SETTING_ACCESS_KEY => 'test-access-key',
    'rakuten.affiliate_id' => 'g00test0.example0.g00test0.example1',
];

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    config()->set('settings', RAKUTEN_CONFIGURED);
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
});

function rakutenClient(): RakutenClient
{
    return app(RakutenClient::class);
}

/**
 * @return array<mixed>
 */
function rakutenFixture(string $name): array
{
    $json = file_get_contents(base_path("tests/Fixtures/rakuten-ichiba-item-search/{$name}.json"));
    assert(is_string($json));
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($decoded));

    return $decoded;
}

/**
 * 楽天に送った要求の、クエリの値の一覧。
 *
 * @return list<array<int|string, mixed>>
 */
function rakutenQueries(): array
{
    return array_map(fn (Request $request) => rakutenQuery($request), rakutenRequests());
}

/**
 * @return array<int|string, mixed>
 */
function rakutenQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

/**
 * 楽天に送った要求を、送った順に返す。
 *
 * @return list<Request>
 */
function rakutenRequests(): array
{
    $requests = [];
    foreach (Http::recorded() as [$request]) {
        $requests[] = $request;
    }

    return $requests;
}

/**
 * $call が投げた例外を返す。投げなければテストを失敗させる。
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $class
 * @return T
 */
function rakutenThrown(string $class, Closure $call): Throwable
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

it('TC-72-01: 検索の応答から、商品のコード・商品名・価格・商品のリンク・画像(元の大きさ)・販売できるかを、楽天の順で取り出す', function () {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(rakutenFixture('search'))]);

    $items = rakutenClient()->searchItems('ジン');

    expect($items)->toEqual([
        new RakutenItem(
            itemCode: 'example-shop:10000001',
            title: 'サンプル ジン 700ml',
            price: 2480,
            detailUrl: 'https://hb.afl.rakuten.co.jp/hgc/g00test0.example0.g00test0.example1/?pc=https%3A%2F%2Fitem.rakuten.co.jp%2Fexample-shop%2Fgin-001%2F&m=http%3A%2F%2Fm.rakuten.co.jp%2Fexample-shop%2Fi%2F10000001%2F',
            imageUrl: 'https://thumbnail.image.rakuten.co.jp/@0_mall/example-shop/cabinet/gin-001.jpg',
            available: true,
        ),
        new RakutenItem(
            itemCode: 'example-shop2:10000002',
            title: 'サンプル ドライ ジン 750ml',
            price: 3300,
            detailUrl: 'https://hb.afl.rakuten.co.jp/hgc/g00test0.example0.g00test0.example1/?pc=https%3A%2F%2Fitem.rakuten.co.jp%2Fexample-shop2%2Fgin-002%2F&m=http%3A%2F%2Fm.rakuten.co.jp%2Fexample-shop2%2Fi%2F10000002%2F',
            imageUrl: null,
            available: true,
        ),
    ]);
});

it('TC-72-02: 検索は、今の呼び出し先・版に、アプリ ID・formatVersion=2・アフィリエイト ID・語・洋酒のジャンルを付け、アクセスキーはヘッダーだけで送る', function () {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(rakutenFixture('search'))]);

    rakutenClient()->searchItems('ドライ ジン');

    Http::assertSentCount(1);
    $request = rakutenRequests()[0];
    expect($request->method())->toBe('GET')
        ->and(strtok($request->url(), '?'))->toBe(RAKUTEN_SEARCH_URL)
        ->and(rakutenQueries()[0])->toBe([
            'applicationId' => 'test-application-id',
            'formatVersion' => '2',
            'affiliateId' => 'g00test0.example0.g00test0.example1',
            'keyword' => 'ドライ ジン',
            'genreId' => '510915',
        ])
        ->and($request->header('accessKey'))->toBe(['test-access-key'])
        ->and($request->url())->not->toContain('test-access-key');
});

it('TC-72-03: ジャンルに null を渡すと、ジャンルで絞らずに探す', function () {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(rakutenFixture('search'))]);

    rakutenClient()->searchItems('トニックウォーター', null);

    expect(rakutenQueries()[0])->not->toHaveKey('genreId')
        ->and(rakutenQueries()[0]['keyword'])->toBe('トニックウォーター');
});

it('TC-72-04: アフィリエイト ID が設定に無ければ、affiliateId を付けずに呼ぶ', function () {
    config()->set('settings', [...RAKUTEN_CONFIGURED, 'rakuten.affiliate_id' => null]);
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(rakutenFixture('search'))]);

    rakutenClient()->searchItems('ジン');

    expect(rakutenQueries()[0])->not->toHaveKey('affiliateId');
});

it('TC-72-05: アプリ ID かアクセスキーが無ければ、楽天に問い合わせず、検索は null・isConfigured は false', function (array $settings) {
    config()->set('settings', [...RAKUTEN_CONFIGURED, ...$settings]);
    Http::fake();

    $client = rakutenClient();

    expect($client->searchItems('ジン'))->toBeNull()
        ->and($client->isConfigured())->toBeFalse();
    Http::assertNothingSent();
})->with([
    'アプリ ID が無い' => [[RakutenClient::SETTING_APPLICATION_ID => null]],
    'アクセスキーが無い' => [[RakutenClient::SETTING_ACCESS_KEY => null]],
    'アプリ ID が空' => [[RakutenClient::SETTING_APPLICATION_ID => '']],
]);

it('TC-72-06: 1 件も無い応答(200・Items が空)は、空の一覧を返す', function () {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(rakutenFixture('search-empty'))]);

    expect(rakutenClient()->searchItems('存在しない銘柄'))->toBe([]);
});

it('TC-72-07: 対象のデータが無い 404(not_found)は、空の一覧を返す', function () {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(['error' => 'not_found', 'error_description' => 'not found'], 404)]);

    expect(rakutenClient()->searchItems('存在しない銘柄'))->toBe([]);
});

it('TC-72-08: 語に空白があって 400 が返ったら、1 秒空けて、空白(半角・全角)を取り除いた語で 1 回だけやり直す', function () {
    $sentAt = [];
    $responses = Http::sequence()
        ->push(['error' => 'wrong_parameter', 'error_description' => 'keyword is not valid'], 400)
        ->push(rakutenFixture('search'));
    Http::fake([RAKUTEN_SEARCH_URL.'*' => function (Request $request) use (&$sentAt, $responses) {
        $sentAt[] = (int) now()->getPreciseTimestamp(3);

        return $responses->__invoke($request);
    }]);

    $items = rakutenClient()->searchItems('ジン A　B');

    expect($sentAt)->toHaveCount(2)
        ->and($sentAt[1] - $sentAt[0])->toBeGreaterThanOrEqual(1000)
        ->and(array_column(rakutenQueries(), 'keyword'))->toBe(['ジン A　B', 'ジンAB'])
        ->and(rakutenQueries()[1]['genreId'])->toBe('510915')
        ->and($items)->toHaveCount(2);
    Sleep::assertSequence([Sleep::for(1000)->milliseconds()]);
});

it('TC-72-09: やり直しも 400 なら、2 回目の失敗を楽天の error 付きの例外で返す', function () {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(['error' => 'wrong_parameter', 'error_description' => 'keyword is not valid'], 400)]);

    $e = rakutenThrown(RakutenApiException::class, fn () => rakutenClient()->searchItems('ジン A'));

    expect($e->status)->toBe(400)
        ->and($e->error)->toBe('wrong_parameter');
    Http::assertSentCount(2);
});

it('TC-72-10: 語に空白が無ければ、400 でもやり直さずに例外を返す', function () {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(['error' => 'wrong_parameter', 'error_description' => 'keyword is not valid'], 400)]);

    $e = rakutenThrown(RakutenApiException::class, fn () => rakutenClient()->searchItems('A'));

    expect($e->status)->toBe(400);
    Http::assertSentCount(1);
});

it('TC-72-11: 429・5xx は、やり直さずに「次の回にまわす」例外を返す(語に空白があっても)', function (int $status, string $error) {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(['error' => $error, 'error_description' => 'x'], $status)]);

    $e = rakutenThrown(RakutenRetryLaterException::class, fn () => rakutenClient()->searchItems('ドライ ジン'));

    expect($e->getMessage())->toContain((string) $status);
    Http::assertSentCount(1);
})->with([
    '429 上限' => [429, 'too_many_requests'],
    '503 メンテナンス' => [503, 'service_unavailable'],
    '500' => [500, 'system_error'],
]);

it('TC-72-12: つながらないときは「次の回にまわす」例外を返し、文にアプリ ID・アクセスキーを含めない', function () {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => function () {
        throw new ConnectionException('cURL error 28: timed out for '.RAKUTEN_SEARCH_URL.'?applicationId=test-application-id');
    }]);

    $e = rakutenThrown(RakutenRetryLaterException::class, fn () => rakutenClient()->searchItems('ジン'));

    expect($e->getMessage())->not->toContain('test-application-id')
        ->and($e->getMessage())->not->toContain('test-access-key')
        ->and($e->getPrevious())->toBeNull();
});

it('TC-72-13: アプリ ID・アクセスキーの誤り(400 以外の 4xx)は、やり直さない失敗として返す', function (int $status, string $error) {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(['error' => $error, 'error_description' => 'x'], $status)]);

    $e = rakutenThrown(RakutenApiException::class, fn () => rakutenClient()->searchItems('ドライ ジン'));

    expect($e->status)->toBe($status)
        ->and($e->error)->toBe($error);
    Http::assertSentCount(1);
})->with([
    '401' => [401, 'invalid_access_key'],
    '403' => [403, 'forbidden'],
]);

it('TC-72-14: 商品のコードで 1 件を取り直す。売り切れも取り(availability=0)、アフィリエイト ID が無ければ商品のページは itemUrl', function () {
    config()->set('settings', [...RAKUTEN_CONFIGURED, 'rakuten.affiliate_id' => null]);
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(rakutenFixture('item-code-sold-out'))]);

    $item = rakutenClient()->getItem('example-shop:10000001');

    expect($item)->toEqual(new RakutenItem(
        itemCode: 'example-shop:10000001',
        title: 'サンプル ジン 700ml',
        price: 2580,
        detailUrl: 'https://item.rakuten.co.jp/example-shop/gin-001/',
        imageUrl: 'https://thumbnail.image.rakuten.co.jp/@0_mall/example-shop/cabinet/gin-001.jpg',
        available: false,
    ))
        ->and(rakutenQueries()[0])->toBe([
            'applicationId' => 'test-application-id',
            'formatVersion' => '2',
            'itemCode' => 'example-shop:10000001',
            'availability' => '0',
        ]);
});

it('TC-72-15: 商品のコードで取れない(404・0 件・別の商品だけが返る)ときは null', function (Closure $response) {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => $response()]);

    expect(rakutenClient()->getItem('example-shop:99999999'))->toBeNull();
})->with([
    '404 not_found' => [fn () => Http::response(['error' => 'not_found', 'error_description' => 'not found'], 404)],
    '200・0 件' => [fn () => Http::response(rakutenFixture('search-empty'))],
    '別のコードの商品だけ' => [fn () => Http::response(rakutenFixture('search'))],
]);

it('TC-72-16: アプリ ID かアクセスキーが無いのに商品のコードで取ろうとすると、楽天に問い合わせずに例外を投げる(見つからない null と分ける)', function () {
    config()->set('settings', [...RAKUTEN_CONFIGURED, RakutenClient::SETTING_ACCESS_KEY => null]);
    Http::fake();

    rakutenThrown(RakutenNotConfiguredException::class, fn () => rakutenClient()->getItem('example-shop:10000001'));

    Http::assertNothingSent();
});

it('TC-72-17: 同じクライアントで続けて呼ぶと、前の呼び出しから 1 秒たつまで待ってから送る。1 秒たっていれば待たない', function () {
    $sentAt = [];
    Http::fake([RAKUTEN_SEARCH_URL.'*' => function () use (&$sentAt) {
        $sentAt[] = (int) now()->getPreciseTimestamp(3);

        return Http::response(rakutenFixture('search'));
    }]);
    $client = rakutenClient();

    $client->searchItems('ジン');
    $this->travel(300)->milliseconds();
    $client->getItem('example-shop:10000001');
    $this->travel(1500)->milliseconds();
    $client->searchItems('ラム');

    Http::assertSentCount(3);
    expect($sentAt[1] - $sentAt[0])->toBeGreaterThanOrEqual(1000)
        ->and($sentAt[2] - $sentAt[1])->toBeGreaterThanOrEqual(1000);
    Sleep::assertSequence([Sleep::for(700)->milliseconds()]);
});

it('TC-72-18: 画像の URL に ?_ex= が無ければそのまま、価格が整数でなければ null、affiliateUrl が空なら itemUrl を使う', function () {
    Http::fake([RAKUTEN_SEARCH_URL.'*' => Http::response(['count' => 1, 'Items' => [[
        'itemName' => 'サンプル ラム',
        'itemCode' => 'example-shop:10000003',
        'itemPrice' => 'abc',
        'itemUrl' => 'https://item.rakuten.co.jp/example-shop/rum-003/',
        'affiliateUrl' => '',
        'imageFlag' => 1,
        'smallImageUrls' => ['https://thumbnail.image.rakuten.co.jp/@0_mall/example-shop/cabinet/rum-003.jpg'],
        'availability' => 1,
    ], ['itemName' => 'コードの無い商品']]])]);

    expect(rakutenClient()->searchItems('ラム'))->toEqual([new RakutenItem(
        itemCode: 'example-shop:10000003',
        title: 'サンプル ラム',
        price: null,
        detailUrl: 'https://item.rakuten.co.jp/example-shop/rum-003/',
        imageUrl: 'https://thumbnail.image.rakuten.co.jp/@0_mall/example-shop/cabinet/rum-003.jpg',
        available: true,
    )]);
});
