<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * 期限が近い・切れた商品の値を、最近見られた行から、商品の ID で取り直す(#249)。
 * 期限・間隔・件数の決まりは docs/decisions/affiliate-freshness.md の 2・5。
 *
 * 選び方(Amazon・楽天それぞれ):
 * - その提供元の ID(amazon_asin・rakuten_item_code)を持ち、取った時刻から REFRESH_AFTER_HOURS 時間を過ぎた行
 *   (ちょうど REFRESH_AFTER_HOURS 時間たった行と、取った時刻の無い行を含む)。手で入れた値だけの行は ID を持たないので選ばない
 * - 並びは、見られた時刻(last_viewed_at。#250)の新しい順。見られていない行はその後。同じなら取った時刻の古い順、行の ID の順
 * - $limit 件まで。Amazon は MAX_ITEM_IDS 件ごとに GetItems を 1 回、楽天は 1 件ずつ取る
 *
 * 取れたときは、その提供元の値と取った時刻を書き換える。検索結果のページへのリンクは、ノード・銘柄の名前から組み立てる
 * (Amazon の GetItems は検索結果のリンクを返さない。楽天は旧と同じく、いつも名前から組み立てている)。
 *
 * ID で取れなかったとき(販売終了など。Amazon は価格の無い商品も、楽天は売り切れの商品も含む。どちらも --new が名前で
 * 探すときに見つからない扱いにするもの)は、その提供元の ID と値を消す(docs/decisions/affiliate-freshness.md の 2
 * 「Amazon に出なくなった値はすぐに消す」・1 の「取り直しで取れなかった itemCode は消す」)。ID も手で入れた値も
 * 残らなくなった行は消し、次の --new(AffiliateCollector)が名前から探し直す。
 *
 * 失敗の扱い:
 * - 認証情報の無い提供元には問い合わせない
 * - 429・5xx・つながらない(RetryLater)ときは、その提供元をこの回で終える(上限に当たっているあいだに続けて呼んでも
 *   通らないため)。もう一方の提供元は続ける。書き換えなかった行は期限が近いままなので、次の回にまた選ばれる
 * - それ以外の API の失敗は、その件(Amazon は GetItems 1 回分)を書き換えずに次へ進む
 *
 * 手で入れた値(human_*)は書き換えない。取っているあいだに行の ID が変わった・行が消えたときは、その行を書き換えない。
 */
final class AffiliateRefresher
{
    /** 1 回に取り直す件数の既定値(Amazon・楽天それぞれ。docs/decisions/affiliate-freshness.md の 5) */
    public const DEFAULT_LIMIT = 10;

    /** 取った時刻からこの時間を過ぎた行を取り直す(同じく 5。24 時間の期限に間に合わせる) */
    public const REFRESH_AFTER_HOURS = 12;

    /** Amazon の呼び出しのあいだに空ける時間(同じく 5)。楽天はクライアントが 1 秒空ける */
    public const INTERVAL_MILLISECONDS = 1000;

    private const AMAZON_COLUMNS = [
        'amazon_asin', 'amazon_title', 'amazon_price',
        'amazon_image_large_url', 'amazon_image_medium_url', 'amazon_image_small_url',
        'amazon_detail_url', 'amazon_search_url', 'amazon_fetched_at',
    ];

    private const RAKUTEN_COLUMNS = [
        'rakuten_item_code', 'rakuten_title', 'rakuten_price',
        'rakuten_image_url', 'rakuten_detail_url', 'rakuten_search_url', 'rakuten_fetched_at',
    ];

    private const HUMAN_COLUMNS = [
        'human_title', 'human_price', 'human_image_url', 'human_amazon_detail_url', 'human_amazon_search_url',
    ];

    public function __construct(
        private readonly AmazonClient $amazon,
        private readonly RakutenClient $rakuten,
        private readonly AffiliateSearchUrls $searchUrls,
    ) {}

    /**
     * Amazon・楽天それぞれ $limit 件まで取り直す。
     *
     * @param  (callable(RefreshOutcome): void)|null  $onOutcome  1 件を終えるたびに呼ぶ(コマンドが進み具合を出すため)
     * @return list<RefreshOutcome>
     */
    public function refresh(int $limit, ?callable $onOutcome = null): array
    {
        $outcomes = [];
        $record = function (RefreshOutcome $outcome) use (&$outcomes, $onOutcome): void {
            $outcomes[] = $outcome;
            if ($onOutcome !== null) {
                $onOutcome($outcome);
            }
        };

        if ($this->amazon->isConfigured()) {
            $this->refreshAmazon($limit, $record);
        }
        if ($this->rakuten->isConfigured()) {
            $this->refreshRakuten($limit, $record);
        }

        return $outcomes;
    }

    /**
     * 取り直す行を、見られた時刻の新しい順に $limit 件まで選ぶ。
     *
     * @param  'amazon_asin'|'rakuten_item_code'  $idColumn
     * @param  'amazon_fetched_at'|'rakuten_fetched_at'  $fetchedAtColumn
     * @return Collection<int, Affiliate>
     */
    public function targets(string $idColumn, string $fetchedAtColumn, int $limit): Collection
    {
        if ($limit < 1) {
            return new Collection;
        }

        $threshold = now()->subHours(self::REFRESH_AFTER_HOURS);

        return Affiliate::query()
            ->with(['brand:id,name', 'category:id,name'])
            ->whereNotNull($idColumn)
            ->where(fn (Builder $query) => $query
                ->whereNull($fetchedAtColumn)
                ->orWhere($fetchedAtColumn, '<=', $threshold))
            // MariaDB は NULL をいちばん小さい値として並べるので、見られていない行は新しい順の最後に、取った時刻の
            // 無い行は古い順の最初に来る
            ->orderByDesc('last_viewed_at')
            ->orderBy($fetchedAtColumn)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'category_id', 'brand_id', $idColumn]);
    }

    /**
     * @param  callable(RefreshOutcome): void  $record
     */
    private function refreshAmazon(int $limit, callable $record): void
    {
        $rows = $this->targets('amazon_asin', 'amazon_fetched_at', $limit);
        // 同じ ASIN の行が 2 つあっても(ノードと銘柄が同じ商品、など)、GetItems には 1 回だけ入れる
        $chunks = $rows->groupBy('amazon_asin')->chunk(AmazonClient::MAX_ITEM_IDS);

        foreach ($chunks->values() as $index => $chunk) {
            if ($index > 0) {
                Sleep::for(self::INTERVAL_MILLISECONDS)->milliseconds();
            }

            /** @var list<string> $asins */
            $asins = $chunk->keys()->map(fn ($asin) => (string) $asin)->all();
            /** @var Collection<int, Affiliate> $chunkRows */
            $chunkRows = $chunk->flatten(1);

            try {
                $items = $this->amazon->getItems($asins);
            } catch (AmazonRetryLaterException $e) {
                Log::warning('affiliates:collect --refresh: Amazon の上限・一時的な失敗のため、Amazon はこの回を終える', ['asins' => $asins, 'error' => $e->getMessage()]);
                foreach ($chunkRows as $row) {
                    $record(self::outcome(RefreshOutcome::AMAZON, $row, (string) $row->amazon_asin, RefreshOutcome::RETRY_LATER, message: $e->getMessage()));
                }

                return;
            } catch (AmazonApiException $e) {
                Log::warning('affiliates:collect --refresh: Amazon の API の失敗のため、この回の分を飛ばす', ['asins' => $asins, 'error' => $e->getMessage()]);
                foreach ($chunkRows as $row) {
                    $record(self::outcome(RefreshOutcome::AMAZON, $row, (string) $row->amazon_asin, RefreshOutcome::FAILED, message: $e->getMessage()));
                }

                continue;
            }

            $byAsin = [];
            foreach ($items as $item) {
                $byAsin[$item->asin] = $item;
            }

            foreach ($chunkRows as $row) {
                $asin = (string) $row->amazon_asin;
                $item = $byAsin[$asin] ?? null;
                // --new(AffiliateCollector::searchAmazon)と同じく、価格の無い商品は見つからない扱いにする
                if ($item === null || $item->price === null) {
                    $deleted = $this->clear($row, 'amazon_asin', $asin, self::AMAZON_COLUMNS);
                    $record(self::outcome(RefreshOutcome::AMAZON, $row, $asin, RefreshOutcome::CLEARED, $deleted));

                    continue;
                }

                Affiliate::query()
                    ->whereKey($row->id)
                    ->where('amazon_asin', $asin)
                    ->update([
                        'amazon_title' => $item->title,
                        'amazon_price' => $item->price,
                        'amazon_image_large_url' => $item->imageUrlLarge,
                        'amazon_image_medium_url' => $item->imageUrlMedium,
                        'amazon_image_small_url' => $item->imageUrlSmall,
                        'amazon_detail_url' => $item->detailPageUrl,
                        'amazon_search_url' => $this->searchUrls->amazon(self::name($row)),
                        'amazon_fetched_at' => now(),
                    ]);
                $record(self::outcome(RefreshOutcome::AMAZON, $row, $asin, RefreshOutcome::REFRESHED));
            }
        }
    }

    /**
     * @param  callable(RefreshOutcome): void  $record
     */
    private function refreshRakuten(int $limit, callable $record): void
    {
        foreach ($this->targets('rakuten_item_code', 'rakuten_fetched_at', $limit) as $row) {
            $itemCode = (string) $row->rakuten_item_code;

            try {
                $item = $this->rakuten->getItem($itemCode);
            } catch (RakutenRetryLaterException $e) {
                Log::warning('affiliates:collect --refresh: 楽天の上限・一時的な失敗のため、楽天はこの回を終える', ['item_code' => $itemCode, 'error' => $e->getMessage()]);
                $record(self::outcome(RefreshOutcome::RAKUTEN, $row, $itemCode, RefreshOutcome::RETRY_LATER, message: $e->getMessage()));

                return;
            } catch (RakutenApiException $e) {
                Log::warning('affiliates:collect --refresh: 楽天の API の失敗のため、この件を飛ばす', ['item_code' => $itemCode, 'error' => $e->getMessage()]);
                $record(self::outcome(RefreshOutcome::RAKUTEN, $row, $itemCode, RefreshOutcome::FAILED, message: $e->getMessage()));

                continue;
            }

            // --new は販売できる商品だけを探す(楽天の既定)ので、売り切れの商品も見つからない扱いにする
            if ($item === null || $item->available === false) {
                $deleted = $this->clear($row, 'rakuten_item_code', $itemCode, self::RAKUTEN_COLUMNS);
                $record(self::outcome(RefreshOutcome::RAKUTEN, $row, $itemCode, RefreshOutcome::CLEARED, $deleted));

                continue;
            }

            Affiliate::query()
                ->whereKey($row->id)
                ->where('rakuten_item_code', $itemCode)
                ->update([
                    'rakuten_title' => $item->title,
                    'rakuten_price' => $item->price,
                    'rakuten_image_url' => $item->imageUrl,
                    'rakuten_detail_url' => $item->detailUrl,
                    'rakuten_search_url' => $this->searchUrls->rakuten(self::name($row)),
                    'rakuten_fetched_at' => now(),
                ]);
            $record(self::outcome(RefreshOutcome::RAKUTEN, $row, $itemCode, RefreshOutcome::REFRESHED));
        }
    }

    /**
     * その提供元の ID と値を消す。ID も手で入れた値も残らなくなった行は消す(次の --new が名前から探し直す)。
     *
     * @param  'amazon_asin'|'rakuten_item_code'  $idColumn
     * @param  list<string>  $columns
     * @return bool 行を消したか
     */
    private function clear(Affiliate $row, string $idColumn, string $itemId, array $columns): bool
    {
        $cleared = Affiliate::query()
            ->whereKey($row->id)
            ->where($idColumn, $itemId)
            ->update(array_fill_keys($columns, null));
        if ($cleared === 0) {
            return false;
        }

        $query = Affiliate::query()
            ->whereKey($row->id)
            ->whereNull('amazon_asin')
            ->whereNull('rakuten_item_code');
        foreach (self::HUMAN_COLUMNS as $column) {
            $query->whereNull($column);
        }

        return $query->delete() > 0;
    }

    /**
     * 検索結果のページへのリンクを組み立てる名前。銘柄の行は銘柄の名前、ノードの行はノードの名前(表示の API と同じ)。
     */
    private static function name(Affiliate $row): string
    {
        return $row->brand !== null ? $row->brand->name : $row->category->name;
    }

    /**
     * @param  RefreshOutcome::AMAZON|RefreshOutcome::RAKUTEN  $provider
     * @param  RefreshOutcome::REFRESHED|RefreshOutcome::CLEARED|RefreshOutcome::FAILED|RefreshOutcome::RETRY_LATER  $status
     */
    private static function outcome(string $provider, Affiliate $row, string $itemId, string $status, bool $rowDeleted = false, ?string $message = null): RefreshOutcome
    {
        return new RefreshOutcome($provider, $row->id, $itemId, $status, $rowDeleted, $message);
    }
}
