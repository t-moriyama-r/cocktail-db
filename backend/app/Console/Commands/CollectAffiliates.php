<?php

namespace App\Console\Commands;

use App\Enums\AffiliateNotFoundReason;
use App\Services\Affiliate\AffiliateCollector;
use App\Services\Affiliate\AffiliateRefresher;
use App\Services\Affiliate\AmazonClient;
use App\Services\Affiliate\CollectOutcome;
use App\Services\Affiliate\RakutenClient;
use App\Services\Affiliate\RefreshOutcome;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * アフィリエイトの商品を集める(旧の AffiliateController@main。旧は毎分動いていた)。
 *
 * --refresh: 期限が近い・切れた行を、最近見られたものから商品の ID で取り直す(#249。中身は AffiliateRefresher)。
 * --new: まだ商品の行が無いノード・銘柄を名前で探し、見つけた商品の ID と値を保存する(#248。中身は AffiliateCollector)。
 *   API の失敗が続く件は、しばらく選ばずに後ろの件へ進む(#695)。
 *        残りの件数で、行はあるが ID の無い提供元を、名前で探してから 7 日たったものから探し直す(#696)。
 *
 * 両方を指定すると、--refresh → --new の順に流す。楽天は 1 秒に 1 回までで、別のプロセスとのあいだは揃えない
 * (docs/decisions/rakuten-client.md の 6)ので、定期実行(routes/console.php)は両方を 1 つのコマンドで流し、
 * あいだに 1 秒空ける。--limit を省いた件数は config/affiliates.php から読む。
 */
final class CollectAffiliates extends Command
{
    protected $signature = 'affiliates:collect
        {--refresh : 期限が近い・切れた行を、最近見られたものから商品の ID で取り直す}
        {--new : まだ商品の行が無いノード・銘柄と、ID の無い提供元(探してから 7 日たったもの)を、名前で探して保存する}
        {--limit= : 1 回の件数。--refresh は Amazon・楽天それぞれの件数(既定は設定の affiliates.refresh_limit・affiliates.new_limit)}';

    protected $description = 'アフィリエイトの商品を Amazon・楽天から集める(--refresh: ID で取り直す、--new: まだ行の無いノード・銘柄と ID の無い提供元を名前で探す)';

    public function handle(AffiliateCollector $collector, AffiliateRefresher $refresher, AmazonClient $amazon, RakutenClient $rakuten): int
    {
        $refresh = (bool) $this->option('refresh');
        $new = (bool) $this->option('new');
        if (! $refresh && ! $new) {
            $this->error('--refresh か --new を指定する');

            return self::INVALID;
        }

        $refreshLimit = $refresh ? $this->limit('affiliates.refresh_limit', AffiliateRefresher::DEFAULT_LIMIT) : null;
        $newLimit = $new ? $this->limit('affiliates.new_limit', AffiliateCollector::DEFAULT_NEW_LIMIT) : null;
        if (($refresh && $refreshLimit === null) || ($new && $newLimit === null)) {
            $this->error('--limit は 1 以上の整数で指定する(省いたときは、設定の件数を確かめる)');

            return self::INVALID;
        }

        $amazonConfigured = $amazon->isConfigured();
        $rakutenConfigured = $rakuten->isConfigured();
        if (! $amazonConfigured && ! $rakutenConfigured) {
            $this->warn('Amazon・楽天とも認証情報が設定に無いので、何もしない');

            return self::SUCCESS;
        }
        if (! $amazonConfigured) {
            $this->warn('Amazon の認証情報が設定に無いので、Amazon には問い合わせない');
        }
        if (! $rakutenConfigured) {
            $this->warn('楽天のアプリ ID かアクセスキーが設定に無いので、楽天には問い合わせない');
        }

        if ($refreshLimit !== null) {
            $this->refresh($refresher, $refreshLimit);
        }
        if ($refreshLimit !== null && $newLimit !== null) {
            // 取り直しの最後の呼び出しと、新しく探す最初の呼び出しのあいだを、Amazon・楽天とも 1 秒空ける
            Sleep::for(AffiliateCollector::INTERVAL_MILLISECONDS)->milliseconds();
        }
        if ($newLimit !== null) {
            $this->collectNew($collector, $newLimit);
        }

        return self::SUCCESS;
    }

    private function refresh(AffiliateRefresher $refresher, int $limit): void
    {
        $outcomes = $refresher->refresh($limit, fn (RefreshOutcome $outcome) => $this->reportRefresh($outcome));

        foreach ([RefreshOutcome::AMAZON => 'Amazon', RefreshOutcome::RAKUTEN => '楽天'] as $provider => $label) {
            $mine = array_values(array_filter($outcomes, fn (RefreshOutcome $outcome) => $outcome->provider === $provider));
            $counts = array_count_values(array_map(fn (RefreshOutcome $outcome) => $outcome->status, $mine));
            $this->info(sprintf(
                '取り直した(%s): %d 件(保存 %d・ID を消した %d(うち行を消した %d)・失敗 %d・次の回にまわした %d)',
                $label,
                count($mine),
                $counts[RefreshOutcome::REFRESHED] ?? 0,
                $counts[RefreshOutcome::CLEARED] ?? 0,
                count(array_filter($mine, fn (RefreshOutcome $outcome) => $outcome->rowDeleted)),
                $counts[RefreshOutcome::FAILED] ?? 0,
                $counts[RefreshOutcome::RETRY_LATER] ?? 0,
            ));
        }
    }

    private function collectNew(AffiliateCollector $collector, int $limit): void
    {
        $outcomes = $collector->collectNew($limit, fn (CollectOutcome $outcome) => $this->reportNew($outcome));

        // 行の無いノード・銘柄(#248)と、行のある行の探し直し(#696)を分けて数える
        [$new, $research] = collect($outcomes)
            ->partition(fn (CollectOutcome $outcome) => $outcome->target->affiliateId === null)
            ->map(fn ($part) => array_count_values($part->map(fn (CollectOutcome $outcome) => $outcome->status)->all()))
            ->all();
        $this->info(sprintf(
            '探した: %d 件(保存 %d・行ができていた %d・失敗 %d・次の回にまわした %d)',
            array_sum($new),
            $new[CollectOutcome::SAVED] ?? 0,
            $new[CollectOutcome::ALREADY_EXISTS] ?? 0,
            $new[CollectOutcome::FAILED] ?? 0,
            $new[CollectOutcome::RETRY_LATER] ?? 0,
        ));
        $this->info(sprintf(
            '探し直した: %d 件(保存 %d・行が変わっていた %d・次の回にまわした %d)',
            array_sum($research),
            $research[CollectOutcome::RESEARCHED] ?? 0,
            $research[CollectOutcome::CHANGED] ?? 0,
            $research[CollectOutcome::RETRY_LATER] ?? 0,
        ));
    }

    /**
     * --limit の値。省いたら設定の値を使う。どちらも 1 以上の整数でなければ null。
     */
    private function limit(string $configKey, int $default): ?int
    {
        $value = $this->option('limit') ?? config($configKey, $default);
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,5}\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function reportRefresh(RefreshOutcome $outcome): void
    {
        $target = $outcome->describe();
        match ($outcome->status) {
            RefreshOutcome::REFRESHED => $this->line("{$target}: 取り直した"),
            RefreshOutcome::CLEARED => $this->line($outcome->rowDeleted
                ? "{$target}: ID で取れなかったので ID と値を消し、ほかに値が無いので行も消した(次の --new で名前から探し直す)"
                : "{$target}: ID で取れなかったので ID と値を消した"),
            RefreshOutcome::FAILED => $this->warn("{$target}: 失敗したので飛ばす({$outcome->message})"),
            RefreshOutcome::RETRY_LATER => $this->warn("{$target}: 上限か一時的な失敗のため、次の回にまわす({$outcome->message})"),
        };
    }

    /**
     * 探し直した提供元 1 つの結果の書き方。見つけた ID か、見つからなかった理由。探していなければ「探していない」。
     */
    private static function researched(bool $searched, ?string $itemId, ?AffiliateNotFoundReason $reason): string
    {
        if (! $searched) {
            return '探していない';
        }

        return $itemId ?? '見つからない('.($reason !== null ? $reason->value : '理由なし').')';
    }

    private function reportNew(CollectOutcome $outcome): void
    {
        $target = $outcome->target->describe();
        match ($outcome->status) {
            CollectOutcome::SAVED => $this->line(sprintf(
                '%s: 保存した(Amazon: %s・楽天: %s)',
                $target,
                $outcome->amazonAsin ?? '見つからない',
                $outcome->rakutenItemCode ?? '見つからない',
            )),
            CollectOutcome::RESEARCHED => $this->line(sprintf(
                '%s: 探し直した(Amazon: %s・楽天: %s)',
                $target,
                self::researched($outcome->target->searchAmazon, $outcome->amazonAsin, $outcome->amazonReason),
                self::researched($outcome->target->searchRakuten, $outcome->rakutenItemCode, $outcome->rakutenReason),
            )),
            CollectOutcome::ALREADY_EXISTS => $this->line("{$target}: 探しているあいだに行ができていたので、書き換えない"),
            CollectOutcome::CHANGED => $this->line("{$target}: 探し直しているあいだに行が変わった(ID が入った・行が消えた)ので、書き換えない"),
            CollectOutcome::FAILED => $this->warn($outcome->retryAfter !== null
                ? "{$target}: 失敗したので飛ばす({$outcome->message})。{$outcome->retryAfter->toDateTimeString()} までは選ばない"
                : "{$target}: 失敗したので飛ばす({$outcome->message})"),
            CollectOutcome::RETRY_LATER => $this->warn("{$target}: 上限か一時的な失敗のため、次の回にまわす({$outcome->message})"),
        };
    }
}
