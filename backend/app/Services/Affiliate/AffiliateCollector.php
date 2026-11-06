<?php

namespace App\Services\Affiliate;

use App\Enums\AffiliateNotFoundReason;
use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Services\CategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * まだ商品の行(affiliates)が無いノード・銘柄を、名前で Amazon・楽天から探し、見つけた商品の ID と値を保存する(#248)。
 * 見つけた商品は ID で持ち、次からは ID で取り直す(#249)。期限・件数の決まりは docs/decisions/affiliate-freshness.md。
 *
 * 行はあるが商品の ID を持たない提供元(何も見つからなかった空の行・取り直しで片方の ID だけが消えた行など)も、
 * 最後に名前で探した時刻(*_searched_at)から RESEARCH_AFTER_DAYS 日たったら、名前で探し直す(#696。旧も 7 日ごとに
 * 名前で探し直していた)。行の無いノード・銘柄を先に選び、残りの件数で探し直す。探した提供元には、探した時刻と、
 * 見つからなかった理由(*_not_found_reason。見つかれば null)を残す。
 * - 探し直すのは、その提供元の ID が無く、まだ名前で探していないか、探してから 7 日たった提供元
 * - 手で入れた値(human_*)のある行は、その提供元で取れなかった理由が残っているとき(取り直しで ID が消えた、など)
 *   だけ探し直す。手で入れた値だけを持ち、一度も探していない行は、旧と同じく探さない(旧は human_price のある行を
 *   探し直さなかった)
 * - 並びは、見られた時刻(last_viewed_at)の新しい順、行の ID の順(取り直しと同じく、見られているページから)
 * - 時間を置いても通らない API の失敗は、その提供元に失敗の理由(api_error)と探した時刻を残し、7 日後に探し直す。
 *   探した時刻を残さないと、毎回同じ行が先頭に選ばれ、ほかの行が探し直されないため
 *
 * 選び方と探し方は、旧(AffiliateController@main・AffiliateFactory::exeSearch)が本番で実際にしていたことに合わせる。
 * - 探すのは、行の無い銘柄すべてと、行の無い「根の直下のノード」(旧のカテゴリ)のうち有名な銘柄の無いもの。旧はミドル
 *   (根の 2 段下)も探すつもりのコードだったが、クエリビルダーのまま foreach に渡していて 1 件も探していなかった
 *   (docs/decisions/affiliate-freshness.md の「旧ソースで確かめた事実」)。根(旧の種別)は旧も探さない
 * - 並びは旧と同じく銘柄 → ノードの順、それぞれ ID の順
 * - 検索の語はノード・銘柄の名前。Amazon は食料品(GroceryAndGourmetFood)で探した先頭の 1 件で、価格の無い商品なら
 *   見つからない扱い。楽天は探した先頭の 1 件
 * - 楽天はいつもジャンル「ビール・洋酒」で絞る。旧は「洋酒ではない」ノードで絞らないつもりのコードだったが、判定と
 *   引数の渡し方の 2 か所の誤りで、本番ではいつも絞っていた(docs/decisions/category-root-role.md の「洋酒ではない
 *   (kind 4 以上)は、本番では効いていない」)。絞らないと検索結果が旧と変わり、その差分はまだ決まっていないので、
 *   旧に合わせる
 * - どちらでも見つからなくても、空の行を作る
 *
 * 失敗の扱い:
 * - 認証情報の無い提供元には問い合わせない。両方とも無ければ何もしない。片方だけあれば、その提供元だけで探して保存する
 * - 429・5xx・つながらない(RetryLater)ときは、その件を保存せずに、この回を終える。上限に当たっているあいだに続けて
 *   呼んでも通らないため。保存しなかった件は行が無いままなので、次の回にまた選ばれる
 * - それ以外の API の失敗は、その件を保存せずに次の件へ進む(次の回にまた選ばれる)
 * - どちらの失敗でも、行の無いノード・銘柄では、片方の提供元が見つけた値も保存しない(行を作らず、次の回に両方を
 *   探し直す。#248 のときの決まり)
 *
 * 手で入れた値(human_*)は書かない。探しているあいだに行ができていたら(管理画面で手で作った、など)、その行は書き換えない。
 */
final class AffiliateCollector
{
    /** 1 回に新しく探す件数の既定値(docs/decisions/affiliate-freshness.md の 5) */
    public const DEFAULT_NEW_LIMIT = 5;

    /** 件と件のあいだに空ける時間。Amazon・楽天とも 1 秒以上あける(同じく 5。旧も 1 件ごとに 1 秒待っていた) */
    public const INTERVAL_MILLISECONDS = 1000;

    /** 名前で探してからこの日数たった、ID の無い提供元を探し直す(#696。旧と同じ 7 日) */
    public const RESEARCH_AFTER_DAYS = 7;

    public function __construct(
        private readonly AmazonClient $amazon,
        private readonly RakutenClient $rakuten,
        private readonly AffiliateSearchUrls $searchUrls,
        private readonly CategoryTree $tree,
    ) {}

    /**
     * まだ行の無いノード・銘柄を $limit 件まで探して保存する。
     *
     * @param  (callable(CollectOutcome): void)|null  $onOutcome  1 件を終えるたびに呼ぶ(コマンドが進み具合を出すため)
     * @return list<CollectOutcome>
     */
    public function collectNew(int $limit, ?callable $onOutcome = null): array
    {
        $amazonConfigured = $this->amazon->isConfigured();
        $rakutenConfigured = $this->rakuten->isConfigured();
        if (! $amazonConfigured && ! $rakutenConfigured) {
            return [];
        }

        $targets = $this->newTargets($limit);
        $targets = [...$targets, ...$this->researchTargets($limit - count($targets), $amazonConfigured, $rakutenConfigured)];

        $outcomes = [];
        foreach ($targets as $index => $target) {
            if ($index > 0) {
                Sleep::for(self::INTERVAL_MILLISECONDS)->milliseconds();
            }

            $outcome = $target->affiliateId !== null
                ? $this->researchOne($target)
                : $this->collectOne($target, $amazonConfigured, $rakutenConfigured);
            $outcomes[] = $outcome;
            if ($onOutcome !== null) {
                $onOutcome($outcome);
            }
            if ($outcome->status === CollectOutcome::RETRY_LATER) {
                break;
            }
        }

        return $outcomes;
    }

    /**
     * まだ行の無い銘柄 → ノードの順に、$limit 件まで選ぶ。
     *
     * @return list<CollectTarget>
     */
    public function newTargets(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $targets = [];
        $brands = Brand::query()
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->from('affiliates')
                ->whereColumn('affiliates.brand_id', 'brands.id'))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'category_id', 'name']);
        foreach ($brands as $brand) {
            $targets[] = new CollectTarget($brand->category_id, $brand->id, $brand->name);
        }

        $rest = $limit - count($targets);
        if ($rest < 1) {
            return $targets;
        }

        $nodes = Category::query()
            ->whereIn('id', CategoryClosure::query()
                ->select('descendant_id')
                ->where('depth', 1)
                ->whereIn('ancestor_id', $this->tree->rootIds()))
            ->whereNull('famous_brand_id')
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->from('affiliates')
                ->whereColumn('affiliates.category_id', 'categories.id')
                ->whereNull('affiliates.brand_id'))
            ->orderBy('id')
            ->limit($rest)
            ->get(['id', 'name']);
        foreach ($nodes as $node) {
            $targets[] = new CollectTarget($node->id, null, $node->name);
        }

        return $targets;
    }

    /**
     * 行はあるが ID の無い提供元を探し直す行を、見られた時刻の新しい順に $limit 件まで選ぶ(#696)。
     * 認証情報の無い提供元は探さないので、その提供元だけが探し直す対象の行は選ばない。
     *
     * @return list<CollectTarget>
     */
    public function researchTargets(int $limit, bool $amazonConfigured, bool $rakutenConfigured): array
    {
        if ($limit < 1 || (! $amazonConfigured && ! $rakutenConfigured)) {
            return [];
        }

        $threshold = now()->subDays(self::RESEARCH_AFTER_DAYS);
        $rows = Affiliate::query()
            ->with(['brand:id,name', 'category:id,name'])
            ->where(function (Builder $query) use ($amazonConfigured, $rakutenConfigured, $threshold) {
                if ($amazonConfigured) {
                    $query->orWhere(fn (Builder $query) => self::whereDue($query, 'amazon_asin', 'amazon', $threshold));
                }
                if ($rakutenConfigured) {
                    $query->orWhere(fn (Builder $query) => self::whereDue($query, 'rakuten_item_code', 'rakuten', $threshold));
                }
            })
            // 取り直し(AffiliateRefresher)と同じく、見られていない行(NULL)は新しい順の最後に来る
            ->orderByDesc('last_viewed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $targets = [];
        foreach ($rows as $row) {
            $targets[] = new CollectTarget(
                $row->category_id,
                $row->brand_id,
                $row->brand !== null ? $row->brand->name : $row->category->name,
                $row->id,
                searchAmazon: $amazonConfigured && self::isDue($row, 'amazon_asin', 'amazon', $threshold),
                searchRakuten: $rakutenConfigured && self::isDue($row, 'rakuten_item_code', 'rakuten', $threshold),
            );
        }

        return $targets;
    }

    /**
     * その提供元を探し直す行の条件(isDue と同じ。SQL で選ぶため)。
     *
     * @param  Builder<Affiliate>  $query
     * @param  'amazon_asin'|'rakuten_item_code'  $idColumn
     * @param  'amazon'|'rakuten'  $provider
     */
    private static function whereDue(Builder $query, string $idColumn, string $provider, Carbon $threshold): void
    {
        $query->whereNull($idColumn)
            ->where(fn (Builder $query) => $query
                ->whereNull("{$provider}_searched_at")
                ->orWhere("{$provider}_searched_at", '<=', $threshold))
            ->where(function (Builder $query) use ($provider) {
                $query->whereNotNull("{$provider}_not_found_reason")
                    ->orWhere(function (Builder $query) {
                        foreach (Affiliate::HUMAN_COLUMNS as $column) {
                            $query->whereNull($column);
                        }
                    });
            });
    }

    /**
     * その提供元を探し直すか(whereDue と同じ条件)。
     *
     * @param  'amazon_asin'|'rakuten_item_code'  $idColumn
     * @param  'amazon'|'rakuten'  $provider
     */
    private static function isDue(Affiliate $row, string $idColumn, string $provider, Carbon $threshold): bool
    {
        if ($row->getAttribute($idColumn) !== null) {
            return false;
        }
        $searchedAt = $row->getAttribute("{$provider}_searched_at");
        if ($searchedAt instanceof Carbon && $searchedAt->greaterThan($threshold)) {
            return false;
        }
        if ($row->getAttribute("{$provider}_not_found_reason") !== null) {
            return true;
        }
        foreach (Affiliate::HUMAN_COLUMNS as $column) {
            if ($row->getAttribute($column) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * 行のあるノード・銘柄で、ID の無い提供元を名前で探し直して保存する(#696)。手で入れた値・もう一方の提供元の値は
     * 書き換えない。探しているあいだにその提供元の ID が入った・行が消えたときは、その提供元を書き換えない。
     */
    private function researchOne(CollectTarget $target): CollectOutcome
    {
        try {
            $amazon = $target->searchAmazon ? $this->researchProvider(fn () => $this->searchAmazon($target->name), 'amazon') : [];
            $rakuten = $target->searchRakuten ? $this->researchProvider(fn () => $this->searchRakuten($target->name), 'rakuten') : [];
        } catch (AmazonRetryLaterException|RakutenRetryLaterException $e) {
            Log::warning('affiliates:collect --new: 上限・一時的な失敗のため、この回を終える', ['target' => $target->describe(), 'error' => $e->getMessage()]);

            return new CollectOutcome($target, CollectOutcome::RETRY_LATER, message: $e->getMessage());
        }

        $updated = 0;
        if ($amazon !== []) {
            $updated += Affiliate::query()->whereKey($target->affiliateId)->whereNull('amazon_asin')->update($amazon);
        }
        if ($rakuten !== []) {
            $updated += Affiliate::query()->whereKey($target->affiliateId)->whereNull('rakuten_item_code')->update($rakuten);
        }
        if ($updated === 0) {
            return new CollectOutcome($target, CollectOutcome::CHANGED);
        }

        return new CollectOutcome(
            $target,
            CollectOutcome::RESEARCHED,
            amazonAsin: self::stringOrNull($amazon['amazon_asin'] ?? null),
            rakutenItemCode: self::stringOrNull($rakuten['rakuten_item_code'] ?? null),
            amazonReason: AffiliateNotFoundReason::tryFrom(self::stringOrNull($amazon['amazon_not_found_reason'] ?? null) ?? ''),
            rakutenReason: AffiliateNotFoundReason::tryFrom(self::stringOrNull($rakuten['rakuten_not_found_reason'] ?? null) ?? ''),
        );
    }

    /**
     * 1 つの提供元を探し直す。時間を置いても通らない API の失敗は、探した時刻と失敗の理由だけを返す(値と ID は
     * 書き換えない)。上限・一時的な失敗(RetryLater)は、そのまま投げる。
     *
     * @param  callable(): array<string, mixed>  $search
     * @param  'amazon'|'rakuten'  $provider
     * @return array<string, mixed>
     *
     * @throws AmazonRetryLaterException
     * @throws RakutenRetryLaterException
     */
    private function researchProvider(callable $search, string $provider): array
    {
        try {
            return $search();
        } catch (AmazonApiException|RakutenApiException $e) {
            Log::warning('affiliates:collect --new: 探し直しで API の失敗のため、失敗の理由を残して 7 日後に探し直す', ['provider' => $provider, 'error' => $e->getMessage()]);

            return [
                "{$provider}_searched_at" => now(),
                "{$provider}_not_found_reason" => AffiliateNotFoundReason::ApiError->value,
            ];
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function collectOne(CollectTarget $target, bool $amazonConfigured, bool $rakutenConfigured): CollectOutcome
    {
        try {
            $amazon = $amazonConfigured ? $this->searchAmazon($target->name) : [];
            $rakuten = $rakutenConfigured ? $this->searchRakuten($target->name) : [];
        } catch (AmazonRetryLaterException|RakutenRetryLaterException $e) {
            Log::warning('affiliates:collect --new: 上限・一時的な失敗のため、この回を終える', ['target' => $target->describe(), 'error' => $e->getMessage()]);

            return new CollectOutcome($target, CollectOutcome::RETRY_LATER, message: $e->getMessage());
        } catch (AmazonApiException|RakutenApiException $e) {
            Log::warning('affiliates:collect --new: API の失敗のため、この件を飛ばす', ['target' => $target->describe(), 'error' => $e->getMessage()]);

            return new CollectOutcome($target, CollectOutcome::FAILED, message: $e->getMessage());
        }

        // 探しているあいだに管理画面でノード・銘柄が消されたら、外部キー違反で残りの件が止まらないよう、保存せずに飛ばす
        $exists = $target->brandId !== null
            ? Brand::query()->whereKey($target->brandId)->exists()
            : Category::query()->whereKey($target->categoryId)->exists();
        if (! $exists) {
            return new CollectOutcome($target, CollectOutcome::FAILED, message: '探しているあいだにノード・銘柄が消された');
        }

        $affiliate = Affiliate::query()->createOrFirst(
            ['category_id' => $target->categoryId, 'brand_id' => $target->brandId],
            [...$amazon, ...$rakuten],
        );
        if (! $affiliate->wasRecentlyCreated) {
            return new CollectOutcome($target, CollectOutcome::ALREADY_EXISTS);
        }

        return new CollectOutcome(
            $target,
            CollectOutcome::SAVED,
            amazonAsin: $affiliate->amazon_asin,
            rakutenItemCode: $affiliate->rakuten_item_code,
        );
    }

    /**
     * 旧(AmazonFactory::generateFromWord)と同じく、先頭の 1 件だけを見て、価格が無ければ見つからない扱いにする。
     *
     * @return array<string, mixed> 保存する Amazon の列と、探した時刻・見つからなかった理由(#696)。見つからなければ、
     *                              Amazon の ID と値を空にし、理由を入れる
     *
     * @throws AmazonRetryLaterException
     * @throws AmazonApiException
     */
    private function searchAmazon(string $name): array
    {
        $result = $this->amazon->searchItems($name);
        $item = $result?->items[0] ?? null;
        if ($item === null || $item->price === null) {
            $reason = $item === null ? AffiliateNotFoundReason::NoResults : AffiliateNotFoundReason::NoPrice;

            return [
                ...array_fill_keys(Affiliate::AMAZON_COLUMNS, null),
                'amazon_searched_at' => now(),
                'amazon_not_found_reason' => $reason->value,
            ];
        }

        return [
            'amazon_asin' => $item->asin,
            'amazon_title' => $item->title,
            'amazon_price' => $item->price,
            'amazon_image_large_url' => $item->imageUrlLarge,
            'amazon_image_medium_url' => $item->imageUrlMedium,
            'amazon_image_small_url' => $item->imageUrlSmall,
            'amazon_detail_url' => $item->detailPageUrl,
            'amazon_search_url' => $result->searchUrl,
            'amazon_fetched_at' => now(),
            'amazon_searched_at' => now(),
            'amazon_not_found_reason' => null,
        ];
    }

    /**
     * 旧(RakutenFactory::generateFromWord)と同じく、先頭の 1 件を使う。検索結果のページへのリンクも旧と同じく、API の
     * 値ではなく名前から組み立てる。
     *
     * @return array<string, mixed> 保存する楽天の列と、探した時刻・見つからなかった理由(#696)。見つからなければ、
     *                              楽天の ID と値を空にし、理由を入れる
     *
     * @throws RakutenRetryLaterException
     * @throws RakutenApiException
     */
    private function searchRakuten(string $name): array
    {
        $item = $this->rakuten->searchItems($name, RakutenClient::LIQUOR_GENRE_ID)[0] ?? null;
        if ($item === null) {
            return [
                ...array_fill_keys(Affiliate::RAKUTEN_COLUMNS, null),
                'rakuten_searched_at' => now(),
                'rakuten_not_found_reason' => AffiliateNotFoundReason::NoResults->value,
            ];
        }

        return [
            'rakuten_item_code' => $item->itemCode,
            'rakuten_title' => $item->title,
            'rakuten_price' => $item->price,
            'rakuten_image_url' => $item->imageUrl,
            'rakuten_detail_url' => $item->detailUrl,
            'rakuten_search_url' => $this->searchUrls->rakuten($name),
            'rakuten_fetched_at' => now(),
            'rakuten_searched_at' => now(),
            'rakuten_not_found_reason' => null,
        ];
    }
}
