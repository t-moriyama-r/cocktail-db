<?php

use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

// 商品の欄に出すアフィリエイトの API(GET /api/affiliates。#47)。
// 期待値は、手元で動かした旧の BE(AffiliateController@getAffiliateData)の応答と旧の画面(AffiliateContainer.vue)の選び方、
// docs/decisions/affiliate-freshness.md(#247)の旧との差分から置く。

uses(RefreshDatabase::class)->group('db');

const AFFILIATE_NOW = '2026-10-27 12:00:00';

/**
 * Creators API を使える設定(認証情報とパートナータグがそろっている)。どれかが無いと Amazon の値を返さない(#136)ので、
 * 既定はこれにする。値はテスト用の仮のもの。
 */
const AMAZON_CONFIGURED = [
    'amazon.credential_id' => 'test-credential-id',
    'amazon.credential_secret' => 'test-credential-secret',
    'amazon.partner_tag' => 'cocktail-22',
];

beforeEach(function () {
    $this->travelTo(Carbon::parse(AFFILIATE_NOW));
    config()->set('settings', AMAZON_CONFIGURED);
});

/**
 * Amazon から $fetchedAt に取った値。
 *
 * @return array<string, mixed>
 */
function amazonValues(string $fetchedAt, int $price = 2000): array
{
    return [
        'amazon_asin' => 'B000000001',
        'amazon_title' => 'Amazon の商品',
        'amazon_price' => $price,
        'amazon_image_large_url' => 'https://m.media-amazon.example/L.jpg',
        'amazon_image_medium_url' => 'https://m.media-amazon.example/M.jpg',
        'amazon_image_small_url' => 'https://m.media-amazon.example/S.jpg',
        'amazon_detail_url' => 'https://www.amazon.co.jp/dp/B000000001?tag=from-api-22',
        'amazon_search_url' => 'https://www.amazon.co.jp/s?k=from-api&tag=from-api-22',
        'amazon_fetched_at' => $fetchedAt,
    ];
}

/**
 * 楽天から $fetchedAt に取った値。
 *
 * @return array<string, mixed>
 */
function rakutenValues(string $fetchedAt, int $price = 1500): array
{
    return [
        'rakuten_item_code' => 'shop:item-1',
        'rakuten_title' => '楽天の商品',
        'rakuten_price' => $price,
        'rakuten_image_url' => 'https://thumbnail.image.rakuten.example/r.jpg',
        'rakuten_detail_url' => 'https://hb.afl.rakuten.example/item-1',
        'rakuten_search_url' => 'https://hb.afl.rakuten.example/search-from-db',
        'rakuten_fetched_at' => $fetchedAt,
    ];
}

/**
 * 手で入れた値。
 *
 * @return array<string, mixed>
 */
function humanValues(): array
{
    return [
        'human_title' => '手入力の商品',
        'human_price' => 1000,
        'human_image_url' => 'https://example.com/human.jpg',
        'human_amazon_detail_url' => 'https://www.amazon.co.jp/dp/B00000000H',
        'human_amazon_search_url' => 'https://www.amazon.co.jp/s?k=human',
    ];
}

/**
 * ノードと、そのノードの行を作る。
 *
 * @param  array<string, mixed>  $values
 */
function nodeAffiliate(array $values = [], string $name = 'ジン'): Affiliate
{
    $node = Category::factory()->create(['name' => $name]);

    return Affiliate::factory()->create(['category_id' => $node->id, ...$values]);
}

/** 期限内(取ってから 1 時間) */
function freshAt(): string
{
    return Carbon::parse(AFFILIATE_NOW)->subHour()->toDateTimeString();
}

/** 期限切れ(旧の値と同じく 2025-11 に取った) */
function expiredAt(): string
{
    return '2025-11-01 10:20:30';
}

/**
 * そのノードの ID だけを付けて API を呼ぶ。
 *
 * @return TestResponse<Response>
 */
function getAffiliate(TestCase $test, Affiliate $affiliate): TestResponse
{
    return $test->getJson('/api/affiliates?category_id='.$affiliate->category_id);
}

it('TC-47-01: 手入力の価格があれば、Amazon・楽天が期限内でも手入力の値を出す', function () {
    $affiliate = nodeAffiliate([...humanValues(), ...amazonValues(freshAt()), ...rakutenValues(freshAt())]);

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.display', [
            'source' => 'human',
            'title' => '手入力の商品',
            'price' => 1000,
            'image_url' => 'https://example.com/human.jpg',
            'detail_url' => 'https://www.amazon.co.jp/dp/B00000000H',
            'fetched_at' => null,
        ])
        ->assertJsonPath('data.amazon.price', 2000)
        ->assertJsonPath('data.rakuten.price', 1500);
});

it('TC-47-02: 手入力の価格が無ければ、期限内の Amazon の値を出す(楽天の方が安くても)', function () {
    $affiliate = nodeAffiliate([...amazonValues(freshAt(), 2000), ...rakutenValues(freshAt(), 1500)]);

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.display', [
            'source' => 'amazon',
            'title' => 'Amazon の商品',
            'price' => 2000,
            'image_url' => 'https://m.media-amazon.example/M.jpg',
            'detail_url' => 'https://www.amazon.co.jp/dp/B000000001?tag=from-api-22',
            'fetched_at' => Carbon::parse(freshAt())->toJSON(),
        ]);
});

it('TC-47-03: 手入力・Amazon の価格が無ければ、期限内の楽天の値を出す', function () {
    $affiliate = nodeAffiliate(rakutenValues(freshAt()));

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.display', [
            'source' => 'rakuten',
            'title' => '楽天の商品',
            'price' => 1500,
            'image_url' => 'https://thumbnail.image.rakuten.example/r.jpg',
            'detail_url' => 'https://hb.afl.rakuten.example/item-1',
            'fetched_at' => Carbon::parse(freshAt())->toJSON(),
        ]);
});

it('TC-47-04: どれにも価格が無ければ、出す 1 件は無い(提供元の値は返す)', function () {
    $affiliate = nodeAffiliate([
        ...amazonValues(freshAt()), 'amazon_price' => null,
        'human_title' => '題名だけ', 'human_price' => null,
    ]);

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.display', null)
        ->assertJsonPath('data.amazon.title', 'Amazon の商品')
        ->assertJsonPath('data.human.title', '題名だけ');
});

it('TC-47-05: Amazon の値は、取ってから 24 時間たつまで返し、ちょうど 24 時間で返さない', function (int $secondsAgo, bool $shown) {
    $fetchedAt = Carbon::parse(AFFILIATE_NOW)->subSeconds($secondsAgo)->toDateTimeString();
    $affiliate = nodeAffiliate(amazonValues($fetchedAt));

    $response = getAffiliate($this, $affiliate)->assertOk();

    $response->assertJsonPath('data.amazon.price', $shown ? 2000 : null)
        ->assertJsonPath('data.amazon.title', $shown ? 'Amazon の商品' : null)
        ->assertJsonPath('data.amazon.image_url', $shown ? 'https://m.media-amazon.example/M.jpg' : null)
        ->assertJsonPath('data.amazon.detail_url', $shown ? 'https://www.amazon.co.jp/dp/B000000001?tag=from-api-22' : null)
        ->assertJsonPath('data.amazon.fetched_at', $shown ? Carbon::parse($fetchedAt)->toJSON() : null)
        ->assertJsonPath('data.display.source', $shown ? 'amazon' : null);
})->with([
    '24 時間の 1 秒前' => [24 * 3600 - 1, true],
    'ちょうど 24 時間' => [24 * 3600, false],
    '24 時間の 1 秒後' => [24 * 3600 + 1, false],
]);

it('TC-47-06: 楽天の値は、取ってから 24 時間たつまで返し、ちょうど 24 時間で返さない', function (int $secondsAgo, bool $shown) {
    $fetchedAt = Carbon::parse(AFFILIATE_NOW)->subSeconds($secondsAgo)->toDateTimeString();
    $affiliate = nodeAffiliate(rakutenValues($fetchedAt));

    $response = getAffiliate($this, $affiliate)->assertOk();

    $response->assertJsonPath('data.rakuten.price', $shown ? 1500 : null)
        ->assertJsonPath('data.rakuten.title', $shown ? '楽天の商品' : null)
        ->assertJsonPath('data.rakuten.image_url', $shown ? 'https://thumbnail.image.rakuten.example/r.jpg' : null)
        ->assertJsonPath('data.rakuten.detail_url', $shown ? 'https://hb.afl.rakuten.example/item-1' : null)
        ->assertJsonPath('data.rakuten.fetched_at', $shown ? Carbon::parse($fetchedAt)->toJSON() : null)
        ->assertJsonPath('data.display.source', $shown ? 'rakuten' : null);
})->with([
    '24 時間の 1 秒前' => [24 * 3600 - 1, true],
    'ちょうど 24 時間' => [24 * 3600, false],
    '24 時間の 1 秒後' => [24 * 3600 + 1, false],
]);

it('TC-47-07: Amazon だけが期限切れなら、期限内の楽天の値を出し、Amazon は名前から組み立てた検索のリンクだけを返す', function () {
    $affiliate = nodeAffiliate([...amazonValues(expiredAt()), ...rakutenValues(freshAt())]);

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.display.source', 'rakuten')
        ->assertJsonPath('data.amazon', [
            'title' => null,
            'price' => null,
            'image_url' => null,
            'detail_url' => null,
            'search_url' => 'https://www.amazon.co.jp/s?k=%E3%82%B8%E3%83%B3&tag=cocktail-22',
            'fetched_at' => null,
        ])
        ->assertJsonPath('data.rakuten.search_url', 'https://hb.afl.rakuten.example/search-from-db');
});

it('TC-47-08: Amazon・楽天とも期限切れで手入力も無ければ、名前と検索のリンクだけを返す', function () {
    $affiliate = nodeAffiliate([...amazonValues(expiredAt()), ...rakutenValues(expiredAt())]);

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertExactJson(['data' => [
            'category_id' => $affiliate->category_id,
            'brand_id' => null,
            'name' => 'ジン',
            'display' => null,
            'amazon' => [
                'title' => null, 'price' => null, 'image_url' => null, 'detail_url' => null,
                'search_url' => 'https://www.amazon.co.jp/s?k=%E3%82%B8%E3%83%B3&tag=cocktail-22',
                'fetched_at' => null,
            ],
            'rakuten' => [
                'title' => null, 'price' => null, 'image_url' => null, 'detail_url' => null,
                'search_url' => 'https://search.rakuten.co.jp/search/mall/%E3%82%B8%E3%83%B3/510915/',
                'fetched_at' => null,
            ],
            'human' => [
                'title' => null, 'price' => null, 'image_url' => null,
                'amazon_detail_url' => null, 'amazon_search_url' => null,
            ],
        ]]);
});

it('TC-47-09: 手で入れた値は、Amazon・楽天が期限切れでも期限なしで返す', function () {
    $affiliate = nodeAffiliate([...humanValues(), ...amazonValues(expiredAt()), ...rakutenValues(expiredAt())]);

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.display.source', 'human')
        ->assertJsonPath('data.display.price', 1000)
        ->assertJsonPath('data.human', [
            'title' => '手入力の商品',
            'price' => 1000,
            'image_url' => 'https://example.com/human.jpg',
            'amazon_detail_url' => 'https://www.amazon.co.jp/dp/B00000000H',
            'amazon_search_url' => 'https://www.amazon.co.jp/s?k=human',
        ])
        ->assertJsonPath('data.amazon.price', null)
        ->assertJsonPath('data.rakuten.price', null);
});

it('TC-47-10: 期限内なら、Amazon・楽天それぞれの値と取った時刻、保存した検索のリンクを返す', function () {
    $amazonAt = Carbon::parse(AFFILIATE_NOW)->subHours(3)->toDateTimeString();
    $rakutenAt = Carbon::parse(AFFILIATE_NOW)->subHours(5)->toDateTimeString();
    $affiliate = nodeAffiliate([...amazonValues($amazonAt), ...rakutenValues($rakutenAt)]);

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.amazon', [
            'title' => 'Amazon の商品',
            'price' => 2000,
            'image_url' => 'https://m.media-amazon.example/M.jpg',
            'detail_url' => 'https://www.amazon.co.jp/dp/B000000001?tag=from-api-22',
            'search_url' => 'https://www.amazon.co.jp/s?k=from-api&tag=from-api-22',
            'fetched_at' => '2026-10-27T09:00:00.000000Z',
        ])
        ->assertJsonPath('data.rakuten', [
            'title' => '楽天の商品',
            'price' => 1500,
            'image_url' => 'https://thumbnail.image.rakuten.example/r.jpg',
            'detail_url' => 'https://hb.afl.rakuten.example/item-1',
            'search_url' => 'https://hb.afl.rakuten.example/search-from-db',
            'fetched_at' => '2026-10-27T07:00:00.000000Z',
        ]);
});

it('TC-47-11: 銘柄を指定したら、その銘柄の行を返し、名前は銘柄の名前にする', function () {
    $node = Category::factory()->create(['name' => 'ジン']);
    $brand = Brand::factory()->create(['category_id' => $node->id, 'name' => 'ビーフィーター']);
    Affiliate::factory()->create(['category_id' => $node->id, ...humanValues(), 'human_title' => 'ノードの行']);
    Affiliate::factory()->create(['category_id' => $node->id, 'brand_id' => $brand->id, ...humanValues(), 'human_title' => '銘柄の行']);

    $this->getJson("/api/affiliates?category_id={$node->id}&brand_id={$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.brand_id', $brand->id)
        ->assertJsonPath('data.name', 'ビーフィーター')
        ->assertJsonPath('data.display.title', '銘柄の行');
});

it('TC-47-12: 銘柄を指定せず、ノードに有名な銘柄があれば、その銘柄の行を返す', function () {
    $node = Category::factory()->create(['name' => 'ラム']);
    $brand = Brand::factory()->create(['category_id' => $node->id, 'name' => 'バカルディ']);
    $node->update(['famous_brand_id' => $brand->id]);
    Affiliate::factory()->create(['category_id' => $node->id, ...humanValues(), 'human_title' => 'ノードの行']);
    Affiliate::factory()->create(['category_id' => $node->id, 'brand_id' => $brand->id, ...humanValues(), 'human_title' => '銘柄の行']);

    $this->getJson("/api/affiliates?category_id={$node->id}")
        ->assertOk()
        ->assertJsonPath('data.brand_id', $brand->id)
        ->assertJsonPath('data.name', 'バカルディ')
        ->assertJsonPath('data.display.title', '銘柄の行');
});

it('TC-47-13: 銘柄の ID の 0 は、指定しないのと同じにする', function () {
    $affiliate = nodeAffiliate(humanValues());

    $this->getJson("/api/affiliates?category_id={$affiliate->category_id}&brand_id=0")
        ->assertOk()
        ->assertJsonPath('data.brand_id', null)
        ->assertJsonPath('data.name', 'ジン');
});

it('TC-47-14: 行の無いノード・銘柄は 404', function () {
    $node = Category::factory()->create();
    $brand = Brand::factory()->create(['category_id' => $node->id]);
    $famousNode = Category::factory()->create();
    $famous = Brand::factory()->create(['category_id' => $famousNode->id]);
    $famousNode->update(['famous_brand_id' => $famous->id]);
    // 有名な銘柄のノードは、ノードの行があっても銘柄の行を探す
    Affiliate::factory()->create(['category_id' => $famousNode->id, ...humanValues()]);

    $this->getJson("/api/affiliates?category_id={$node->id}")->assertNotFound();
    $this->getJson("/api/affiliates?category_id={$node->id}&brand_id={$brand->id}")->assertNotFound();
    $this->getJson("/api/affiliates?category_id={$famousNode->id}")->assertNotFound();
});

it('TC-47-15: 無いノード・銘柄の ID は 404', function () {
    $affiliate = nodeAffiliate(humanValues());

    $this->getJson('/api/affiliates?category_id='.($affiliate->category_id + 1000))->assertNotFound();
    $this->getJson("/api/affiliates?category_id={$affiliate->category_id}&brand_id=999999")->assertNotFound();
});

it('TC-47-16: ノードの ID が無い・数でないときは 422', function (string $query) {
    $this->getJson('/api/affiliates'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');
})->with([
    '無い' => [''],
    '数でない' => ['?category_id=abc'],
]);

it('TC-47-17: 商品が一度も見つかっていない提供元は、検索のリンクも返さない', function () {
    $affiliate = nodeAffiliate(rakutenValues(expiredAt()));

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.amazon.search_url', null)
        ->assertJsonPath('data.rakuten.search_url', 'https://search.rakuten.co.jp/search/mall/%E3%82%B8%E3%83%B3/510915/');
});

it('TC-47-18: 期限切れの検索のリンクは、設定にあればパートナータグ・アフィリエイトの ID を付けて組み立てる', function (string $name, string $amazon, string $rakuten) {
    config()->set('settings', [...AMAZON_CONFIGURED, 'rakuten.affiliate_id' => '1a2b3c4d.5e6f7a8b']);
    $affiliate = nodeAffiliate([...amazonValues(expiredAt()), ...rakutenValues(expiredAt())], $name);

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.amazon.search_url', $amazon)
        ->assertJsonPath('data.rakuten.search_url', $rakuten);
})->with([
    '日本語の名前' => [
        'ジン',
        'https://www.amazon.co.jp/s?k=%E3%82%B8%E3%83%B3&tag=cocktail-22',
        // 旧(Rakuten::set_searchURL)と同じ形
        'https://hb.afl.rakuten.co.jp/hgc/1a2b3c4d.5e6f7a8b/?pc=https%3A%2F%2Fsearch.rakuten.co.jp%2Fsearch%2Fmall%2F%E3%82%B8%E3%83%B3%2F510915%2F',
    ],
    '空白と記号を含む名前' => [
        'Gordon & Gin',
        'https://www.amazon.co.jp/s?k=Gordon%20%26%20Gin&tag=cocktail-22',
        'https://hb.afl.rakuten.co.jp/hgc/1a2b3c4d.5e6f7a8b/?pc=https%3A%2F%2Fsearch.rakuten.co.jp%2Fsearch%2Fmall%2FGordon+%26+Gin%2F510915%2F',
    ],
]);

// Amazon の認証情報・パートナータグが設定に無いとき(#136)。設定は App\Support\Settings が .env(config/settings.php)から読む

it('TC-136-01: 認証情報が無ければ、期限内の Amazon の値も返さず、パートナータグ付きの検索結果のリンクだけを返す', function (array $settings) {
    config()->set('settings', $settings);
    $affiliate = nodeAffiliate(amazonValues(freshAt()));

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.display', null)
        ->assertJsonPath('data.amazon', [
            'title' => null,
            'price' => null,
            'image_url' => null,
            'detail_url' => null,
            'search_url' => 'https://www.amazon.co.jp/s?k=%E3%82%B8%E3%83%B3&tag=cocktail-22',
            'fetched_at' => null,
        ]);
})->with([
    'ID と secret が無い' => [['amazon.partner_tag' => 'cocktail-22']],
    'ID だけが無い' => [['amazon.credential_secret' => 'test-credential-secret', 'amazon.partner_tag' => 'cocktail-22']],
    'secret だけが無い' => [['amazon.credential_id' => 'test-credential-id', 'amazon.partner_tag' => 'cocktail-22']],
    'ID と secret が空の文字列' => [['amazon.credential_id' => '', 'amazon.credential_secret' => '', 'amazon.partner_tag' => 'cocktail-22']],
]);

it('TC-136-02: 認証情報が無ければ、Amazon で商品が一度も見つかっていない行でも、検索結果のリンクを返す', function () {
    config()->set('settings', ['amazon.partner_tag' => 'cocktail-22']);
    $affiliate = nodeAffiliate();

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.amazon.search_url', 'https://www.amazon.co.jp/s?k=%E3%82%B8%E3%83%B3&tag=cocktail-22');
});

it('TC-136-03: パートナータグが無ければ、認証情報があっても Amazon の値を返さず、タグの無い検索結果のリンクだけを返す', function (array $settings) {
    config()->set('settings', $settings);
    $affiliate = nodeAffiliate(amazonValues(freshAt()));

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.display', null)
        ->assertJsonPath('data.amazon', [
            'title' => null,
            'price' => null,
            'image_url' => null,
            'detail_url' => null,
            'search_url' => 'https://www.amazon.co.jp/s?k=%E3%82%B8%E3%83%B3',
            'fetched_at' => null,
        ]);
})->with([
    'パートナータグが無い' => [['amazon.credential_id' => 'test-credential-id', 'amazon.credential_secret' => 'test-credential-secret']],
    'パートナータグが空の文字列' => [['amazon.credential_id' => 'test-credential-id', 'amazon.credential_secret' => 'test-credential-secret', 'amazon.partner_tag' => '']],
]);

it('TC-136-04: 認証情報とパートナータグがそろっていれば、期限内の Amazon の値と保存した検索結果のリンクを返す', function () {
    $affiliate = nodeAffiliate(amazonValues(freshAt()));

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.display.source', 'amazon')
        ->assertJsonPath('data.amazon', [
            'title' => 'Amazon の商品',
            'price' => 2000,
            'image_url' => 'https://m.media-amazon.example/M.jpg',
            'detail_url' => 'https://www.amazon.co.jp/dp/B000000001?tag=from-api-22',
            'search_url' => 'https://www.amazon.co.jp/s?k=from-api&tag=from-api-22',
            'fetched_at' => '2026-10-27T11:00:00.000000Z',
        ]);
});

it('TC-136-05: 認証情報とパートナータグがそろっていて、Amazon で商品が一度も見つかっていなければ、検索結果のリンクも返さない', function () {
    $affiliate = nodeAffiliate(rakutenValues(freshAt()));

    getAffiliate($this, $affiliate)
        ->assertOk()
        ->assertJsonPath('data.amazon.search_url', null);
});

it('TC-136-06: 認証情報が無くても、楽天と手で入れた値はそのまま返す', function () {
    config()->set('settings', ['amazon.partner_tag' => 'cocktail-22']);
    $withRakuten = nodeAffiliate([...amazonValues(freshAt(), 1000), ...rakutenValues(freshAt(), 1500)]);
    $withHuman = nodeAffiliate([...humanValues(), ...amazonValues(freshAt())], 'ラム');

    getAffiliate($this, $withRakuten)
        ->assertOk()
        ->assertJsonPath('data.display.source', 'rakuten')
        ->assertJsonPath('data.display.price', 1500)
        ->assertJsonPath('data.rakuten.price', 1500)
        ->assertJsonPath('data.rakuten.search_url', 'https://hb.afl.rakuten.example/search-from-db');
    getAffiliate($this, $withHuman)
        ->assertOk()
        ->assertJsonPath('data.display.source', 'human')
        ->assertJsonPath('data.human.amazon_detail_url', 'https://www.amazon.co.jp/dp/B00000000H')
        ->assertJsonPath('data.human.amazon_search_url', 'https://www.amazon.co.jp/s?k=human');
});
