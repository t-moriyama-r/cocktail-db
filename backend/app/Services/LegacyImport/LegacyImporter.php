<?php

namespace App\Services\LegacyImport;

/**
 * 旧 DB の表のまとまりを、新しい DB に移す処理。
 *
 * 移行の練習で何度も流すので、同じ旧データで 2 回流しても結果が変わらないように書く。
 */
interface LegacyImporter
{
    /**
     * 旧 DB(接続 legacy)から新しい DB に移し、移した後の新しい表ごとの件数を返す。表の行を作らずに値を直す部分
     * (説明文の参照の記法。#285)は、直したものの数を返す。
     *
     * @return array<string, int> 新しい表の名前(か、数えたものの名前) => 件数
     */
    public function import(): array;
}
