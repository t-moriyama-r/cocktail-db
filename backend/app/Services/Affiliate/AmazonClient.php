<?php

namespace App\Services\Affiliate;

use App\Support\Settings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * Amazon の Creators API(docs/amazon-creators-api.md)で、日本(amazon.co.jp)の商品を探す・取る。
 *
 * 認証情報とパートナータグは、画面から変える設定(App\Support\Settings。#69)から読む。どれかが無いあいだは
 * Creators API を使えないので、どこにも問い合わせない。
 *
 * アクセストークンは 1 時間有効で、公式の資料が「期限が近づくまで使い回す(プロセスのあいだでも共有する)」よう
 * 求めているため、キャッシュに置いて使い回す。期限ちょうどまで使うと、送っているあいだに切れて 401 になりうるので、
 * 期限の TOKEN_REFRESH_MARGIN_SECONDS 秒前からは取り直す。
 */
class AmazonClient
{
    public const SETTING_CREDENTIAL_ID = 'amazon.credential_id';

    public const SETTING_CREDENTIAL_SECRET = 'amazon.credential_secret';

    public const SETTING_PARTNER_TAG = 'amazon.partner_tag';

    /** GetItems に 1 回で渡せる ASIN の数(公式の上限) */
    public const MAX_ITEM_IDS = 10;

    /** 旧(AmazonFactory::generateFromWord)が探していた分類。食料品 */
    public const DEFAULT_SEARCH_INDEX = 'GroceryAndGourmetFood';

    public const TOKEN_REFRESH_MARGIN_SECONDS = 60;

    /** 日本のストアの資格情報(Version 3.3。FE)のトークンの取得先 */
    private const TOKEN_URL = 'https://api.amazon.co.jp/auth/o2/token';

    private const API_BASE_URL = 'https://creatorsapi.amazon/catalog/v1';

    private const MARKETPLACE = 'www.amazon.co.jp';

    private const TIMEOUT_SECONDS = 10;

    /** 旧が取っていた項目(商品名・画像 3 種・価格。詳細と検索の URL は指定しなくても返る) */
    private const RESOURCES = [
        'itemInfo.title',
        'images.primary.small',
        'images.primary.medium',
        'images.primary.large',
        'offersV2.listings.price',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly Cache $cache,
        private readonly Http $http,
    ) {}

    /**
     * キーワードで商品を探す。返るのは検索結果の先頭の 1 ページ(10 件まで)。
     *
     * 認証情報が無ければ問い合わせずに null を返す。
     *
     * @throws AmazonRetryLaterException
     * @throws AmazonApiException
     */
    public function searchItems(string $keywords, string $searchIndex = self::DEFAULT_SEARCH_INDEX): ?AmazonSearchResult
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return null;
        }

        $body = $this->post($credentials, 'searchItems', [
            'keywords' => $keywords,
            'searchIndex' => $searchIndex,
        ]);
        // 1 件も見つからないときは 404 が返る(下の post)ので、検索結果の無い本文は 0 件として読む
        $result = self::arrayAt($body, 'searchResult');
        $searchUrl = self::stringAt($result, 'searchURL');

        return new AmazonSearchResult(self::items($result), $searchUrl);
    }

    /**
     * ASIN を指定して商品を取る。取れなかった ASIN は結果に入らず、並びは頼んだ順と一致しない
     * (公式の資料。各商品は asin で突き合わせる)。
     *
     * 認証情報が無い・ASIN が空なら、問い合わせずに空を返す。
     *
     * @param  list<string>  $asins
     * @return list<AmazonItem>
     *
     * @throws AmazonRetryLaterException
     * @throws AmazonApiException
     * @throws InvalidArgumentException ASIN が MAX_ITEM_IDS 件を超えるとき
     */
    public function getItems(array $asins): array
    {
        if (count($asins) > self::MAX_ITEM_IDS) {
            throw new InvalidArgumentException('GetItems に一度に渡せる ASIN は '.self::MAX_ITEM_IDS.' 件まで: '.count($asins).' 件');
        }

        $credentials = $this->credentials();
        if ($credentials === null || $asins === []) {
            return [];
        }

        $body = $this->post($credentials, 'getItems', [
            'itemIds' => $asins,
            'itemIdType' => 'ASIN',
        ]);

        // 応答のキーは、公式の資料の中で itemResults と itemsResult の 2 通りに書かれている(docs/amazon-creators-api.md)
        $result = self::arrayAt($body, 'itemResults') ?? self::arrayAt($body, 'itemsResult');

        return self::items($result);
    }

    /**
     * 認証情報とパートナータグがそろっているか。
     */
    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * @return array{id: string, secret: string, partnerTag: string}|null
     */
    private function credentials(): ?array
    {
        $id = $this->settings->get(self::SETTING_CREDENTIAL_ID);
        $secret = $this->settings->get(self::SETTING_CREDENTIAL_SECRET);
        $partnerTag = $this->settings->get(self::SETTING_PARTNER_TAG);
        if ($id === null || $id === '' || $secret === null || $secret === '' || $partnerTag === null || $partnerTag === '') {
            return null;
        }

        return ['id' => $id, 'secret' => $secret, 'partnerTag' => $partnerTag];
    }

    /**
     * Creators API に POST して、応答の本文を返す。1 件も見つからない 404 は、空の本文として返す。
     *
     * 期限が切れたトークン(401 TokenExpired)は、取り直して 1 回だけ送り直す。キャッシュの期限より先に
     * Amazon 側で切れたとき(時計のずれなど)にも、次の回を待たずに取れるようにするため。
     *
     * @param  array{id: string, secret: string, partnerTag: string}  $credentials
     * @param  array<string, mixed>  $params
     * @return array<mixed>
     *
     * @throws AmazonRetryLaterException
     * @throws AmazonApiException
     */
    private function post(array $credentials, string $operation, array $params): array
    {
        $payload = $params + [
            'partnerTag' => $credentials['partnerTag'],
            'marketplace' => self::MARKETPLACE,
            'resources' => self::RESOURCES,
        ];

        $response = $this->send($operation, $payload, $this->accessToken($credentials));
        if ($response->status() === 401 && self::stringAt($response->json(), 'reason') === 'TokenExpired') {
            $this->cache->forget(self::tokenCacheKey($credentials));
            $response = $this->send($operation, $payload, $this->accessToken($credentials));
        }
        // TokenExpired 以外の 401(トークンが無効にされた など)でも、キャッシュに残すと期限まで毎回 401 になるので捨てる
        if ($response->status() === 401) {
            $this->cache->forget(self::tokenCacheKey($credentials));
        }

        if ($response->status() === 404 && self::stringAt($response->json(), 'type') === 'ResourceNotFoundException') {
            return [];
        }
        self::throwUnlessSuccessful($response, $operation);

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws AmazonRetryLaterException
     */
    private function send(string $operation, array $payload, string $token): Response
    {
        try {
            return $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->withHeaders(['x-marketplace' => self::MARKETPLACE])
                ->asJson()
                ->post(self::API_BASE_URL.'/'.$operation, $payload);
        } catch (ConnectionException $e) {
            throw new AmazonRetryLaterException("Creators API の {$operation} に接続できない: {$e->getMessage()}", null, $e);
        }
    }

    /**
     * キャッシュにある使えるトークンを返す。無い・期限の TOKEN_REFRESH_MARGIN_SECONDS 秒前を過ぎたなら取り直す。
     *
     * @param  array{id: string, secret: string, partnerTag: string}  $credentials
     *
     * @throws AmazonRetryLaterException
     * @throws AmazonApiException
     */
    private function accessToken(array $credentials): string
    {
        $key = self::tokenCacheKey($credentials);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            $token = self::stringAt($cached, 'accessToken');
            $refreshAt = $cached['refreshAt'] ?? null;
            if ($token !== null && is_int($refreshAt) && now()->getTimestamp() < $refreshAt) {
                return $token;
            }
        }

        try {
            $response = $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $credentials['id'],
                    'client_secret' => $credentials['secret'],
                    'scope' => 'creatorsapi::default',
                ]);
        } catch (ConnectionException $e) {
            throw new AmazonRetryLaterException("Creators API のトークンの取得先に接続できない: {$e->getMessage()}", null, $e);
        }
        self::throwUnlessSuccessful($response, 'token');

        $token = self::stringAt($response->json(), 'access_token');
        $expiresIn = $response->json('expires_in');
        if ($token === null || ! is_numeric($expiresIn)) {
            throw new AmazonApiException('Creators API のトークンの応答に access_token・expires_in が無い', $response->status());
        }

        $refreshAt = now()->getTimestamp() + (int) $expiresIn - self::TOKEN_REFRESH_MARGIN_SECONDS;
        // キャッシュの期限は判定に使わない(上の refreshAt で決める)。期限の切れたトークンを残さないためだけに付ける
        $this->cache->put($key, ['accessToken' => $token, 'refreshAt' => $refreshAt], (int) $expiresIn);

        return $token;
    }

    /**
     * 資格情報を画面で変えたら、前の資格情報のトークンを使わないよう、資格情報ごとに別のキーにする。
     *
     * @param  array{id: string, secret: string, partnerTag: string}  $credentials
     */
    private static function tokenCacheKey(array $credentials): string
    {
        return 'amazon-creators-api:token:'.hash('sha256', $credentials['id']."\0".$credentials['secret']);
    }

    /**
     * 429(上限)・5xx は、時間を置けば通りうる(公式の資料は間隔を空けてやり直すよう書いている)ので、
     * それ以外の失敗(資格情報の誤り・使える条件を満たしていない 403 など)と分ける。
     *
     * @throws AmazonRetryLaterException
     * @throws AmazonApiException
     */
    private static function throwUnlessSuccessful(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $type = self::stringAt($response->json(), 'type') ?? self::stringAt($response->json(), 'error') ?? '';
        $message = "Creators API の {$operation} が {$status} {$type} を返した";

        if ($status === 429 || $response->serverError()) {
            $retryAfter = $response->json('retryAfterSeconds') ?? $response->header('Retry-After');
            throw new AmazonRetryLaterException($message, is_numeric($retryAfter) ? (int) $retryAfter : null);
        }

        throw new AmazonApiException($message, $status);
    }

    /**
     * @param  array<mixed>|null  $result
     * @return list<AmazonItem>
     */
    private static function items(?array $result): array
    {
        $items = [];
        foreach (self::arrayAt($result, 'items') ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $asin = self::stringAt($item, 'asin');
            if ($asin === null) {
                continue;
            }
            $items[] = self::item($asin, $item);
        }

        return $items;
    }

    /**
     * 価格は、カートに出ている出品(offersV2.listings の先頭)の金額。出品が無ければ在庫切れなので null。
     *
     * @param  array<mixed>  $item
     */
    private static function item(string $asin, array $item): AmazonItem
    {
        $primary = self::arrayAt(self::arrayAt($item, 'images'), 'primary');
        $listings = self::arrayAt(self::arrayAt($item, 'offersV2'), 'listings');
        $listing = is_array($listings) && isset($listings[0]) && is_array($listings[0]) ? $listings[0] : null;
        $money = self::arrayAt(self::arrayAt($listing, 'price'), 'money');
        $amount = $money['amount'] ?? null;

        return new AmazonItem(
            asin: $asin,
            title: self::stringAt(self::arrayAt(self::arrayAt($item, 'itemInfo'), 'title'), 'displayValue'),
            detailPageUrl: self::stringAt($item, 'detailPageURL'),
            imageUrlSmall: self::stringAt(self::arrayAt($primary, 'small'), 'url'),
            imageUrlMedium: self::stringAt(self::arrayAt($primary, 'medium'), 'url'),
            imageUrlLarge: self::stringAt(self::arrayAt($primary, 'large'), 'url'),
            price: is_numeric($amount) ? (int) round((float) $amount) : null,
            displayPrice: self::stringAt($money, 'displayAmount'),
        );
    }

    /**
     * @return array<mixed>|null
     */
    private static function arrayAt(mixed $value, string $key): ?array
    {
        $child = is_array($value) ? ($value[$key] ?? null) : null;

        return is_array($child) ? $child : null;
    }

    private static function stringAt(mixed $value, string $key): ?string
    {
        $child = is_array($value) ? ($value[$key] ?? null) : null;

        return is_string($child) && $child !== '' ? $child : null;
    }
}
