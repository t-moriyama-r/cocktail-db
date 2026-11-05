<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Services\CategoryTree;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * まだ商品の行(affiliates)が無いノード・銘柄を、名前で Amazon・楽天から探し、見つけた商品の ID と値を保存する(#248)。
 * 見つけた商品は ID で持ち、次からは ID で取り直す(#249)。期限・件数の決まりは docs/decisions/affiliate-freshness.md。
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
 * - どちらの失敗でも、片方の提供元が見つけた値も保存しない。保存すると行ができて次の回に選ばれず、失敗した提供元を
 *   名前で探し直す処理が無いため
 *
 * 手で入れた値(human_*)は書かない。探しているあいだに行ができていたら(管理画面で手で作った、など)、その行は書き換えない。
 */
final class AffiliateCollector
{
    /** 1 回に新しく探す件数の既定値(docs/decisions/affiliate-freshness.md の 5) */
    public const DEFAULT_NEW_LIMIT = 5;

    /** 件と件のあいだに空ける時間。Amazon・楽天とも 1 秒以上あける(同じく 5。旧も 1 件ごとに 1 秒待っていた) */
    public const INTERVAL_MILLISECONDS = 1000;

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

        $outcomes = [];
        foreach ($this->newTargets($limit) as $index => $target) {
            if ($index > 0) {
                Sleep::for(self::INTERVAL_MILLISECONDS)->milliseconds();
            }

            $outcome = $this->collectOne($target, $amazonConfigured, $rakutenConfigured);
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
     * @return array<string, mixed> 保存する Amazon の列。見つからなければ空
     *
     * @throws AmazonRetryLaterException
     * @throws AmazonApiException
     */
    private function searchAmazon(string $name): array
    {
        $result = $this->amazon->searchItems($name);
        $item = $result?->items[0] ?? null;
        if ($item === null || $item->price === null) {
            return [];
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
        ];
    }

    /**
     * 旧(RakutenFactory::generateFromWord)と同じく、先頭の 1 件を使う。検索結果のページへのリンクも旧と同じく、API の
     * 値ではなく名前から組み立てる。
     *
     * @return array<string, mixed> 保存する楽天の列。見つからなければ空
     *
     * @throws RakutenRetryLaterException
     * @throws RakutenApiException
     */
    private function searchRakuten(string $name): array
    {
        $item = $this->rakuten->searchItems($name, RakutenClient::LIQUOR_GENRE_ID)[0] ?? null;
        if ($item === null) {
            return [];
        }

        return [
            'rakuten_item_code' => $item->itemCode,
            'rakuten_title' => $item->title,
            'rakuten_price' => $item->price,
            'rakuten_image_url' => $item->imageUrl,
            'rakuten_detail_url' => $item->detailUrl,
            'rakuten_search_url' => $this->searchUrls->rakuten($name),
            'rakuten_fetched_at' => now(),
        ];
    }
}
