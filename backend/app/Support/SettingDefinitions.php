<?php

namespace App\Support;

use App\Services\Affiliate\AffiliateSearchUrls;
use App\Services\Affiliate\AmazonClient;
use App\Services\Affiliate\RakutenClient;

/**
 * 管理画面から変えられる設定の一覧(#132。決めたことは docs/decisions/settings.md)。
 *
 * ここに無いキーは、管理 API で読むことも書くこともできない(422)。.env の値は config/settings.php から読むので、
 * 項目を足すときは両方に足す(キーがそろっていることはテストで確かめている)。
 *
 * APP_KEY・DB の接続・APP_URL は載せない。APP_KEY を変えると保存した値を復号できなくなり、DB の接続は
 * 設定を読む前に要るため(#69)。
 */
final class SettingDefinitions
{
    /** 秘密の値の形。空白・改行を含まない、表示できる ASCII の文字だけ */
    private const SECRET_RULES = ['regex:/\A[\x21-\x7E]+\z/', 'max:255'];

    /** リンクの URL に入る ID の形。英数字と「.」「_」「-」だけ */
    private const LINK_ID_RULES = ['regex:/\A[0-9A-Za-z._-]+\z/', 'max:100'];

    /**
     * 画面に並べる順。
     *
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        $definitions = [
            new SettingDefinition(
                AmazonClient::SETTING_CREDENTIAL_ID,
                'Amazon Creators API の Credential ID',
                'Amazon の商品を取る API の認証情報(Client ID)。Credential Secret・パートナータグとそろったときだけ API を呼ぶ',
                true,
                self::SECRET_RULES,
            ),
            new SettingDefinition(
                AmazonClient::SETTING_CREDENTIAL_SECRET,
                'Amazon Creators API の Credential Secret',
                'Amazon の商品を取る API の認証情報(Client Secret)',
                true,
                self::SECRET_RULES,
            ),
            new SettingDefinition(
                AmazonClient::SETTING_PARTNER_TAG,
                'Amazon のパートナータグ',
                'Amazon へのリンクと API の呼び出しに付けるトラッキング ID。空にすると、タグを付けずにリンクする',
                false,
                self::LINK_ID_RULES,
            ),
            new SettingDefinition(
                RakutenClient::SETTING_APPLICATION_ID,
                '楽天のアプリ ID',
                '楽天の商品を取る API(商品検索 API)のアプリ ID(applicationId)。アクセスキーとそろったときだけ API を呼ぶ',
                true,
                self::SECRET_RULES,
            ),
            new SettingDefinition(
                RakutenClient::SETTING_ACCESS_KEY,
                '楽天のアクセスキー',
                '楽天の商品を取る API(商品検索 API)のアクセスキー(accessKey)',
                true,
                self::SECRET_RULES,
            ),
            new SettingDefinition(
                AffiliateSearchUrls::SETTING_RAKUTEN_AFFILIATE_ID,
                '楽天のアフィリエイト ID',
                '楽天市場へのリンクと API の呼び出しに付ける ID。空にすると、アフィリエイトのリンクにせず検索結果・商品のページへ直接リンクする',
                false,
                self::LINK_ID_RULES,
            ),
        ];

        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
        }

        return $byKey;
    }

    public function find(string $key): ?SettingDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
