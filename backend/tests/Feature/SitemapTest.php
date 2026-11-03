<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

// sitemap.xml・分けたサイトマップ・robots.txt(#114)。決めたことと旧との差分は docs/decisions/sitemap.md。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

const SITEMAP_APP_URL = 'https://cocktail.example.test';

beforeEach(function () {
    config(['app.url' => SITEMAP_APP_URL]);
});

/**
 * 親を持たないノード(根)を作る。
 *
 * @param  array<string, mixed>  $attributes
 */
function sitemapNode(array $attributes = []): Category
{
    return app(CategoryTree::class)->addNode(null, Category::factory()->make($attributes)->getAttributes());
}

/**
 * ノード $category にぶら下がる銘柄を $count 件、まとめて入れる(境界値のテストで 5 万件を入れるため、モデルを通さない)。
 */
function sitemapBulkBrands(Category $category, int $count): void
{
    $now = now()->format('Y-m-d H:i:s');
    for ($start = 0; $start < $count; $start += 2000) {
        $rows = [];
        for ($i = $start; $i < min($count, $start + 2000); $i++) {
            $rows[] = [
                'category_id' => $category->id,
                'name' => "銘柄{$i}",
                'name_search' => "銘柄{$i}",
                'name_eng' => "brand{$i}",
                'volume' => 40,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('brands')->insert($rows);
    }
}

/**
 * 応答の XML から、要素 $element(url・sitemap)の loc を順に取り出す。
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function sitemapLocs(TestResponse $response, string $element = 'url'): array
{
    preg_match_all('#<'.$element.'><loc>([^<]*)</loc>#', (string) $response->getContent(), $matches);

    return $matches[1];
}

/**
 * @param  TestResponse<Response>  $response
 */
function sitemapRoot(TestResponse $response): string
{
    $xml = simplexml_load_string((string) $response->getContent());
    assert($xml instanceof SimpleXMLElement);

    return $xml->getName();
}

it('TC-114-01: sitemap.xml に、カクテル・カテゴリ・銘柄・用語の詳細ページの URL が載る', function () {
    $node = sitemapNode();
    $cocktail = Cocktail::factory()->create(['base_category_id' => $node->id]);
    $brand = Brand::factory()->create(['category_id' => $node->id]);
    $dictionary = Dictionary::factory()->create();

    $response = $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    expect(sitemapRoot($response))->toBe('urlset')
        ->and(sitemapLocs($response))->toBe([
            SITEMAP_APP_URL.'/detail/cocktail/'.$cocktail->id,
            SITEMAP_APP_URL.'/category/'.$node->id,
            SITEMAP_APP_URL.'/detail/brand/'.$brand->id,
            SITEMAP_APP_URL.'/dictionary/'.$dictionary->id,
        ]);
});

it('TC-114-02: 下書きのカクテルは載らない', function () {
    $node = sitemapNode();
    $published = Cocktail::factory()->create(['base_category_id' => $node->id]);
    $draft = Cocktail::factory()->create(['base_category_id' => $node->id, 'is_draft' => true]);

    $locs = sitemapLocs($this->get('/sitemap.xml')->assertOk());

    expect($locs)->toContain(SITEMAP_APP_URL.'/detail/cocktail/'.$published->id)
        ->not->toContain(SITEMAP_APP_URL.'/detail/cocktail/'.$draft->id);
});

it('TC-114-03: 種類ごとに ID の昇順で並ぶ(カクテル → カテゴリ → 銘柄 → 用語)', function () {
    $nodeB = sitemapNode();
    $nodeA = sitemapNode();
    $c2 = Cocktail::factory()->create(['id' => 20, 'base_category_id' => $nodeA->id]);
    $c1 = Cocktail::factory()->create(['id' => 10, 'base_category_id' => $nodeA->id]);
    $b2 = Brand::factory()->create(['id' => 7, 'category_id' => $nodeA->id]);
    $b1 = Brand::factory()->create(['id' => 3, 'category_id' => $nodeB->id]);

    expect(sitemapLocs($this->get('/sitemap.xml')))->toBe([
        SITEMAP_APP_URL.'/detail/cocktail/'.$c1->id,
        SITEMAP_APP_URL.'/detail/cocktail/'.$c2->id,
        SITEMAP_APP_URL.'/category/'.$nodeB->id,
        SITEMAP_APP_URL.'/category/'.$nodeA->id,
        SITEMAP_APP_URL.'/detail/brand/'.$b1->id,
        SITEMAP_APP_URL.'/detail/brand/'.$b2->id,
    ]);
});

it('TC-114-04: lastmod は更新日時(W3C の日時の形)で、更新日時が無い行には付けない', function () {
    $node = sitemapNode();
    DB::table('categories')->where('id', $node->id)->update(['updated_at' => '2026-10-01 12:34:56']);
    $dictionary = Dictionary::factory()->create();
    DB::table('dictionaries')->where('id', $dictionary->id)->update(['updated_at' => null]);

    $xml = (string) $this->get('/sitemap.xml')->getContent();

    expect($xml)->toContain('<url><loc>'.SITEMAP_APP_URL.'/category/'.$node->id.'</loc><lastmod>2026-10-01T12:34:56+00:00</lastmod></url>')
        ->toContain('<url><loc>'.SITEMAP_APP_URL.'/dictionary/'.$dictionary->id.'</loc></url>');
});

it('TC-114-05: 載せるものが 1 件も無くても、空の urlset を 200 で返す', function () {
    $response = $this->get('/sitemap.xml')->assertOk();

    expect(sitemapRoot($response))->toBe('urlset')
        ->and(sitemapLocs($response))->toBe([]);
});

it('TC-114-06: ちょうど 50,000 件なら分けず、sitemap.xml に全部載り、/sitemap-1.xml は 404', function () {
    $node = sitemapNode();
    sitemapBulkBrands($node, 49999);

    $response = $this->get('/sitemap.xml')->assertOk();

    expect(sitemapRoot($response))->toBe('urlset')
        ->and(sitemapLocs($response))->toHaveCount(50000);
    $this->get('/sitemap-1.xml')->assertNotFound();
});

it('TC-114-07: 50,001 件なら 50,000 件ずつに分け、sitemap.xml はインデックスになる(種類の境目をまたいで続く)', function () {
    $node = sitemapNode();
    $cocktail = Cocktail::factory()->create(['base_category_id' => $node->id]);
    sitemapBulkBrands($node, 49998);
    $lastBrandId = Brand::query()->orderByDesc('id')->firstOrFail()->id;
    $dictionary = Dictionary::factory()->create();

    $index = $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    expect(sitemapRoot($index))->toBe('sitemapindex')
        ->and(sitemapLocs($index, 'sitemap'))->toBe([
            SITEMAP_APP_URL.'/sitemap-1.xml',
            SITEMAP_APP_URL.'/sitemap-2.xml',
        ]);

    $first = sitemapLocs($this->get('/sitemap-1.xml')->assertOk());
    expect($first)->toHaveCount(50000)
        ->and($first[0])->toBe(SITEMAP_APP_URL.'/detail/cocktail/'.$cocktail->id)
        ->and($first[49999])->toBe(SITEMAP_APP_URL.'/detail/brand/'.$lastBrandId);

    expect(sitemapLocs($this->get('/sitemap-2.xml')->assertOk()))->toBe([
        SITEMAP_APP_URL.'/dictionary/'.$dictionary->id,
    ]);
    $this->get('/sitemap-3.xml')->assertNotFound();
});

it('TC-114-08: 下書きのカクテルは 50,000 件に数えない(載せる分がちょうど 50,000 件なら分けない)', function () {
    $node = sitemapNode();
    sitemapBulkBrands($node, 49999);
    Cocktail::factory()->create(['base_category_id' => $node->id, 'is_draft' => true]);

    $response = $this->get('/sitemap.xml')->assertOk();

    expect(sitemapRoot($response))->toBe('urlset')
        ->and(sitemapLocs($response))->toHaveCount(50000);
});

it('TC-114-09: robots.txt は、どのページもクロールしてよいとし、sitemap.xml の場所を APP_URL で書く', function (string $appUrl) {
    config(['app.url' => $appUrl]);

    $response = $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect($response->getContent())->toBe("User-agent: *\nDisallow:\n\nSitemap: ".SITEMAP_APP_URL."/sitemap.xml\n");
})->with([
    '末尾のスラッシュ無し' => SITEMAP_APP_URL,
    '末尾のスラッシュ有り' => SITEMAP_APP_URL.'/',
]);

it('TC-114-10: 番号の形でない分けたサイトマップの URL は、SPA の HTML を返す(サイトマップとして扱わない)', function (string $path) {
    $this->withoutVite();

    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    '0 番' => '/sitemap-0.xml',
    '頭に 0' => '/sitemap-01.xml',
    '数字でない' => '/sitemap-a.xml',
]);
