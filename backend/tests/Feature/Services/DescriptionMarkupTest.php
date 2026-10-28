<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Services\CategoryTree;
use App\Services\DescriptionMarkup;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

// DescriptionMarkup(#287)が、説明文の保存のとき(旧の mark_up)と編集で開くとき(旧の at_id_append_note)に、
// docs/decisions/description-markup.md のとおりに記法を変換するかを、MariaDB で確かめる。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * テストに使う木。
 *
 * 蒸留酒(根)
 * ├ ラム(1)
 * │  └ ホワイト(2)
 * └ ジン(1)
 *    └ ホワイト(2)   ← 同じ名前のノード(浅い方・ID の小さい方を選ぶかを見る)
 * その他(根。other)
 * ├ 砂糖(1)
 * └ 焼酎(1)
 *    └ 芋焼酎(2)
 *
 * @return array<string, Category>
 */
function descriptionMarkupTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, ?string $role = null): Category => $tree->addNode(
        $parent,
        Category::factory()->make([
            'name' => $name,
            'name_search' => NameNormalizer::normalize($name),
            'role' => $role,
        ])->getAttributes(),
    );

    $n = [];
    $n['蒸留酒'] = $add(null, '蒸留酒');
    $n['ラム'] = $add($n['蒸留酒'], 'ラム');
    $n['ラム/ホワイト'] = $add($n['ラム'], 'ホワイト');
    $n['ジン'] = $add($n['蒸留酒'], 'ジン');
    $n['ジン/ホワイト'] = $add($n['ジン'], 'ホワイト');
    $n['その他'] = $add(null, 'その他', 'other');
    $n['砂糖'] = $add($n['その他'], '砂糖');
    $n['焼酎'] = $add($n['その他'], '焼酎');
    $n['芋焼酎'] = $add($n['焼酎'], '芋焼酎');

    return $n;
}

function markupCocktail(string $name): Cocktail
{
    return Cocktail::factory()->create(['name' => $name, 'name_search' => NameNormalizer::normalize($name)]);
}

function markupBrand(string $name): Brand
{
    return Brand::factory()->create(['name' => $name, 'name_search' => NameNormalizer::normalize($name)]);
}

function markupDictionary(string $name): Dictionary
{
    return Dictionary::factory()->create(['name' => $name, 'name_search' => NameNormalizer::normalize($name)]);
}

/**
 * 422 になり、$attribute にエラーが付くことを確かめる。
 */
function expectMarkupRejected(callable $call, string $attribute = 'description'): void
{
    $caught = null;
    try {
        $call();
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    assert($caught instanceof ValidationException);
    expect($caught->status)->toBe(422)
        ->and($caught->errors())->toHaveKey($attribute);
}

beforeEach(function () {
    $this->markup = app(DescriptionMarkup::class);
});

it('TC-287-01: 片仮名の並びが、名前の一致するノードの記法になる', function () {
    $n = descriptionMarkupTree();

    expect($this->markup->toStored('ラムとジンを使う'))
        ->toBe("@n{$n['ラム']->id}@と@n{$n['ジン']->id}@を使う");
});

it('TC-287-02: 同じ名前があれば、ノード → カクテル → 銘柄 → 用語の順に選ぶ', function () {
    $n = descriptionMarkupTree();
    markupCocktail('ラム');
    $mojito = markupCocktail('モヒート');
    markupBrand('モヒート');
    $bacardi = markupBrand('バカルディ');
    markupDictionary('バカルディ');
    $shaker = markupDictionary('シェーカー');

    // 中黒は片仮名の並びに含まれる(旧と同じ)ので、読点で区切る
    expect($this->markup->toStored('ラム、モヒート、バカルディ、シェーカー'))
        ->toBe("@n{$n['ラム']->id}@、@c{$mojito->id}@、@b{$bacardi->id}@、@d{$shaker->id}@");
});

it('TC-287-03: 一致する名前が無い片仮名の並びと、1 文字の片仮名はそのまま残る', function () {
    descriptionMarkupTree();

    expect($this->markup->toStored('ウォッカとラを混ぜる'))->toBe('ウォッカとラを混ぜる');
});

it('TC-287-04: 名前は name_search の形にそろえて比べる(中黒を含む並びでも一致する)', function () {
    $moscow = markupCocktail('モスコミュール');

    expect($this->markup->toStored('モスコ・ミュールを作る'))->toBe("@c{$moscow->id}@を作る");
});

it('TC-287-05: 名前で書いた記法が ID の記法になる(大文字は書いたまま残す)', function () {
    $n = descriptionMarkupTree();
    $mojito = markupCocktail('モヒート');
    $bacardi = markupBrand('バカルディ');
    $shaker = markupDictionary('シェーカー');

    expect($this->markup->toStored('@nラム@と@cモヒート@と@Bバカルディ@と@dシェーカー@'))
        ->toBe("@n{$n['ラム']->id}@と@c{$mojito->id}@と@B{$bacardi->id}@と@d{$shaker->id}@");
});

it('TC-287-06: ID で書いた記法はそのまま残す', function () {
    descriptionMarkupTree();

    expect($this->markup->toStored('前に@c99999@を見る'))->toBe('前に@c99999@を見る');
});

it('TC-287-07: 名前で書いた記法の名前が見つからなければ 422', function () {
    descriptionMarkupTree();

    expectMarkupRejected(fn () => $this->markup->toStored('@cないカクテル@を作る'));
    expectMarkupRejected(fn () => $this->markup->toStored('@cないカクテル@', 'note'), 'note');
});

it('TC-287-08: 記法の種類が n・c・b・d でなければ、名前でも ID でも 422(旧の p・m も)', function () {
    descriptionMarkupTree();

    expectMarkupRejected(fn () => $this->markup->toStored('@xラム@'));
    expectMarkupRejected(fn () => $this->markup->toStored('@pラム@'));
    expectMarkupRejected(fn () => $this->markup->toStored('@mラム@'));
    expectMarkupRejected(fn () => $this->markup->toStored('前に@x12@を見る'));
    expectMarkupRejected(fn () => $this->markup->toStored('前に@p12@を見る'));
    expectMarkupRejected(fn () => $this->markup->toStored('前に@m12@を見る'));
});

it('TC-287-09: 文末の閉じていない記法(@ と英字の後が数字)は 422。メールアドレスも 422(#392 で改めた)', function () {
    descriptionMarkupTree();

    expectMarkupRejected(fn () => $this->markup->toStored('前に@c12'));
    expectMarkupRejected(fn () => $this->markup->toStored('連絡は info@example.com へ'));
});

it('TC-287-10: 旧で決め打ちしていた漢字の名前を、ミドルの方から先にノードの記法にする', function () {
    $n = descriptionMarkupTree();

    expect($this->markup->toStored('砂糖と芋焼酎と焼酎'))
        ->toBe("@n{$n['砂糖']->id}@と@n{$n['芋焼酎']->id}@と@n{$n['焼酎']->id}@");
});

it('TC-287-11: 決め打ちの漢字の名前のノードが無ければ、そのまま残す', function () {
    descriptionMarkupTree();

    expect($this->markup->toStored('巨峰とグラニュー糖'))->toBe('巨峰とグラニュー糖');
});

it('TC-287-12: 保存のときは null と空の文字列をそのまま返し、編集で開くときはどちらも null を返す', function () {
    expect($this->markup->toStored(null))->toBeNull()
        ->and($this->markup->toStored(''))->toBe('')
        ->and($this->markup->withNotes(null))->toBeNull()
        ->and($this->markup->withNotes(''))->toBeNull();
});

it('TC-287-13: 同じ名前のノードが複数あれば、浅いもの、同じ深さなら ID の小さいものを選ぶ', function () {
    $n = descriptionMarkupTree();

    expect($this->markup->toStored('ホワイト'))->toBe("@n{$n['ラム/ホワイト']->id}@");

    $shallowWhite = app(CategoryTree::class)->addNode(
        $n['蒸留酒'],
        Category::factory()->make(['name' => 'ホワイト', 'name_search' => 'ホワイト'])->getAttributes(),
    );

    expect($this->markup->toStored('ホワイト'))->toBe("@n{$shallowWhite->id}@");
});

it('TC-287-14: 編集で開くとき、ID の記法に指す先の名前を添える', function () {
    $n = descriptionMarkupTree();
    $mojito = markupCocktail('モヒート');
    $bacardi = markupBrand('バカルディ');
    $shaker = markupDictionary('シェーカー');

    expect($this->markup->withNotes("@n{$n['ラム']->id}@と@C{$mojito->id}@と@b{$bacardi->id}@と@d{$shaker->id}@"))
        ->toBe("@n{$n['ラム']->id}@{!ラム!}と@C{$mojito->id}@{!モヒート!}と@b{$bacardi->id}@{!バカルディ!}と@d{$shaker->id}@{!シェーカー!}");
});

it('TC-287-15: 2 段より深いノードには「親の名前/名前」を添える。根と 1 段目は名前だけ', function () {
    $n = descriptionMarkupTree();

    expect($this->markup->withNotes("@n{$n['ラム/ホワイト']->id}@・@n{$n['蒸留酒']->id}@"))
        ->toBe("@n{$n['ラム/ホワイト']->id}@{!ラム/ホワイト!}・@n{$n['蒸留酒']->id}@{!蒸留酒!}");
});

it('TC-287-16: 編集で開くとき、指す先の無い記法は取り除く', function () {
    descriptionMarkupTree();

    expect($this->markup->withNotes('前に@c99999@を見る'))->toBe('前にを見る');
});

it('TC-287-17: 名前を添えて開き、そのまま保存すると、添えた名前が取れて元に戻る', function () {
    $n = descriptionMarkupTree();
    $mojito = markupCocktail('モヒート');
    $stored = "@n{$n['ラム/ホワイト']->id}@を使った@c{$mojito->id}@。\n@n{$n['砂糖']->id}@を少し";

    $opened = $this->markup->withNotes($stored);

    expect($opened)->not->toBe($stored)
        ->and($this->markup->toStored($opened))->toBe($stored);
});

it('TC-287-18: UTF-8 でない文字列は 422', function () {
    expectMarkupRejected(fn () => $this->markup->toStored("ラム\xff"));
});

it('TC-287-20: ID の形でない数字(@c0@・@c012@・@c1.5@・@c-5@)は ID の記法とみなさず、名前として引いて見つからなければ 422', function () {
    descriptionMarkupTree();

    foreach (['@c0@', '@c012@', '@c1.5@', '@c-5@'] as $text) {
        expectMarkupRejected(fn () => $this->markup->toStored($text));
    }
    expect($this->markup->toStored('@c12@'))->toBe('@c12@');
});

it('TC-287-21: @ を 2 つ以上含むメールアドレスの間は、旧と同じく名前で書いた記法とみなし、見つからなければ 422(旧は例外)', function () {
    descriptionMarkupTree();

    expectMarkupRejected(fn () => $this->markup->toStored('連絡は a@b.com と c@d.com へ'));
});

it('TC-287-19: 名前の一致する語は根にリンクしない。名前で書いた記法なら根も指せる', function () {
    $n = descriptionMarkupTree();
    $soft = app(CategoryTree::class)->addNode(
        null,
        Category::factory()->make(['name' => 'ソフトドリンク', 'name_search' => 'ソフトドリンク', 'role' => 'soft_drink'])->getAttributes(),
    );
    $white = app(CategoryTree::class)->addNode(
        null,
        Category::factory()->make(['name' => 'ホワイト', 'name_search' => 'ホワイト'])->getAttributes(),
    );

    expect($this->markup->toStored('ソフトドリンクで割る'))->toBe('ソフトドリンクで割る')
        ->and($this->markup->toStored('ホワイト'))->toBe("@n{$n['ラム/ホワイト']->id}@")
        ->and($this->markup->toStored('@nソフトドリンク@と@nホワイト@'))->toBe("@n{$soft->id}@と@n{$white->id}@");
});

/**
 * 422 になったときのメッセージ($attribute の最初の 1 件)。422 にならなければ null。
 */
function markupRejectionMessage(callable $call, string $attribute = 'description'): ?string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->validator->errors()->first($attribute);
    }

    return null;
}

it('TC-392-01: 文末の、名前で書いた記法の閉じ忘れは 422', function () {
    markupCocktail('モヒート');
    markupBrand('バカルディ');

    expectMarkupRejected(fn () => $this->markup->toStored('@cモヒート@と@bバカルディ'));
});

it('TC-392-02: 2 つの記法の間の @ を 1 つで済ませたものは 422', function () {
    markupCocktail('モヒート');
    markupCocktail('ジントニック');

    expectMarkupRejected(fn () => $this->markup->toStored('@cモヒート@cジントニック@'));
    expectMarkupRejected(fn () => $this->markup->toStored('@c5@c7@'));
});

it('TC-392-03: 余分な @ があれば 422', function () {
    markupCocktail('モヒート');
    markupBrand('バカルディ');

    expectMarkupRejected(fn () => $this->markup->toStored('@c5@と@b9@@'));
    expectMarkupRejected(fn () => $this->markup->toStored('@cモヒート@と@@bバカルディ@'));
});

it('TC-392-04: 種類の英字・先頭の @ が無い記法と、全角の ＠ は 422(エラーは渡した入力の名前に付く)', function () {
    foreach (['@3621@', 'p29@', '＠p@'] as $text) {
        expectMarkupRejected(fn () => $this->markup->toStored($text));
        expectMarkupRejected(fn () => $this->markup->toStored($text, 'note'), 'note');
    }
});

it('TC-392-05: 記法でない @(メールアドレス)は 422', function () {
    expectMarkupRejected(fn () => $this->markup->toStored('連絡は info@example.com へ'));
});

it('TC-392-06: 422 のメッセージに、残った @ の前後の文字が入る', function () {
    expect(markupRejectionMessage(fn () => $this->markup->toStored('連絡は info@example.com へ')))
        ->toBe('記法になっていない @ があります:  info@examp')
        ->and(markupRejectionMessage(fn () => $this->markup->toStored('p29@')))
        ->toBe('記法になっていない @ があります: p29@');
});

it('TC-392-07: 正しく書いた記法(複数・隣り合う・名前と ID の混在・同じ記法が 2 回)は変換される', function () {
    $n = descriptionMarkupTree();
    $mojito = markupCocktail('モヒート');
    $bacardi = markupBrand('バカルディ');

    expect($this->markup->toStored("@cモヒート@@Bバカルディ@と@c{$mojito->id}@と@cモヒート@、@nラム@"))
        ->toBe("@c{$mojito->id}@@B{$bacardi->id}@と@c{$mojito->id}@と@c{$mojito->id}@、@n{$n['ラム']->id}@");
});

it('TC-392-08: 編集で開いた({!…!} 付きの)文に記法を書き足して保存すると、書き足した記法も ID になる', function () {
    $n = descriptionMarkupTree();
    $mojito = markupCocktail('モヒート');
    $gin = markupCocktail('ジントニック');
    $stored = "@n{$n['ラム']->id}@で作る@c{$mojito->id}@";

    expect($this->markup->toStored($this->markup->withNotes($stored).'と@cジントニック@'))
        ->toBe("{$stored}と@c{$gin->id}@");
});

it('TC-392-09: 正しい記法と地の文だけの保存済みの文は、編集で開いてそのまま保存しても 422 にならず、変わらない', function () {
    $n = descriptionMarkupTree();
    $mojito = markupCocktail('モヒート');
    $bacardi = markupBrand('バカルディ');
    $shaker = markupDictionary('シェーカー');
    $stored = "@n{$n['ラム/ホワイト']->id}@@C{$mojito->id}@を、@b{$bacardi->id}@で。\n@d{$shaker->id}@で振る。@C{$mojito->id}@";

    expect($this->markup->toStored($this->markup->withNotes($stored)))->toBe($stored);
});
