<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SPA の HTML に入れる、アクセス解析(GA4)のタグの測定 ID(#59。決めたことは docs/decisions/analytics.md)。
 *
 * 測定 ID は画面から変える設定(Settings。#69)から読み、コードに書かない。未設定ならタグを出さない。
 * 画面の移動ごとの page_view は SPA が送る(frontend の usePageViewTracking)ので、タグは最初の page_view を送らない。
 */
final class Ga4Tag
{
    public const SETTING_MEASUREMENT_ID = 'ga4.measurement_id';

    /**
     * 測定 ID の形。「G-」に英大文字と数字が続く。HTML の属性と script の中に入れるので、この形のものだけを出す
     * (画面で保存する値は SettingDefinitions で検査するが、.env の値は検査を通らないため、出すときにも確かめる)。
     */
    public const MEASUREMENT_ID_PATTERN = '/\AG-[0-9A-Z]+\z/';

    /** 測定 ID の長さの上限(管理画面で保存するときの検査にも使う) */
    public const MEASUREMENT_ID_MAX_LENGTH = 30;

    public function __construct(private readonly Settings $settings) {}

    /**
     * このリクエストの HTML にタグを入れるなら測定 ID、入れないなら null。
     *
     * 管理画面(/admin 以下)には入れない。管理者の操作を、公開ページのアクセスの計測に混ぜないため。
     *
     * 設定を読めないとき(DB に繋がらない・表がまだ無い)は、タグを入れずに HTML を返す。計測のためだけに、
     * DB を引かない画面の HTML まで 500 にしないため。読めなかったことはログに残す。
     */
    public function measurementIdFor(Request $request): ?string
    {
        if ($request->is('admin', 'admin/*')) {
            return null;
        }

        try {
            $id = $this->settings->get(self::SETTING_MEASUREMENT_ID);
        } catch (QueryException) {
            Log::warning('設定を読めないので、GA4 のタグを入れない', ['key' => self::SETTING_MEASUREMENT_ID]);

            return null;
        }

        if ($id === null || $id === '') {
            return null;
        }

        if (strlen($id) > self::MEASUREMENT_ID_MAX_LENGTH || preg_match(self::MEASUREMENT_ID_PATTERN, $id) !== 1) {
            Log::warning('GA4 の測定 ID の形が違うので、タグを入れない', ['key' => self::SETTING_MEASUREMENT_ID]);

            return null;
        }

        return $id;
    }
}
