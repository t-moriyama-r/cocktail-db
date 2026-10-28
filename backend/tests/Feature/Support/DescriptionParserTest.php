<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Services\CategoryTree;
use App\Support\DescriptionParser;
use Illuminate\Foundation\Testing\RefreshDatabase;

// DescriptionParser(#286)が、説明文を docs/decisions/description-segments.md のとおりの区切りの並びにするかを、MariaDB で確かめる。
// 期待値は、旧の Markup::generateDiscriptionArray(本番の laravel13)に同じ説明文を渡して出た結果と、同じ文書の旧との差分から置く。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * テストに使うノード(蒸留酒(根)── ラム)。ラムには動画がある。
 *
 * @return array{root: Category, rum: Category}
 */
function descriptionParserNodes(): array
{
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, Category::factory()->make(['name' => '蒸留酒'])->getAttributes());
    $rum = $tree->addNode($root, Category::factory()->make(['name' => 'ラム', 'youtube' => 'rumvideo01'])->getAttributes());

    return ['root' => $root, 'rum' => $rum];
}

beforeEach(function () {
    $this->parser = app(DescriptionParser::class);
});

it('TC-286-01: 参照の無い説明文は、文字の区切り 1 つになる', function () {
    expect($this->parser->parse('ただの文字'))->toBe([
        ['type' => 'text', 'text' => 'ただの文字'],
    ]);
});

it('TC-286-02: 参照だけの説明文は、リンクの区切り 1 つになる(前後に空の文字の区切りを置かない)', function () {
    $cocktail = Cocktail::factory()->create(['name' => 'カルーア・ミルク', 'youtube' => 'kahluavideo']);

    expect($this->parser->parse("@c{$cocktail->id}@"))->toBe([
        ['type' => 'cocktail', 'id' => $cocktail->id, 'text' => 'カルーア・ミルク', 'has_youtube' => true],
    ]);
});

it('TC-286-03: 参照と文字の混在を、説明文の順に並べる(4 種類・大文字の記法・同じ参照の繰り返し・動画の有無)', function () {
    $n = descriptionParserNodes();
    $gimlet = Cocktail::factory()->create(['name' => 'ギムレット', 'youtube' => null]);
    $brand = Brand::factory()->create(['category_id' => $n['rum']->id, 'name' => 'バカルディ', 'youtube' => '']);
    $term = Dictionary::factory()->create(['name' => 'シェイク', 'youtube' => 'shakevideo1']);

    $parsed = $this->parser->parse(
        "前@c{$gimlet->id}@中@N{$n['rum']->id}@後@b{$brand->id}@と@D{$term->id}@。@n{$n['root']->id}@と@C{$gimlet->id}@"
    );

    expect($parsed)->toBe([
        ['type' => 'text', 'text' => '前'],
        ['type' => 'cocktail', 'id' => $gimlet->id, 'text' => 'ギムレット', 'has_youtube' => false],
        ['type' => 'text', 'text' => '中'],
        ['type' => 'node', 'id' => $n['rum']->id, 'text' => 'ラム', 'has_youtube' => true],
        ['type' => 'text', 'text' => '後'],
        ['type' => 'brand', 'id' => $brand->id, 'text' => 'バカルディ', 'has_youtube' => false],
        ['type' => 'text', 'text' => 'と'],
        ['type' => 'dictionary', 'id' => $term->id, 'text' => 'シェイク', 'has_youtube' => true],
        ['type' => 'text', 'text' => '。'],
        ['type' => 'node', 'id' => $n['root']->id, 'text' => '蒸留酒', 'has_youtube' => false],
        ['type' => 'text', 'text' => 'と'],
        ['type' => 'cocktail', 'id' => $gimlet->id, 'text' => 'ギムレット', 'has_youtube' => false],
    ]);
});

it('TC-286-04: 改行は HTML(<br />)にせず、文字の改行のまま返す', function () {
    $cocktail = Cocktail::factory()->create(['name' => 'ギムレット']);

    expect($this->parser->parse("1行目\n2行目@c{$cocktail->id}@\r\n3行目"))->toBe([
        ['type' => 'text', 'text' => "1行目\n2行目"],
        ['type' => 'cocktail', 'id' => $cocktail->id, 'text' => 'ギムレット', 'has_youtube' => false],
        ['type' => 'text', 'text' => "\r\n3行目"],
    ]);
});

it('TC-286-05: 指す先の無い参照は取り除き、前後の文字を 1 つの区切りにつなげる', function () {
    $n = descriptionParserNodes();
    $missing = Cocktail::factory()->create()->id;
    Cocktail::query()->whereKey($missing)->delete();

    expect($this->parser->parse("前@c{$missing}@後@n999999@。@b999999@@d999999@"))->toBe([
        ['type' => 'text', 'text' => '前後。'],
    ])->and($this->parser->parse("@c{$missing}@"))->toBe([]);
});

it('TC-286-06: 下書きのカクテルを指す参照は、名前だけの文字の区切りにして前後とつなげる', function () {
    $draft = Cocktail::factory()->create(['name' => '下書きのカクテル', 'is_draft' => true, 'youtube' => 'draftvideo1']);

    expect($this->parser->parse("前@c{$draft->id}@後"))->toBe([
        ['type' => 'text', 'text' => '前下書きのカクテル後'],
    ]);
});

it('TC-286-07: null と空の文字列は null を返す', function () {
    expect($this->parser->parse(null))->toBeNull()
        ->and($this->parser->parse(''))->toBeNull();
});

it('TC-286-08: 記法の形でない @(メールアドレス・種類の無い英字・桁の多すぎる ID)は、書いたままの文字にする', function () {
    expect($this->parser->parse('メールは a@b.jp です。@x1@と@p1@と@c1234567890123456789@'))->toBe([
        ['type' => 'text', 'text' => 'メールは a@b.jp です。@x1@と@p1@と@c1234567890123456789@'],
    ]);
});

it('TC-286-09: 文字の区切りの HTML は、手を加えずに文字のまま返す', function () {
    expect($this->parser->parse('<b>太字</b>&amp;'))->toBe([
        ['type' => 'text', 'text' => '<b>太字</b>&amp;'],
    ]);
});
