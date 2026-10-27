<?php

namespace App\Support;

/**
 * 名前を、検索用の名前(name_search の列)の形にそろえる(#171)。保存する名前と検索する語の両方に同じ変換をかけ、
 * 書き方の揺れ(区切りの有無・全角半角の英数字)があっても同じ名前として突き合わせられるようにする。
 *
 * 旧の convert_to_name_search(旧の Markup と SearchCommon\Search に同じものがある)と同じ結果にする。
 * 移した name_search の値と、新で作る値・検索する語が食い違わないようにするため。
 */
final class NameNormalizer
{
    /**
     * 旧が消していた区切りの文字(半角のピリオド・半角の空白・全角の中黒)。
     */
    private const REMOVED_CHARACTERS = ['.', ' ', '・'];

    /**
     * 旧の値と合わせるため、旧が消していた文字だけを消し、旧が半角にしていた全角の英数字だけを半角にする。
     * 全角の空白・半角の中黒・全角の記号は旧も残しており、ひらがなとカタカナ・全角と半角のカナも旧は
     * そろえていないので、ここでも変えない。
     */
    public static function normalize(string $name): string
    {
        return mb_convert_kana(str_replace(self::REMOVED_CHARACTERS, '', $name), 'rn', 'UTF-8');
    }
}
