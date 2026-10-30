<?php

namespace App\Support;

/**
 * サーバーが返す HTML の `<title>`(#111)。
 *
 * 形は画面のタイトル(frontend の usePageTitle.ts の formatPageTitle)と同じ `〈ページ名〉 ｜ 〈サイト名〉`。
 * サイト名は usePageTitle.ts の SITE_TITLE とそろえる(画面は JS で document.title を書き換えるので、片方だけ変えると、
 * JS を動かすかどうかでタイトルが変わる)。
 */
final class PageTitle
{
    public const SITE_TITLE = '宅飲みカクテルデータベース(RC)';

    /**
     * 詳細ページのタイトル。`名前(英名) ｜ サイト名`。英名が空なら括弧を省く(旧は `名前()` を出していた。#111 の旧との差分)。
     */
    public static function forDetail(string $name, ?string $nameEng): string
    {
        $pageName = in_array($nameEng, [null, ''], true) ? $name : "{$name}({$nameEng})";

        return $pageName.' ｜ '.self::SITE_TITLE;
    }
}
