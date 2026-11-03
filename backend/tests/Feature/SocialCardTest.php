<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

// SPA の HTML に入れる OGP・Twitter カード(#112)。
// 決めたことと旧との差分は docs/decisions/ogp.md。title・description・canonical は DetailPageHeadTest(#111)。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

const SOCIAL_SITE_NAME = '宅飲みカクテルデータベース';
const SOCIAL_SITE_DESCRIPTION = '読んで楽しい、を目指したデータベース。';
const SOCIAL_APP_URL = 'https://cocktail.example.test';
const SOCIAL_DEFAULT_IMAGE = SOCIAL_APP_URL.'/og-default.png';

beforeEach(function () {
    $this->withoutVite();
    config(['app.url' => SOCIAL_APP_URL]);
});

/**
 * 応答の HTML から、OGP・Twitter カードの meta の content を、名前(`og:title`・`twitter:card` など)ごとに取り出す
 * (HTML の実体参照は戻す)。同じ名前が 2 つあれば、テストを落とす。
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, string>
 */
function socialMeta(TestResponse $response): array
{
    preg_match_all('/<meta (?:property|name)="((?:og|twitter):[a-z_:]+)" content="([^"]*)">/u', (string) $response->getContent(), $matches, PREG_SET_ORDER);
    $meta = [];
    foreach ($matches as [, $name, $content]) {
        expect($meta)->not->toHaveKey($name);
        $meta[$name] = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return $meta;
}

/**
 * 種類 $kind(cocktail・category・brand・dictionary)の行を作り、その詳細ページのパスを返す。
 *
 * @param  array<string, mixed>  $attributes
 */
function socialDetailPath(string $kind, array $attributes = []): string
{
    return match ($kind) {
        'cocktail' => '/detail/cocktail/'.Cocktail::factory()->create($attributes)->id,
        'category' => '/category/'.app(CategoryTree::class)->addNode(null, Category::factory()->make($attributes)->getAttributes())->id,
        'brand' => '/detail/brand/'.Brand::factory()->create($attributes)->id,
        default => '/dictionary/'.Dictionary::factory()->create($attributes)->id,
    };
}

/**
 * サイト共通の値(詳細でないページ・無い ID の 404)。
 *
 * @return array<string, string>
 */
function socialSiteMeta(): array
{
    return [
        'og:url' => SOCIAL_APP_URL.'/',
        'og:type' => 'website',
        'og:title' => SOCIAL_SITE_NAME,
        'og:description' => SOCIAL_SITE_DESCRIPTION,
        'og:image' => SOCIAL_DEFAULT_IMAGE,
        'twitter:card' => 'summary_large_image',
        'twitter:title' => SOCIAL_SITE_NAME,
        'twitter:description' => SOCIAL_SITE_DESCRIPTION,
        'twitter:image' => SOCIAL_DEFAULT_IMAGE,
    ];
}

dataset('social detail kinds', [
    'カクテル' => 'cocktail',
    'カテゴリ' => 'category',
    '銘柄' => 'brand',
    '用語' => 'dictionary',
]);

it('TC-112-01: 詳細ページの HTML に、ページごとの OGP と Twitter カード(summary)を入れる', function (string $kind) {
    $path = socialDetailPath($kind, [
        'name' => 'ジン・トニック',
        'name_eng' => 'Gin and Tonic',
        'description' => 'すっきりした一杯。',
        'image' => 'gin-tonic.jpg',
    ]);

    expect(socialMeta($this->get($path.'?utm_source=x')->assertOk()))->toBe([
        'og:url' => SOCIAL_APP_URL.$path,
        'og:type' => 'website',
        'og:title' => 'ジン・トニック | '.SOCIAL_SITE_NAME,
        'og:description' => 'すっきりした一杯。',
        'og:image' => SOCIAL_APP_URL.'/storage/gin-tonic.jpg',
        'twitter:card' => 'summary',
        'twitter:title' => 'ジン・トニック | '.SOCIAL_SITE_NAME,
        'twitter:description' => 'すっきりした一杯。',
        'twitter:image' => SOCIAL_APP_URL.'/storage/gin-tonic.jpg',
    ]);
})->with('social detail kinds');

it('TC-112-02: og:description・twitter:description は、description と同じ切り方(参照は名前に、タグを除き、改行は空白に)にする', function () {
    $brand = Brand::factory()->create(['name' => 'ビーフィーター']);
    $cocktail = Cocktail::factory()->create(['description' => "@b{$brand->id}@で作る<b>定番</b>。\n@c999999@甘い"]);

    $response = $this->get("/detail/cocktail/{$cocktail->id}")->assertOk();
    $meta = socialMeta($response);

    expect($meta['og:description'])->toBe('ビーフィーターで作る定番。 甘い')
        ->and($meta['twitter:description'])->toBe('ビーフィーターで作る定番。 甘い');
    $response->assertSee('<meta name="description" content="ビーフィーターで作る定番。 甘い">', false);
});

it('TC-112-03: og:description・twitter:description は 120 字までそのまま、超えたら 119 字に「…」を足す', function (int $length, string $expected) {
    $cocktail = Cocktail::factory()->create(['description' => str_repeat('あ', $length)]);

    $meta = socialMeta($this->get("/detail/cocktail/{$cocktail->id}")->assertOk());

    expect($meta['og:description'])->toBe($expected)
        ->and($meta['twitter:description'])->toBe($expected);
})->with([
    '119 字' => [119, str_repeat('あ', 119)],
    '120 字' => [120, str_repeat('あ', 120)],
    '121 字' => [121, str_repeat('あ', 119).'…'],
]);

it('TC-112-04: 説明文が空なら、og:description・twitter:description はサイト共通の文にする', function (?string $description) {
    $cocktail = Cocktail::factory()->create(['description' => $description]);

    $meta = socialMeta($this->get("/detail/cocktail/{$cocktail->id}")->assertOk());

    expect($meta['og:description'])->toBe(SOCIAL_SITE_DESCRIPTION)
        ->and($meta['twitter:description'])->toBe(SOCIAL_SITE_DESCRIPTION);
})->with([
    'null' => [null],
    '空の文字列' => [''],
]);

it('TC-112-05: 画像が無い詳細ページの og:image・twitter:image は、既定の画像にする', function (string $kind, ?string $image) {
    $meta = socialMeta($this->get(socialDetailPath($kind, ['image' => $image]))->assertOk());

    expect($meta['og:image'])->toBe(SOCIAL_DEFAULT_IMAGE)
        ->and($meta['twitter:image'])->toBe(SOCIAL_DEFAULT_IMAGE);
})->with('social detail kinds')->with([
    'null' => [null],
    '空の文字列' => [''],
    '空白だけ' => [' '],
]);

it('TC-112-06: 画像の列が外部の URL なら、og:image にそのまま入れる', function (string $image) {
    $meta = socialMeta($this->get(socialDetailPath('cocktail', ['image' => $image]))->assertOk());

    expect($meta['og:image'])->toBe($image)
        ->and($meta['twitter:image'])->toBe($image);
})->with([
    'https' => ['https://images.example.test/a.jpg'],
    'http' => ['http://images.example.test/a.jpg'],
    '大文字' => ['HTTPS://images.example.test/a.jpg'],
]);

it('TC-112-07: 画像のファイル名の空白・日本語は、URL の中でエンコードする', function () {
    $meta = socialMeta($this->get(socialDetailPath('cocktail', ['image' => 'ジン トニック.jpg']))->assertOk());

    expect($meta['og:image'])->toBe(SOCIAL_APP_URL.'/storage/'.rawurlencode('ジン').'%20'.rawurlencode('トニック').'.jpg');
});

it('TC-112-08: 名前・説明文・画像の記号は、HTML の中でエスケープする', function () {
    $cocktail = Cocktail::factory()->create([
        'name' => 'A&B "<script>"',
        'description' => 'a & b "c"',
        'image' => 'https://images.example.test/a.jpg?x=1&y="2"',
    ]);

    $response = $this->get("/detail/cocktail/{$cocktail->id}")->assertOk();

    $response->assertSee('<meta property="og:title" content="A&amp;B &quot;&lt;script&gt;&quot; | '.SOCIAL_SITE_NAME.'">', false)
        ->assertDontSee('<script>"', false);
    expect(socialMeta($response))
        ->toMatchArray([
            'og:description' => 'a & b "c"',
            'og:image' => 'https://images.example.test/a.jpg?x=1&y="2"',
        ]);
});

it('TC-112-09: 詳細でないページの HTML には、サイト共通の OGP と Twitter カード(summary_large_image)を入れる', function (string $path) {
    expect(socialMeta($this->get($path)->assertOk()))->toBe(socialSiteMeta());
})->with([
    'トップ' => '/',
    '用語辞典' => '/dictionary',
    '条件検索の結果' => '/result/Cocktails',
    '数字でない ID の詳細' => '/detail/cocktail/abc',
    '知らない URL' => '/nope',
]);

it('TC-112-10: 無い ID・下書きの詳細の 404 には、サイト共通の値を入れ、名前を出さない', function (string $kind) {
    $path = socialDetailPath($kind, ['name' => '消えた行']);
    $missing = (string) preg_replace_callback('/\d+$/', fn (array $m): string => (string) ((int) $m[0] + 1), $path);

    $response = $this->get($missing)->assertNotFound();

    expect(socialMeta($response))->toBe(socialSiteMeta());
    $response->assertDontSee('消えた行', false);
})->with('social detail kinds');

it('TC-112-11: 下書きのカクテルの詳細の 404 には、サイト共通の値を入れ、名前・画像を出さない', function () {
    $draft = Cocktail::factory()->create(['name' => '下書きのカクテル', 'is_draft' => true, 'image' => 'draft.jpg']);

    $response = $this->get("/detail/cocktail/{$draft->id}")->assertNotFound();

    expect(socialMeta($response))->toBe(socialSiteMeta());
    $response->assertDontSee('下書きのカクテル', false)->assertDontSee('draft.jpg', false);
});

it('TC-112-12: APP_URL の末尾に / があっても、og:url・og:image の URL に / を重ねない', function () {
    config(['app.url' => SOCIAL_APP_URL.'/']);
    $cocktail = Cocktail::factory()->create(['image' => null]);

    expect(socialMeta($this->get("/detail/cocktail/{$cocktail->id}")->assertOk()))
        ->toMatchArray([
            'og:url' => SOCIAL_APP_URL."/detail/cocktail/{$cocktail->id}",
            'og:image' => SOCIAL_DEFAULT_IMAGE,
        ])
        ->and(socialMeta($this->get('/')->assertOk()))
        ->toMatchArray([
            'og:url' => SOCIAL_APP_URL.'/',
            'og:image' => SOCIAL_DEFAULT_IMAGE,
        ]);
});

it('TC-112-13: 既定の画像は public に置いた 1200×630 の PNG', function () {
    $size = getimagesize(public_path('og-default.png'));

    expect($size)->not->toBeFalse()
        ->and([$size[0] ?? null, $size[1] ?? null, $size['mime'] ?? null])->toBe([1200, 630, 'image/png']);
});
