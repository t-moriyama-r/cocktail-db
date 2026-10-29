<?php

use App\Enums\CategoryRole;
use App\Models\Category;
use App\Services\CategoryTree;
use App\Support\AmountFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;

// AmountFormatter が、旧(本番の laravel13)の RecipeCalc::format_amount・convert_amount_int と同じ結果を返すかを
// 確かめる(#159)。期待値は、旧のファイルをそのまま PHP 8.4 で読み込み(本番の旧は PHP ^8.3)、Laravel と同じく警告を
// 例外にして、旧の関数に同じ入力を渡して出た値。「その他」の判定に閉包表を使うので、MariaDB で流す。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * 役割の違う 3 つの根と、その下のノードを作る。
 *
 * 蒸留酒(根。null) ─ ジン ─ ドライジン
 * ソフトドリンク(根。soft_drink) ─ トニックウォーター
 * その他(根。other) ─ ガーニッシュ ─ ライム
 *
 * @return array<string, Category> 呼び名 → ノード
 */
function amountFormatterNodes(): array
{
    $tree = app(CategoryTree::class);
    $attrs = amountFormatterAttrs(...);

    $liquorRoot = $tree->addNode(null, $attrs(['name' => '蒸留酒', 'role' => null]));
    $gin = $tree->addNode($liquorRoot, $attrs(['name' => 'ジン']));
    $softRoot = $tree->addNode(null, $attrs(['name' => 'ソフトドリンク', 'role' => CategoryRole::SoftDrink]));
    $otherRoot = $tree->addNode(null, $attrs(['name' => 'その他', 'role' => CategoryRole::Other]));
    $garnish = $tree->addNode($otherRoot, $attrs(['name' => 'ガーニッシュ']));

    return [
        'liquor' => $tree->addNode($gin, $attrs(['name' => 'ドライジン'])),
        'soft' => $tree->addNode($softRoot, $attrs(['name' => 'トニックウォーター'])),
        'other' => $tree->addNode($garnish, $attrs(['name' => 'ライム'])),
        'other root' => $otherRoot,
    ];
}

/**
 * addNode に渡す、保存していないノードの属性。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function amountFormatterAttrs(array $overrides): array
{
    return Category::factory()->make($overrides)->getAttributes();
}

it('TC-159-01〜31: 旧の format_amount・convert_amount_int と同じ書式と量を返す', function (string $node, string $amount, string $formatted, float $milliliters) {
    $nodes = amountFormatterNodes();
    $formatter = app(AmountFormatter::class);

    expect($formatter->format($nodes[$node], $amount))->toBe($formatted)
        ->and($formatter->toMilliliters($nodes[$node], $amount))->toBe($milliliters);
})->with([
    'TC-159-01: 数字だけなら ml を付ける' => ['liquor', '30', '30ml', 30.0],
    'TC-159-02: 「その他」で 1 なら個を付け、量は 0' => ['other', '1', '1個', 0.0],
    'TC-159-03: 「その他」でも 2 なら ml を付ける' => ['other', '2', '2ml', 2.0],
    'TC-159-04: 洋酒で 1 なら ml を付ける' => ['liquor', '1', '1ml', 1.0],
    'TC-159-05: ソフトドリンクで 1 なら ml を付ける' => ['soft', '1', '1ml', 1.0],
    'TC-159-06: 「その他」の根自身で 1 なら個を付ける' => ['other root', '1', '1個', 0.0],
    'TC-159-07: 「その他」で 1.0 は 1 とみなして個を付ける' => ['other', '1.0', '1.0個', 0.0],
    'TC-159-08: 小数なら ml を付ける' => ['liquor', '1.5', '1.5ml', 1.5],
    'TC-159-08: 「その他」でも 1.5 なら ml を付ける' => ['other', '1.5', '1.5ml', 1.5],
    'TC-159-09: 分数はそのまま。量は 100ml に対する割合' => ['liquor', '1/2', '1/2', 50.0],
    'TC-159-10: 割り切れない分数' => ['liquor', '1/3', '1/3', 33.333333333333336],
    'TC-159-11: 1 より大きい分数' => ['liquor', '3/2', '3/2', 150.0],
    'TC-159-12: t は tsp にし、量は 0' => ['liquor', '1t', '1tsp', 0.0],
    'TC-159-13: tsp はそのまま' => ['liquor', '1tsp', '1tsp', 0.0],
    'TC-159-14: 分数の tsp も量は 0' => ['liquor', '1/2tsp', '1/2tsp', 0.0],
    'TC-159-15: m は ml にする' => ['liquor', '2m', '2ml', 2.0],
    'TC-159-15: 小数の m も ml にする' => ['liquor', '1.5m', '1.5ml', 1.5],
    'TC-159-16: ml はそのまま' => ['liquor', '2ml', '2ml', 2.0],
    'TC-159-17: da は dash にし、量は 0' => ['liquor', '1da', '1dash', 0.0],
    'TC-159-17: dash はそのまま' => ['liquor', '1dash', '1dash', 0.0],
    'TC-159-18: dr は drop にし、量は 0' => ['liquor', '1dr', '1drop', 0.0],
    'TC-159-18: drop はそのまま' => ['liquor', '1drop', '1drop', 0.0],
    'TC-159-19: c は cup にし、量は適量と同じ' => ['liquor', '1c', '1cup', AmountFormatter::TO_TASTE],
    'TC-159-20: 分数の cup も量は適量と同じ' => ['liquor', '1/2cup', '1/2cup', AmountFormatter::TO_TASTE],
    'TC-159-21: oz はそのまま。量は ml に直さない' => ['liquor', '1oz', '1oz', 1.0],
    'TC-159-21: 小数の oz' => ['liquor', '1.5oz', '1.5oz', 1.5],
    'TC-159-22: 分数の oz は数字の部分だけを読む' => ['liquor', '1/2oz', '1/2oz', 1.0],
    'TC-159-22: 分数の ml は数字の部分だけを読む' => ['liquor', '1/2ml', '1/2ml', 1.0],
    'TC-159-23: - は適量にする' => ['liquor', '-', '適量', AmountFormatter::TO_TASTE],
    'TC-159-24: 適量はそのまま' => ['liquor', '適量', '適量', AmountFormatter::TO_TASTE],
    'TC-159-25: nothing は空にし、量は 0' => ['liquor', 'nothing', '', 0.0],
    'TC-159-26: 少量はそのまま。量は 0' => ['liquor', '少量', '少量', 0.0],
    'TC-159-27: g はそのまま。量は 0' => ['liquor', '10g', '10g', 0.0],
    'TC-159-28: 個はそのまま。量は 0' => ['liquor', '1個', '1個', 0.0],
    'TC-159-28: 「その他」の 2個もそのまま' => ['other', '2個', '2個', 0.0],
    'TC-159-28: 分数の個も量は 0' => ['liquor', '1/2個', '1/2個', 0.0],
    'TC-159-28: 片はそのまま。量は 0' => ['liquor', '1片', '1片', 0.0],
    'TC-159-28: 枚はそのまま。量は 0' => ['liquor', '1枚', '1枚', 0.0],
    'TC-159-29: 999ml は適量と同じ値になる' => ['liquor', '999ml', '999ml', AmountFormatter::TO_TASTE],
    'TC-159-30: 省略記法は t・m・da・dr・c の順で最初の 1 つだけ書き足す' => ['liquor', 'abc', 'abcup', AmountFormatter::TO_TASTE],
    'TC-159-30: t と m を両方含むと tsp だけを書き足す' => ['liquor', 'tm', 'tmsp', 0.0],
    'TC-159-31: 空文字は空文字のまま。量は 0' => ['liquor', '', '', 0.0],
]);

it('TC-159-32: 分母を数として読めない・0 の分数は、量に直すと例外になる', function (string $amount) {
    $nodes = amountFormatterNodes();
    $formatter = app(AmountFormatter::class);

    // 書式はそのまま整える(旧も保存する値は作れていた)
    expect($formatter->format($nodes['liquor'], $amount))->toBe($amount)
        ->and(fn () => $formatter->toMilliliters($nodes['liquor'], $amount))->toThrow(InvalidArgumentException::class);
})->with([
    'TC-159-32: 分母が無い' => ['1/'],
    'TC-159-32: 分母が 0' => ['2/0'],
    'TC-159-32: 分母に単位が付いている' => ['1/2g'],
]);

it('TC-159-33: 1 以外の量は「その他」かを見ないので、木に入っていないノードでも書式を整えられる', function () {
    $formatter = app(AmountFormatter::class);
    $outside = Category::factory()->create();

    expect($formatter->format($outside, '30'))->toBe('30ml')
        ->and($formatter->toMilliliters($outside, '1t'))->toBe(0.0)
        ->and(fn () => $formatter->format($outside, '1'))->toThrow(InvalidArgumentException::class);
});
