<?php

namespace App\Services\Affiliate;

/**
 * Creators API の SearchItems の結果。searchUrl は Amazon の検索結果のページへのリンク(パートナータグ付き)で、
 * 1 件も見つからなかったときは null。
 */
final class AmazonSearchResult
{
    /**
     * @param  list<AmazonItem>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $searchUrl,
    ) {}
}
