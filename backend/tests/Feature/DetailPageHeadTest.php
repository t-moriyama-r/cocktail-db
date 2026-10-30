<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

// 詳細ページの SPA の HTML に入れる title・description・canonical と、無い ID・下書きの 404(#111)。
// 決めたことと旧との差分は #111 の本文(旧との差分(意図: #111))。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

const DETAIL_HEAD_SITE_TITLE = '宅飲みカクテルデータベース(RC)';
const DETAIL_HEAD_SITE_DESCRIPTION = '読んで楽しい、を目指したデータベース。';

beforeEach(function () {
    $this->withoutVite();
    config(['app.url' => 'https://cocktail.example.test']);
});

/**
 * 親を持たないノード(根)を作る。
 *
 * @param  array<string, mixed>  $attributes
 */
function detailHeadNode(array $attributes = []): Category
{
    return app(CategoryTree::class)->addNode(null, Category::factory()->make($attributes)->getAttributes());
}

/**
 * 応答の HTML から、`<meta name="description">` の content を取り出す(HTML の実体参照は戻す)。無ければ null。
 */
/**
 * @param  TestResponse<Response>  $response
 */
function detailHeadDescription(TestResponse $response): ?string
{
    if (preg_match('/<meta name="description" content="([^"]*)">/u', (string) $response->getContent(), $m) !== 1) {
        return null;
    }

    return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * 種類 $kind(cocktail・category・brand・dictionary)の行を作り、その詳細ページのパスを返す。
 *
 * @param  array<string, mixed>  $attributes
 */
function detailHeadPath(string $kind, array $attributes = []): string
{
    return match ($kind) {
        'cocktail' => '/detail/cocktail/'.Cocktail::factory()->create($attributes)->id,
        'category' => '/category/'.detailHeadNode($attributes)->id,
        'brand' => '/detail/brand/'.Brand::factory()->create($attributes)->id,
        default => '/dictionary/'.Dictionary::factory()->create($attributes)->id,
    };
}

dataset('detail kinds', [
    'カクテル' => 'cocktail',
    'カテゴリ' => 'category',
    '銘柄' => 'brand',
    '用語' => 'dictionary',
]);

it('TC-111-01: 詳細ページの HTML の title が「名前(英名) ｜ サイト名」になり、SPA の HTML を 200 で返す', function (string $kind) {
    $path = detailHeadPath($kind, ['name' => 'ジン・トニック', 'name_eng' => 'Gin and Tonic']);

    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false)
        ->assertSee('<title>ジン・トニック(Gin and Tonic) ｜ '.DETAIL_HEAD_SITE_TITLE.'</title>', false);
})->with('detail kinds');

it('TC-111-02: 英名が空の文字列なら、title に空の括弧を出さない', function (string $kind) {
    $path = detailHeadPath($kind, ['name' => 'ジン・トニック', 'name_eng' => '']);

    $this->get($path)
        ->assertOk()
        ->assertSee('<title>ジン・トニック ｜ '.DETAIL_HEAD_SITE_TITLE.'</title>', false)
        ->assertDontSee('()', false);
})->with('detail kinds');

it('TC-111-18: 英名が null のカクテル(英名の列が null を許すのはカクテルだけ)も、title に空の括弧を出さない', function () {
    $cocktail = Cocktail::factory()->create(['name' => 'ジン・トニック', 'name_eng' => null]);

    $this->get("/detail/cocktail/{$cocktail->id}")
        ->assertOk()
        ->assertSee('<title>ジン・トニック ｜ '.DETAIL_HEAD_SITE_TITLE.'</title>', false)
        ->assertDontSee('()', false);
});

it('TC-111-03: canonical は APP_URL とそのページのパスで、クエリを付けない', function (string $kind) {
    $path = detailHeadPath($kind);

    $this->get($path.'?utm_source=x&page=2')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="https://cocktail.example.test'.$path.'">', false);
})->with('detail kinds');

it('TC-111-04: description に説明文を入れる', function (string $kind) {
    $path = detailHeadPath($kind, ['description' => 'すっきりした一杯。']);

    expect(detailHeadDescription($this->get($path)->assertOk()))->toBe('すっきりした一杯。');
})->with('detail kinds');

it('TC-111-05: description の参照の記法は、指す先の名前にする(指す先が無い参照は除く)', function () {
    $gin = detailHeadNode(['name' => 'ジン']);
    $tonic = Cocktail::factory()->create(['name' => 'ジン・トニック']);
    $draft = Cocktail::factory()->create(['name' => '下書きのカクテル', 'is_draft' => true]);
    $brand = Brand::factory()->create(['name' => 'ビーフィーター']);
    $word = Dictionary::factory()->create(['name' => 'ビルド']);
    $cocktail = Cocktail::factory()->create([
        'description' => "@n{$gin->id}@を@b{$brand->id}@で。@D{$word->id}@で作る@c{$tonic->id}@の仲間。@c{$draft->id}@にも。@c999999@消えた。",
    ]);

    expect(detailHeadDescription($this->get("/detail/cocktail/{$cocktail->id}")->assertOk()))
        ->toBe('ジンをビーフィーターで。ビルドで作るジン・トニックの仲間。下書きのカクテルにも。消えた。');
});

it('TC-111-06: description からタグを除く', function () {
    $cocktail = Cocktail::factory()->create(['description' => '<b>定番</b>の<a href="https://example.test/">一杯</a>。<br>甘い']);

    expect(detailHeadDescription($this->get("/detail/cocktail/{$cocktail->id}")->assertOk()))->toBe('定番の一杯。甘い');
});

it('TC-111-07: description の改行は空白にし、前後の空白を除く', function (string $description, string $expected) {
    $cocktail = Cocktail::factory()->create(['description' => $description]);

    expect(detailHeadDescription($this->get("/detail/cocktail/{$cocktail->id}")->assertOk()))->toBe($expected);
})->with([
    'LF' => ["一行目\n二行目", '一行目 二行目'],
    'CRLF' => ["一行目\r\n二行目", '一行目 二行目'],
    'CR' => ["一行目\r二行目", '一行目 二行目'],
    '空行' => ["一行目\n\n二行目", '一行目  二行目'],
    '前後の改行' => ["\n一行目\n", '一行目'],
]);

it('TC-111-08: description は 120 字までそのまま、超えたら 119 字に「…」を足す', function (int $length, string $expected) {
    $cocktail = Cocktail::factory()->create(['description' => str_repeat('あ', $length)]);

    expect(detailHeadDescription($this->get("/detail/cocktail/{$cocktail->id}")->assertOk()))->toBe($expected);
})->with([
    '119 字' => [119, str_repeat('あ', 119)],
    '120 字' => [120, str_repeat('あ', 120)],
    '121 字' => [121, str_repeat('あ', 119).'…'],
    '300 字' => [300, str_repeat('あ', 119).'…'],
]);

it('TC-111-09: 字数は参照を名前にした後で数える(4 バイトの文字も 1 字)', function () {
    $gin = detailHeadNode(['name' => 'ジン']);
    // 記法(@n<ID>@)のままなら 120 字を超えるが、名前(2 字)にすると 120 字ちょうど
    $cocktail = Cocktail::factory()->create(['description' => "@n{$gin->id}@".str_repeat('🍸', 118)]);

    expect(detailHeadDescription($this->get("/detail/cocktail/{$cocktail->id}")->assertOk()))
        ->toBe('ジン'.str_repeat('🍸', 118));
});

it('TC-111-10: 説明文が空なら、description はサイト共通の文にする', function (?string $description) {
    $cocktail = Cocktail::factory()->create(['description' => $description]);

    expect(detailHeadDescription($this->get("/detail/cocktail/{$cocktail->id}")->assertOk()))->toBe(DETAIL_HEAD_SITE_DESCRIPTION);
})->with([
    'null' => [null],
    '空の文字列' => [''],
    '改行と空白だけ' => ["\n \r\n"],
    'タグだけ' => ['<br>'],
    '指す先の無い参照だけ' => ['@c999999@'],
]);

it('TC-111-11: 名前・説明文の記号は HTML の中でエスケープする', function () {
    $cocktail = Cocktail::factory()->create([
        'name' => 'A&B "<script>"',
        'name_eng' => 'x',
        'description' => 'a & b "c" \'d\'',
    ]);

    $response = $this->get("/detail/cocktail/{$cocktail->id}")->assertOk();

    $response->assertSee('<title>A&amp;B &quot;&lt;script&gt;&quot;(x) ｜ '.DETAIL_HEAD_SITE_TITLE.'</title>', false)
        ->assertDontSee('<script>"', false);
    expect(detailHeadDescription($response))->toBe('a & b "c" \'d\'');
});

it('TC-111-12: 無い ID の詳細は、SPA の HTML を 404 で返し、title・description・canonical を入れない', function (string $kind) {
    $missing = preg_replace_callback('/\d+$/', fn (array $m): string => (string) ((int) $m[0] + 1), detailHeadPath($kind));

    $this->get((string) $missing)
        ->assertNotFound()
        ->assertSee('<div id="root"></div>', false)
        ->assertSee('<title>宅飲みカクテルデータベース</title>', false)
        ->assertDontSee('name="description"', false)
        ->assertDontSee('rel="canonical"', false);
})->with('detail kinds');

it('TC-111-13: 下書きのカクテルの詳細は、SPA の HTML を 404 で返す', function () {
    $draft = Cocktail::factory()->create(['name' => '下書きのカクテル', 'is_draft' => true]);

    $this->get("/detail/cocktail/{$draft->id}")
        ->assertNotFound()
        ->assertSee('<div id="root"></div>', false)
        ->assertDontSee('下書きのカクテル', false);
});

it('TC-111-14: int に収まらない桁の ID も、500 にせず SPA の HTML を 404 で返す', function (string $prefix) {
    $this->get($prefix.'99999999999999999999999')
        ->assertNotFound()
        ->assertSee('<div id="root"></div>', false);
})->with([
    'カクテル' => '/detail/cocktail/',
    'カテゴリ' => '/category/',
    '銘柄' => '/detail/brand/',
    '用語' => '/dictionary/',
]);

it('TC-111-15: 0 で始まる ID は、その ID の詳細を返し、canonical は 0 を除いたパスにする', function () {
    $cocktail = Cocktail::factory()->create(['name' => 'ジン・トニック', 'name_eng' => 'Gin and Tonic']);

    $this->get('/detail/cocktail/00'.$cocktail->id)
        ->assertOk()
        ->assertSee('<title>ジン・トニック(Gin and Tonic) ｜ '.DETAIL_HEAD_SITE_TITLE.'</title>', false)
        ->assertSee('<link rel="canonical" href="https://cocktail.example.test/detail/cocktail/'.$cocktail->id.'">', false);
});
