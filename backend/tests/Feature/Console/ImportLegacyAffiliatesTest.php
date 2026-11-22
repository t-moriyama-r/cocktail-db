<?php

use App\Models\Affiliate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\LegacyFixture;
use Tests\Support\LegacyImportCommand;
use Tests\Support\LegacyNodeMap;
use Tests\TestCase;

// legacy:import --only=affiliates(アフィリエイトを移す。#32)。旧 DB を使うので db グループ

uses(RefreshDatabase::class)->group('db');

/** テストの旧データの行で、Amazon の商品のページの URL に入れる ASIN(仮のもの) */
const LEGACY_ASIN = 'B0SAMPLE01';

/**
 * カテゴリ木と銘柄を移してから、アフィリエイトを移す。
 */
function importLegacyAffiliates(TestCase $test): PendingCommand
{
    LegacyImportCommand::run($test, ['--only' => 'categories'])->assertExitCode(0)->run();
    LegacyImportCommand::run($test, ['--only' => 'brands'])->assertExitCode(0)->run();

    return LegacyImportCommand::run($test, ['--only' => 'affiliates']);
}

/**
 * 旧のアフィリエイトの、キーが (category_id, middle_id, brand_id) の行を書き換える。
 *
 * @param  array<string, mixed>  $values
 */
function updateLegacyAffiliate(int $categoryId, int $middleId, int $brandId, array $values): void
{
    DB::connection('legacy')->table('affiliates')
        ->where(['category_id' => $categoryId, 'middle_id' => $middleId, 'brand_id' => $brandId])
        ->update($values);
}

/**
 * 旧のアフィリエイトの行を足す。
 */
function insertLegacyAffiliate(int $categoryId, int $middleId, int $brandId): void
{
    DB::connection('legacy')->table('affiliates')->insert([
        'category_id' => $categoryId, 'middle_id' => $middleId, 'brand_id' => $brandId, 'name' => 'サンプルの商品',
    ]);
}

/**
 * 旧の楽天のアフィリエイトの転送の URL。転送先の商品のページを pc= に入れる。
 */
function rakutenAffiliateUrl(string $itemUrl): string
{
    return 'https://hb.afl.rakuten.co.jp/hgc/sample-affiliate-id/?pc='.urlencode($itemUrl).'&m='.urlencode($itemUrl);
}

/**
 * 移した affiliates を、id の順に比べられる形で返す。
 *
 * @return list<array<mixed>>
 */
function importedAffiliateRows(): array
{
    $rows = [];
    foreach (DB::table('affiliates')->orderBy('id')->get() as $row) {
        $rows[] = (array) $row;
    }

    return $rows;
}

beforeEach(function () {
    LegacyFixture::load();
});

it('TC-32-01: アフィリエイトの件数が旧と同じで、件数を出力する', function () {
    importLegacyAffiliates($this)
        ->expectsOutput('affiliates: 3')
        ->assertExitCode(0)
        ->run();

    expect(DB::table('affiliates')->count())
        ->toBe(DB::connection('legacy')->table('affiliates')->count())
        ->toBe(3);
});

it('TC-32-02: ミドルも銘柄も 0 の行は、カテゴリのノードの行(銘柄は null)にする', function () {
    importLegacyAffiliates($this)->assertExitCode(0)->run();

    $affiliate = Affiliate::query()->whereNull('brand_id')->sole();
    expect($affiliate->category_id)->toBe(LegacyNodeMap::id('m_category', 4));
});

it('TC-32-03: ミドルの下の銘柄の行は、その銘柄の行にし、ノードは銘柄の所属ノードにする', function () {
    importLegacyAffiliates($this)->assertExitCode(0)->run();

    $affiliate = Affiliate::query()->where('brand_id', 2)->sole();
    expect($affiliate->category_id)->toBe(LegacyNodeMap::id('m_middle', 3))
        ->and($affiliate->category_id)->toBe(DB::table('brands')->where('id', 2)->value('category_id'));
});

it('TC-32-04: ミドルが 0 の銘柄の行は、その銘柄の行にし、ノードは銘柄の所属ノード(カテゴリ)にする', function () {
    importLegacyAffiliates($this)->assertExitCode(0)->run();

    expect(Affiliate::query()->where('brand_id', 1)->sole()->category_id)->toBe(LegacyNodeMap::id('m_category', 1));
});

it('TC-32-05: ミドルの親とカテゴリが合わない銘柄の行は、カテゴリの列を見ずに銘柄の所属ノード(ミドル)にする(#28)', function () {
    // 銘柄 4 は、カテゴリ 1(ジン)・ミドル 3(親はカテゴリ 2 のラム)。#30 でミドルのノードに付けた
    insertLegacyAffiliate(1, 3, 4);

    importLegacyAffiliates($this)->assertExitCode(0)->run();

    expect(Affiliate::query()->where('brand_id', 4)->sole()->category_id)->toBe(LegacyNodeMap::id('m_middle', 3));
});

it('TC-32-06: 銘柄が 0 でミドルのある行は、ミドルのノードの行にする', function () {
    insertLegacyAffiliate(3, 1, 0);

    importLegacyAffiliates($this)->assertExitCode(0)->run();

    expect(Affiliate::query()->whereNull('brand_id')->where('category_id', LegacyNodeMap::id('m_middle', 1))->count())->toBe(1);
});

it('TC-32-07: 値の列と手で入れた列(human_*)、作成・更新日時を旧からそのまま移す', function () {
    updateLegacyAffiliate(2, 3, 2, [
        'title' => 'Amazon の商品名', 'price' => 3200,
        'img_large_url' => 'https://images.example/L.jpg', 'img_medium_url' => 'https://images.example/M.jpg',
        'img_small_url' => 'https://images.example/S.jpg',
        'amazon_detail_url' => 'https://www.amazon.example/dp/'.LEGACY_ASIN.'?tag=sample-22',
        'amazon_search_url' => 'https://www.amazon.example/s?k=sample',
        'rakuten_title' => '楽天の商品名', 'rakuten_price' => 3000, 'rakuten_image_url' => 'https://images.example/R.jpg',
        'rakuten_detail_url' => rakutenAffiliateUrl('https://item.rakuten.co.jp/sample-shop/sample-item/'),
        'rakuten_search_url' => 'https://search.example/sample',
        'human_title' => '手で入れた商品名', 'human_price' => 2800, 'human_image_url' => 'https://images.example/H.jpg',
        'human_amazon_detail_url' => 'https://www.amazon.example/dp/B0HUMAN001',
        'human_amazon_search_url' => 'https://www.amazon.example/s?k=human',
        'created_at' => '2019-01-02 03:04:05', 'updated_at' => '2021-02-03 04:05:06',
    ]);

    importLegacyAffiliates($this)->assertExitCode(0)->run();

    expect((array) DB::table('affiliates')->where('brand_id', 2)->sole())->toMatchArray([
        'amazon_title' => 'Amazon の商品名', 'amazon_price' => 3200,
        'amazon_image_large_url' => 'https://images.example/L.jpg', 'amazon_image_medium_url' => 'https://images.example/M.jpg',
        'amazon_image_small_url' => 'https://images.example/S.jpg',
        'amazon_detail_url' => 'https://www.amazon.example/dp/'.LEGACY_ASIN.'?tag=sample-22',
        'amazon_search_url' => 'https://www.amazon.example/s?k=sample',
        'rakuten_title' => '楽天の商品名', 'rakuten_price' => 3000, 'rakuten_image_url' => 'https://images.example/R.jpg',
        'rakuten_detail_url' => rakutenAffiliateUrl('https://item.rakuten.co.jp/sample-shop/sample-item/'),
        'rakuten_search_url' => 'https://search.example/sample',
        'human_title' => '手で入れた商品名', 'human_price' => 2800, 'human_image_url' => 'https://images.example/H.jpg',
        'human_amazon_detail_url' => 'https://www.amazon.example/dp/B0HUMAN001',
        'human_amazon_search_url' => 'https://www.amazon.example/s?k=human',
        'last_viewed_at' => null,
        'created_at' => '2019-01-02 03:04:05', 'updated_at' => '2021-02-03 04:05:06',
    ]);
});

it('TC-32-08: Amazon の商品のページの URL から ASIN を取り出す(:dataset)', function (?string $url, ?string $expected) {
    updateLegacyAffiliate(2, 3, 2, ['amazon_detail_url' => $url]);

    importLegacyAffiliates($this)->assertExitCode(0)->run();

    expect(Affiliate::query()->where('brand_id', 2)->sole()->amazon_asin)->toBe($expected);
})->with([
    'クエリの付いた /dp/<ASIN>' => ['https://www.amazon.co.jp/dp/'.LEGACY_ASIN.'?tag=sample-22&linkCode=ogi', LEGACY_ASIN],
    '/dp/<ASIN>/ の後にパスが続く' => ['https://www.amazon.co.jp/sample-item/dp/'.LEGACY_ASIN.'/ref=sr_1_1', LEGACY_ASIN],
    'URL が /dp/<ASIN> で終わる' => ['https://www.amazon.co.jp/dp/'.LEGACY_ASIN, LEGACY_ASIN],
    '/dp/ の後が 9 桁' => ['https://www.amazon.co.jp/dp/B0SAMPLE0?tag=sample-22', null],
    '/dp/ の後が 11 桁' => ['https://www.amazon.co.jp/dp/B0SAMPLE012?tag=sample-22', null],
    '/dp/ が無い' => ['https://www.amazon.co.jp/s?k=sample', null],
    'URL が null' => [null, null],
]);

it('TC-32-09: 楽天の商品のページの URL から itemCode を取り出す(:dataset)', function (?string $url, ?string $expected) {
    updateLegacyAffiliate(2, 3, 2, ['rakuten_detail_url' => $url]);

    importLegacyAffiliates($this)->assertExitCode(0)->run();

    expect(Affiliate::query()->where('brand_id', 2)->sole()->rakuten_item_code)->toBe($expected);
})->with([
    '転送の URL の pc= に商品のページ' => [rakutenAffiliateUrl('https://item.rakuten.co.jp/sample-shop/sample-item/'), 'sample-shop:sample-item'],
    'pc= の商品のページの末尾に / が無い' => [rakutenAffiliateUrl('https://item.rakuten.co.jp/sample-shop/10000123'), 'sample-shop:10000123'],
    '商品のページの URL そのもの' => ['https://item.rakuten.co.jp/sample-shop/sample-item/', 'sample-shop:sample-item'],
    'pc= が検索結果のページ' => [rakutenAffiliateUrl('https://search.rakuten.co.jp/search/mall/sample/'), null],
    'pc= の商品のページのパスが 3 段' => [rakutenAffiliateUrl('https://item.rakuten.co.jp/sample-shop/sample-item/extra/'), null],
    '転送の URL に pc= が無い' => ['https://hb.afl.rakuten.co.jp/hgc/sample-affiliate-id/', null],
    'URL が null' => [null, null],
]);

it('TC-32-10: 取った時刻は、値のある提供元だけ旧の updated_at にする(:dataset)', function (?string $amazonColumn, ?string $rakutenColumn, ?string $amazon, ?string $rakuten) {
    // 手で入れた値はどの場合も入れる(提供元の値ではないので、取った時刻に関わらない)
    $values = ['human_title' => '手で入れた商品名', 'updated_at' => '2025-11-08 03:56:03'];
    foreach ([$amazonColumn, $rakutenColumn] as $column) {
        if ($column !== null) {
            $values[$column] = 'https://example.com/sample';
        }
    }
    updateLegacyAffiliate(2, 3, 2, $values);

    importLegacyAffiliates($this)->assertExitCode(0)->run();

    $row = DB::table('affiliates')->where('brand_id', 2)->sole();
    expect($row->amazon_fetched_at)->toBe($amazon)
        ->and($row->rakuten_fetched_at)->toBe($rakuten);
})->with([
    'Amazon だけ(商品名)' => ['title', null, '2025-11-08 03:56:03', null],
    'Amazon だけ(検索結果のリンク)' => ['amazon_search_url', null, '2025-11-08 03:56:03', null],
    '楽天だけ(画像)' => [null, 'rakuten_image_url', null, '2025-11-08 03:56:03'],
    '両方' => ['img_small_url', 'rakuten_search_url', '2025-11-08 03:56:03', '2025-11-08 03:56:03'],
    '手で入れた値だけ' => [null, null, null, null],
]);

it('TC-32-11: 新しいキー(ノード・銘柄)で同じになる旧の行があると、例外で止まり、何も入れない', function () {
    // 銘柄 1 の行が、旧のキーを変えて 2 行。新しいキーではどちらも (銘柄 1 の所属ノード, 銘柄 1) になる
    insertLegacyAffiliate(2, 0, 1);

    expect(fn () => importLegacyAffiliates($this)->run())->toThrow(UnexpectedValueException::class);

    expect(DB::table('affiliates')->count())->toBe(0);
});

it('TC-32-12: 2 回流しても、affiliates が ID まで 1 回目と同じ', function () {
    importLegacyAffiliates($this)->assertExitCode(0)->run();
    $first = importedAffiliateRows();

    importLegacyAffiliates($this)->assertExitCode(0)->run();

    expect(importedAffiliateRows())->toBe($first);
});

it('TC-32-13: 2 回目の前に旧が変わっていたら、ID を変えずに書き直し、旧から消えた行を消す', function () {
    importLegacyAffiliates($this)->assertExitCode(0)->run();
    $brand1Id = Affiliate::query()->where('brand_id', 1)->sole()->id;
    $legacy = DB::connection('legacy');
    // 銘柄 1 の所属をミドル 3 に変え、銘柄 1 の行の値を変え、カテゴリ 4 の行を消す
    $legacy->table('m_brand')->where('id', 1)->update(['middle_category' => 3]);
    updateLegacyAffiliate(1, 0, 1, ['human_title' => '手で入れた商品名(変更)']);
    $legacy->table('affiliates')->where(['category_id' => 4, 'middle_id' => 0, 'brand_id' => 0])->delete();

    importLegacyAffiliates($this)->assertExitCode(0)->run();

    $affiliate = Affiliate::query()->where('brand_id', 1)->sole();
    expect($affiliate->id)->toBe($brand1Id)
        ->and($affiliate->category_id)->toBe(LegacyNodeMap::id('m_middle', 3))
        ->and($affiliate->human_title)->toBe('手で入れた商品名(変更)')
        ->and(Affiliate::query()->whereNull('brand_id')->count())->toBe(0)
        ->and(DB::table('affiliates')->count())->toBe(2);
});

it('TC-32-14: 先に移す部分が無い・指す先が旧に無いと、例外で止まり、何も入れない(:dataset)', function (bool $importCategories, bool $importBrands, ?int $extraCategory, int $extraMiddle, int $extraBrand) {
    if ($extraCategory !== null) {
        insertLegacyAffiliate($extraCategory, $extraMiddle, $extraBrand);
    }
    if ($importCategories) {
        LegacyImportCommand::run($this, ['--only' => 'categories'])->assertExitCode(0)->run();
    }
    if ($importBrands) {
        LegacyImportCommand::run($this, ['--only' => 'brands'])->assertExitCode(0)->run();
    }

    expect(fn () => LegacyImportCommand::run($this, ['--only' => 'affiliates'])->run())->toThrow(UnexpectedValueException::class);

    expect(DB::table('affiliates')->count())->toBe(0);
})->with([
    'カテゴリ木を移していない' => [false, false, null, 0, 0],
    '銘柄を移していない' => [true, false, null, 0, 0],
    '銘柄が旧に無い' => [true, true, 1, 0, 99],
    'ミドルが旧に無い' => [true, true, 3, 99, 0],
    'カテゴリが旧に無い' => [true, true, 99, 0, 0],
]);

it('TC-32-15: --only を付けないと、銘柄の後にアフィリエイトも移す', function () {
    LegacyImportCommand::run($this, [])->expectsOutput('affiliates:')->expectsOutput('affiliates: 3')->assertExitCode(0)->run();

    expect(DB::table('affiliates')->count())->toBe(3);
});

it('TC-32-16: 移した旧の価格は期限切れなので、手で入れた値のほかは商品の欄に出さない(#247)', function () {
    updateLegacyAffiliate(4, 0, 0, [
        'title' => 'Amazon の商品名', 'price' => 3200,
        'amazon_detail_url' => 'https://www.amazon.co.jp/dp/'.LEGACY_ASIN,
        'rakuten_title' => '楽天の商品名', 'rakuten_price' => 3000,
        'human_title' => '手で入れた商品名', 'human_price' => 2800,
        'updated_at' => '2025-11-08 03:56:03',
    ]);
    importLegacyAffiliates($this)->assertExitCode(0)->run();
    $this->travelTo(Carbon::parse('2026-10-29 12:00:00'));

    $this->getJson('/api/affiliates?category_id='.LegacyNodeMap::id('m_category', 4))
        ->assertOk()
        ->assertJsonPath('data.amazon.price', null)
        ->assertJsonPath('data.rakuten.price', null)
        ->assertJsonPath('data.human.price', 2800)
        ->assertJsonPath('data.display.source', 'human');
});
