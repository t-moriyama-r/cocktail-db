<?php

namespace App\Console\Commands;

use App\Services\Affiliate\AffiliateCollector;
use App\Services\Affiliate\AmazonClient;
use App\Services\Affiliate\CollectOutcome;
use App\Services\Affiliate\RakutenClient;
use Illuminate\Console\Command;

/**
 * アフィリエイトの商品を集める(旧の AffiliateController@main。旧は毎分動いていた)。
 *
 * --new: まだ商品の行が無いノード・銘柄を名前で探し、見つけた商品の ID と値を保存する(#248。中身は AffiliateCollector)。
 *   API の失敗が続く件は、しばらく選ばずに後ろの件へ進む(#695)。
 * ID での取り直し(--refresh)と定期実行の登録は #249 で足す。
 */
final class CollectAffiliates extends Command
{
    protected $signature = 'affiliates:collect
        {--new : まだ商品の行が無いノード・銘柄を、名前で探して保存する}
        {--limit= : 1 回に探す件数(既定は '.AffiliateCollector::DEFAULT_NEW_LIMIT.')}';

    protected $description = 'アフィリエイトの商品を Amazon・楽天から集める(--new: まだ行の無いノード・銘柄を名前で探す)';

    public function handle(AffiliateCollector $collector, AmazonClient $amazon, RakutenClient $rakuten): int
    {
        if (! $this->option('new')) {
            $this->error('--new を指定する');

            return self::INVALID;
        }

        $limit = $this->limit();
        if ($limit === null) {
            $this->error('--limit は 1 以上の整数で指定する');

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

        $outcomes = $collector->collectNew($limit, fn (CollectOutcome $outcome) => $this->report($outcome));

        $counts = array_count_values(array_map(fn (CollectOutcome $outcome) => $outcome->status, $outcomes));
        $this->info(sprintf(
            '探した: %d 件(保存 %d・行ができていた %d・失敗 %d・次の回にまわした %d)',
            count($outcomes),
            $counts[CollectOutcome::SAVED] ?? 0,
            $counts[CollectOutcome::ALREADY_EXISTS] ?? 0,
            $counts[CollectOutcome::FAILED] ?? 0,
            $counts[CollectOutcome::RETRY_LATER] ?? 0,
        ));

        return self::SUCCESS;
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');
        if ($value === null) {
            return AffiliateCollector::DEFAULT_NEW_LIMIT;
        }
        if (preg_match('/\A[1-9][0-9]{0,5}\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function report(CollectOutcome $outcome): void
    {
        $target = $outcome->target->describe();
        match ($outcome->status) {
            CollectOutcome::SAVED => $this->line(sprintf(
                '%s: 保存した(Amazon: %s・楽天: %s)',
                $target,
                $outcome->amazonAsin ?? '見つからない',
                $outcome->rakutenItemCode ?? '見つからない',
            )),
            CollectOutcome::ALREADY_EXISTS => $this->line("{$target}: 探しているあいだに行ができていたので、書き換えない"),
            CollectOutcome::FAILED => $this->warn($outcome->retryAfter !== null
                ? "{$target}: 失敗したので飛ばす({$outcome->message})。{$outcome->retryAfter->toDateTimeString()} までは選ばない"
                : "{$target}: 失敗したので飛ばす({$outcome->message})"),
            CollectOutcome::RETRY_LATER => $this->warn("{$target}: 上限か一時的な失敗のため、次の回にまわす({$outcome->message})"),
        };
    }
}
