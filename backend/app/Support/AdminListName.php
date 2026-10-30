<?php

namespace App\Support;

/**
 * 管理画面の一覧で、名前を並べる・絞り込むときの比べ方(#289)。
 *
 * 旧の管理画面の一覧(旧 ListComponent.vue)は、全件を受け取って画面の JS で並べ・絞っていた。比べる前に、名前を
 * 旧の Helper.katakanaToHiragana(全角の英数字を半角に、カタカナ(U+30A1〜U+30F6)をひらがなに)でそろえ、
 * 並べるときは小文字にもしていた。並びと絞り込みの結果を旧と同じにするため、同じそろえ方をここに写す。
 *
 * NameNormalizer(name_search の形)は区切りの文字を消し、カタカナとひらがなをそろえないので、旧の一覧の並びとは
 * 合わない。そのため使わない。
 *
 * 条件検索の結果の並べ替え(#167。CocktailSearchSort)も、旧の画面が同じ Helper.katakanaToHiragana でそろえていたので、fold を使う。
 */
final class AdminListName
{
    /**
     * 旧の Helper.katakanaToHiragana と同じ変換。全角の英数字を半角にし、カタカナをひらがなにする。
     * 大文字・小文字は変えない。
     */
    public static function fold(string $name): string
    {
        $halfWidth = preg_replace_callback(
            '/[Ａ-Ｚａ-ｚ０-９]/u',
            fn (array $m) => mb_chr(mb_ord($m[0]) - 0xFEE0),
            $name,
        ) ?? $name;

        return preg_replace_callback(
            '/[\x{30A1}-\x{30F6}]/u',
            fn (array $m) => mb_chr(mb_ord($m[0]) - 0x60),
            $halfWidth,
        ) ?? $halfWidth;
    }

    /**
     * 旧の Helper.aiu と同じ順に並べるための鍵。バイト列として(strcmp・SORT_STRING で)比べる。
     *
     * fold した後に小文字にし、JS の文字列の比較と同じく UTF-16 の符号の単位で比べられるよう、UTF-16BE にする。
     */
    public static function sortKey(string $name): string
    {
        return mb_convert_encoding(mb_strtolower(self::fold($name)), 'UTF-16BE', 'UTF-8');
    }

    /**
     * 旧の管理画面の用語辞典の一覧(旧 DictionaryList.vue の sortedList)と同じ順に並べるための鍵。比べ方は sortKey と同じ。
     *
     * 旧の用語辞典の一覧は Helper.aiu を使わず、fold しただけの名前を比べていた(小文字にしない)ので、それに合わせる。
     */
    public static function caseSensitiveSortKey(string $name): string
    {
        return mb_convert_encoding(self::fold($name), 'UTF-16BE', 'UTF-8');
    }

    /**
     * 語 $q が、名前か英名のどこかに含まれるか。
     *
     * 旧の ListComponent.vue の絞り込みと同じそろえ方で比べる(名前は fold だけで大文字・小文字を区別し、英名は
     * 小文字にもそろえる)。旧は前方一致だったが、部分一致にする(#284 の 6。#289 の「旧との差分」)。
     */
    public static function matches(string $q, string $name, ?string $nameEng): bool
    {
        $folded = self::fold($q);

        if (str_contains(self::fold($name), $folded)) {
            return true;
        }

        return $nameEng !== null
            && str_contains(mb_strtolower(self::fold($nameEng)), mb_strtolower($folded));
    }
}
