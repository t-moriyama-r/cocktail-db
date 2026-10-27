<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Services\CategoryTree;
use App\Support\DescriptionParser;
use Illuminate\Foundation\Testing\RefreshDatabase;

// DescriptionParser(#286)が、説明文の参照の記法(`@n<ID>@` など。#285)を、文字の区切りとリンクの区切りの並びに
// するかを、MariaDB で確かめる。期待値は旧の Markup::generateDiscriptionArray(旧ソース)と、
// docs/decisions/description-parts.md の旧との差分から置く。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

function descriptionParserNode(string $name, ?string $youtube = null): Category
{
    return app(CategoryTree::class)->addNode(
        null,
        Category::factory()->make(['name' => $name, 'youtube' => $youtube])->getAttributes(),
    );
}

beforeEach(function () {
    $this->parser = app(DescriptionParser::class);
});

it('TC-286-01: 文字だけの説明文は、文字の区切り 1 つになる', function () {
    expect($this->parser->parse('ラムを使う。'))->toBe([
        ['type' => 'text', 'text' => 'ラムを使う。'],
    ]);
});

it('TC-286-02: 参照だけの説明文は、リンクの区切りだけになり、種類・ID・リンク先の名前を返す', function () {
    $node = descriptionParserNode('ラム');
    $cocktail = Cocktail::factory()->create(['name' => 'モヒート']);
    $brand = Brand::factory()->create(['name' => 'バカルディ']);
    $term = Dictionary::factory()->create(['name' => 'シェーカー']);

    expect($this->parser->parse("@n{$node->id}@@c{$cocktail->id}@@b{$brand->id}@@d{$term->id}@"))->toBe([
        ['type' => 'node', 'id' => $node->id, 'text' => 'ラム', 'has_youtube' => false],
        ['type' => 'cocktail', 'id' => $cocktail->id, 'text' => 'モヒート', 'has_youtube' => false],
        ['type' => 'brand', 'id' => $brand->id, 'text' => 'バカルディ', 'has_youtube' => false],
        ['type' => 'dictionary', 'id' => $term->id, 'text' => 'シェーカー', 'has_youtube' => false],
    ]);
});

it('TC-286-03: 参照と文字が混ざった説明文は、書いた順の並びになる', function () {
    $node = descriptionParserNode('ラム');
    $cocktail = Cocktail::factory()->create(['name' => 'モヒート']);

    expect($this->parser->parse("@n{$node->id}@ベースの@c{$cocktail->id}@は、ミントを使う。"))->toBe([
        ['type' => 'node', 'id' => $node->id, 'text' => 'ラム', 'has_youtube' => false],
        ['type' => 'text', 'text' => 'ベースの'],
        ['type' => 'cocktail', 'id' => $cocktail->id, 'text' => 'モヒート', 'has_youtube' => false],
        ['type' => 'text', 'text' => 'は、ミントを使う。'],
    ]);
});

it('TC-286-04: 改行は HTML(<br />)にせず、文字の改行のまま返す。HTML の文字もそのまま返す', function () {
    $node = descriptionParserNode('ラム');

    expect($this->parser->parse("1 行目\n@n{$node->id}@の<b>2 行目</b>\r\n3 行目"))->toBe([
        ['type' => 'text', 'text' => "1 行目\n"],
        ['type' => 'node', 'id' => $node->id, 'text' => 'ラム', 'has_youtube' => false],
        ['type' => 'text', 'text' => "の<b>2 行目</b>\r\n3 行目"],
    ]);
});

it('TC-286-05: 指す先の見つからない参照は記法を取り除き、前後の文字を 1 つの区切りにつなげる', function () {
    $node = descriptionParserNode('ラム');

    expect($this->parser->parse("前@n999999@と@c999999@と@b999999@と@d999999@後@n{$node->id}@"))->toBe([
        ['type' => 'text', 'text' => '前ととと後'],
        ['type' => 'node', 'id' => $node->id, 'text' => 'ラム', 'has_youtube' => false],
    ]);
});

it('TC-286-06: 下書きのカクテルの参照は、名前だけの文字の区切りにして、前後の文字とつなげる', function () {
    $draft = Cocktail::factory()->create(['name' => 'モヒート', 'is_draft' => true]);

    expect($this->parser->parse("これは@c{$draft->id}@の仲間。"))->toBe([
        ['type' => 'text', 'text' => 'これはモヒートの仲間。'],
    ]);
});

it('TC-286-07: リンク先に動画があれば has_youtube が true(空の文字は動画なし)', function () {
    $withVideo = descriptionParserNode('ラム', 'abcdefghijk');
    $emptyVideo = descriptionParserNode('ジン', '');
    $cocktail = Cocktail::factory()->create(['name' => 'モヒート', 'youtube' => 'abcdefghijk']);
    $brand = Brand::factory()->create(['name' => 'バカルディ', 'youtube' => 'abcdefghijk']);
    $term = Dictionary::factory()->create(['name' => 'シェーカー', 'youtube' => 'abcdefghijk']);

    expect($this->parser->parse("@n{$withVideo->id}@@n{$emptyVideo->id}@@c{$cocktail->id}@@b{$brand->id}@@d{$term->id}@"))
        ->toBe([
            ['type' => 'node', 'id' => $withVideo->id, 'text' => 'ラム', 'has_youtube' => true],
            ['type' => 'node', 'id' => $emptyVideo->id, 'text' => 'ジン', 'has_youtube' => false],
            ['type' => 'cocktail', 'id' => $cocktail->id, 'text' => 'モヒート', 'has_youtube' => true],
            ['type' => 'brand', 'id' => $brand->id, 'text' => 'バカルディ', 'has_youtube' => true],
            ['type' => 'dictionary', 'id' => $term->id, 'text' => 'シェーカー', 'has_youtube' => true],
        ]);
});

it('TC-286-08: 大文字の記法(@N・@C・@B・@D)も、小文字と同じリンクの区切りになる', function () {
    $node = descriptionParserNode('ラム');
    $cocktail = Cocktail::factory()->create(['name' => 'モヒート']);
    $brand = Brand::factory()->create(['name' => 'バカルディ']);
    $term = Dictionary::factory()->create(['name' => 'シェーカー']);

    expect($this->parser->parse("@N{$node->id}@@C{$cocktail->id}@@B{$brand->id}@@D{$term->id}@"))->toBe([
        ['type' => 'node', 'id' => $node->id, 'text' => 'ラム', 'has_youtube' => false],
        ['type' => 'cocktail', 'id' => $cocktail->id, 'text' => 'モヒート', 'has_youtube' => false],
        ['type' => 'brand', 'id' => $brand->id, 'text' => 'バカルディ', 'has_youtube' => false],
        ['type' => 'dictionary', 'id' => $term->id, 'text' => 'シェーカー', 'has_youtube' => false],
    ]);
});

it('TC-286-09: null と空の説明文は null', function () {
    expect($this->parser->parse(null))->toBeNull()
        ->and($this->parser->parse(''))->toBeNull();
});

it('TC-286-10: 記法の形でない @(メールアドレス・ほかの英字・閉じていない記法・0 から始まる ID)は、文字のまま残す', function () {
    $node = descriptionParserNode('ラム');
    $text = "a@example.com と @p{$node->id}@ と @m{$node->id}@ と @n0@ と 前@n{$node->id}";

    expect($this->parser->parse($text))->toBe([
        ['type' => 'text', 'text' => $text],
    ]);
});

it('TC-286-11: 桁の多すぎる ID の参照は、見つからない参照と同じく取り除く(500 にしない)', function () {
    expect($this->parser->parse('前@n99999999999999999999@後'))->toBe([
        ['type' => 'text', 'text' => '前後'],
    ]);
});
