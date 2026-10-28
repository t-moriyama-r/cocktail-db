<?php

use App\Support\AdminListName;

// AdminListName::fold が、旧の Helper.katakanaToHiragana(旧の管理画面の一覧が、並べる・絞る前にかけていた変換)と
// 同じ結果を返すかを確かめる(#289)。期待値は、旧の Helper を node で動かして同じ入力を渡して出た値。

it('TC-289-13: 旧の Helper.katakanaToHiragana と同じ結果を返す', function (string $name, string $expected) {
    expect(AdminListName::fold($name))->toBe($expected);
})->with([
    'カタカナはひらがなに(ヴも)' => ['ヴェスパー', 'ゔぇすぱー'],
    'ヵ・ヶもひらがなに' => ['ヵヶ', 'ゕゖ'],
    '全角の英数字は半角に。大文字・小文字は変えない' => ['Ｂ５２ｘ', 'B52x'],
    '全角の大文字の英字' => ['ＡＢＣ', 'ABC'],
]);
