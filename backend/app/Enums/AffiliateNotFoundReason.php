<?php

namespace App\Enums;

/**
 * アフィリエイトの提供元(Amazon・楽天)で商品が取れなかった理由(affiliates.*_not_found_reason。#696)。
 *
 * 名前で探したとき(affiliates:collect --new)と、商品の ID で取り直したとき(affiliates:collect --refresh)に残す。
 * 取れたら null に戻す。
 */
enum AffiliateNotFoundReason: string
{
    /** 名前で探して、検索結果が 0 件だった */
    case NoResults = 'no_results';

    /** 商品はあったが、価格が無かった(Amazon の出品が無い商品。--new も --refresh も見つからない扱いにする) */
    case NoPrice = 'no_price';

    /** 商品の ID で取れなかった(販売終了など) */
    case Gone = 'gone';

    /** 商品の ID で取れたが、売り切れだった(楽天) */
    case SoldOut = 'sold_out';

    /** 時間を置いても通らない API の失敗(400・403 など)。--refresh では ID を残したまま、この理由だけを残す */
    case ApiError = 'api_error';
}
