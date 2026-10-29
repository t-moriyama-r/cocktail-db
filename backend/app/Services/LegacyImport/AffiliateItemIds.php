<?php

namespace App\Services\LegacyImport;

/**
 * 旧のアフィリエイトの商品のページの URL から、商品の ID(Amazon の ASIN・楽天の itemCode)を取り出す(#32)。
 *
 * 旧は商品の ID を持たず、取り直すたびに名前で検索していた。新は ID で取り直す(docs/decisions/affiliate-freshness.md の 1・6)ので、
 * 旧が持っていた商品のページの URL から取り出す。取り出せない URL は、ID で取り直せない行として null にする(止めない)。
 */
final class AffiliateItemIds
{
    /** 楽天の商品のページの host。パスが /<店舗>/<商品の管理番号>/ になっている */
    private const RAKUTEN_ITEM_HOST = 'item.rakuten.co.jp';

    /**
     * Amazon の商品のページの URL(.../dp/<ASIN>...)の ASIN。ASIN は英大文字と数字の 10 桁。
     */
    public static function asin(mixed $detailUrl): ?string
    {
        return is_string($detailUrl) && preg_match('#/dp/([A-Z0-9]{10})(?=[/?\#]|$)#', $detailUrl, $match) === 1
            ? $match[1]
            : null;
    }

    /**
     * 楽天の商品のページの URL の itemCode(<店舗>:<商品の管理番号>)。旧はアフィリエイトの転送の URL
     * (hb.afl.rakuten.co.jp)を持っていて、転送先の商品のページは pc= に入っている。
     */
    public static function rakutenItemCode(mixed $detailUrl): ?string
    {
        if (! is_string($detailUrl)) {
            return null;
        }

        $itemUrl = $detailUrl;
        if (parse_url($detailUrl, PHP_URL_HOST) !== self::RAKUTEN_ITEM_HOST) {
            parse_str((string) parse_url($detailUrl, PHP_URL_QUERY), $query);
            $itemUrl = $query['pc'] ?? null;
            if (! is_string($itemUrl) || parse_url($itemUrl, PHP_URL_HOST) !== self::RAKUTEN_ITEM_HOST) {
                return null;
            }
        }

        $path = parse_url($itemUrl, PHP_URL_PATH);

        return is_string($path) && preg_match('#^/([^/:]+)/([^/:]+)/?$#', $path, $match) === 1
            ? "{$match[1]}:{$match[2]}"
            : null;
    }
}
