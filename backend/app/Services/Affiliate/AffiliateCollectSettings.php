<?php

namespace App\Services\Affiliate;

use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * アフィリエイトの商品の収集(affiliates:collect)の間隔と 1 回の件数の設定(#826)。
 *
 * 値は画面から変える設定(Settings。#69)から読む。画面で保存した値があればそれ、無ければ .env の値
 * (config/settings.php)。どちらにも無ければ既定値(docs/decisions/affiliate-freshness.md の 5)。
 * 画面で保存するときの検査は SettingDefinitions(間隔は 1〜59、件数は 1〜MAX_LIMIT の整数。docs/decisions/settings.md)。
 */
final class AffiliateCollectSettings
{
    public const SETTING_INTERVAL_MINUTES = 'affiliates.interval_minutes';

    public const SETTING_REFRESH_LIMIT = 'affiliates.refresh_limit';

    public const SETTING_NEW_LIMIT = 'affiliates.new_limit';

    /** 定期実行の間隔の既定(分) */
    public const DEFAULT_INTERVAL_MINUTES = 10;

    /** 定期実行の間隔の上限(分)。cron の分の欄に「毎 N 分」で書くので、1 時間の中に収める */
    public const MAX_INTERVAL_MINUTES = 59;

    /**
     * 画面で保存できる 1 回の件数の上限。楽天は 1 秒に 1 回なので、取り直し 100 件・新しく探す 100 件でも 1 回は数分で終わり、
     * 重ねて流さない印(routes/console.php の 30 分)より十分短い。
     */
    public const MAX_LIMIT = 100;

    public function __construct(private readonly Settings $settings) {}

    /**
     * 定期実行の間隔(分)。1〜59 の整数でなければ、既定の 10 分にする。
     *
     * routes/console.php は artisan のどのコマンドでも読まれる(migrate の前、DB に繋がらないときも)ので、
     * 設定を読めないとき(DB に繋がらない・表がまだ無い)は、.env の値を使う。読めなかったことはログに残す。
     */
    public function intervalMinutes(): int
    {
        try {
            $value = $this->settings->get(self::SETTING_INTERVAL_MINUTES);
        } catch (QueryException) {
            Log::warning('設定を読めないので、収集の間隔は .env の値を使う', ['key' => self::SETTING_INTERVAL_MINUTES]);
            $value = $this->settings->fromEnv(self::SETTING_INTERVAL_MINUTES);
        }

        $interval = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_INTERVAL_MINUTES]]);

        return $interval === false ? self::DEFAULT_INTERVAL_MINUTES : $interval;
    }

    /**
     * --limit を省いたときの件数の、設定の値(検査する前の文字)。どこにも無ければ $default。
     */
    public function limit(string $key, int $default): string
    {
        return $this->settings->get($key) ?? (string) $default;
    }
}
