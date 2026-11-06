<?php

namespace App\Services\Affiliate;

use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

/**
 * 楽天市場の商品検索 API(docs/rakuten-api.md)で、商品を名前で探す・商品のコード(itemCode)で 1 件を取り直す。
 * 決めたこと・旧(App\Classes\Factory\RakutenFactory)との差分は docs/decisions/rakuten-client.md。
 *
 * アプリ ID・アクセスキー・アフィリエイト ID は、画面から変える設定(App\Support\Settings。#69)から読む。
 * アプリ ID かアクセスキーが無いあいだは API を使えないので、どこにも問い合わせない。
 *
 * 呼べるのはアプリ ID ごとに 1 秒に 1 回まで(docs/rakuten-api.md の 5)。同じクライアントからの呼び出しは、
 * 前の呼び出しから MIN_INTERVAL_MILLISECONDS ミリ秒空くまで待ってから送る。
 */
class RakutenClient
{
    public const SETTING_APPLICATION_ID = 'rakuten.application_id';

    public const SETTING_ACCESS_KEY = 'rakuten.access_key';

    /** 旧(RakutenFactory::generateURL)がいつも付けていた、楽天市場のジャンル「ビール・洋酒」 */
    public const LIQUOR_GENRE_ID = '510915';

    public const MIN_INTERVAL_MILLISECONDS = 1000;

    /** 2026-05-14 から使える呼び出し先と版(docs/rakuten-api.md の 1・2) */
    private const SEARCH_URL = 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260701';

    private const TIMEOUT_SECONDS = 10;

    /** 検索語の空白(半角・全角)。旧は、1 回目が失敗したら、これを取り除いた語でやり直していた */
    private const KEYWORD_SPACES = [' ', '　'];

    private ?int $lastRequestAtMs = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Http $http,
    ) {}

    /**
     * 語で商品を探す。返るのは検索結果の先頭の 1 ページ(楽天の既定の件数)を、楽天が返した順に並べたもの。
     * 販売できる商品だけを探す(楽天の既定。旧と同じ)。
     *
     * 語に空白があって 400 が返ったら、旧と同じく空白を取り除いた語で 1 回だけやり直す(楽天は、空白で区切った
     * 語の 1 つ 1 つが短すぎると受け付けない)。
     *
     * アプリ ID かアクセスキーが無ければ、問い合わせずに null を返す。1 件も無ければ空の一覧を返す。
     *
     * @param  string|null  $genreId  ジャンルで絞る。null なら絞らない(洋酒でないものを探すとき。#248 の AffiliateCollector は旧に合わせていつも絞る)
     * @return list<RakutenItem>|null
     *
     * @throws RakutenRetryLaterException
     * @throws RakutenApiException
     */
    public function searchItems(string $keyword, ?string $genreId = self::LIQUOR_GENRE_ID): ?array
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return null;
        }

        $params = ['keyword' => $keyword] + ($genreId === null ? [] : ['genreId' => $genreId]);
        $response = $this->send($credentials, $params);

        $trimmed = str_replace(self::KEYWORD_SPACES, '', $keyword);
        if ($response->status() === 400 && $trimmed !== $keyword && $trimmed !== '') {
            $response = $this->send($credentials, ['keyword' => $trimmed] + $params);
        }

        return self::items(self::body($response));
    }

    /**
     * 商品のコード(`shop:1234` の形)を指定して 1 件を取り直す。売り切れた商品も取る(available が false になる)。
     *
     * 見つからない(404・0 件・別のコードの商品だけが返る)ときは null を返す。呼ぶ側は、販売が終わったものとして
     * コードを消してよい(#249)。
     *
     * @throws RakutenNotConfiguredException アプリ ID かアクセスキーが無いとき。見つからない(null)と区別するため
     *                                       投げる。呼ぶ前に isConfigured() で確かめる
     * @throws RakutenRetryLaterException
     * @throws RakutenApiException
     */
    public function getItem(string $itemCode): ?RakutenItem
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            throw new RakutenNotConfiguredException('楽天のアプリ ID かアクセスキーが設定に無い');
        }

        $items = self::items(self::body($this->send($credentials, [
            'itemCode' => $itemCode,
            'availability' => '0',
        ])));
        foreach ($items as $item) {
            if ($item->itemCode === $itemCode) {
                return $item;
            }
        }

        return null;
    }

    /**
     * アプリ ID とアクセスキーがそろっているか。
     */
    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * @return array{applicationId: string, accessKey: string, affiliateId: string|null}|null
     */
    private function credentials(): ?array
    {
        $applicationId = $this->settings->get(self::SETTING_APPLICATION_ID);
        $accessKey = $this->settings->get(self::SETTING_ACCESS_KEY);
        if ($applicationId === null || $applicationId === '' || $accessKey === null || $accessKey === '') {
            return null;
        }
        $affiliateId = $this->settings->get(AffiliateSearchUrls::SETTING_RAKUTEN_AFFILIATE_ID);

        return [
            'applicationId' => $applicationId,
            'accessKey' => $accessKey,
            'affiliateId' => $affiliateId === null || $affiliateId === '' ? null : $affiliateId,
        ];
    }

    /**
     * アクセスキーは、URL(アクセスのログに残りうる)に載せないよう、ヘッダーで送る(どちらでもよいと公式にある)。
     *
     * つながらないときの例外の文には、楽天の URL(アプリ ID が入る)を含みうる元の例外の文を入れない。
     *
     * @param  array{applicationId: string, accessKey: string, affiliateId: string|null}  $credentials
     * @param  array<string, string>  $params
     *
     * @throws RakutenRetryLaterException
     */
    private function send(array $credentials, array $params): Response
    {
        $query = [
            'applicationId' => $credentials['applicationId'],
            'formatVersion' => '2',
            ...($credentials['affiliateId'] === null ? [] : ['affiliateId' => $credentials['affiliateId']]),
            ...$params,
        ];

        $this->waitForInterval();
        try {
            return $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['accessKey' => $credentials['accessKey']])
                ->acceptJson()
                ->get(self::SEARCH_URL, $query);
        } catch (ConnectionException) {
            throw new RakutenRetryLaterException('楽天の商品検索 API に接続できない');
        } finally {
            $this->lastRequestAtMs = (int) now()->getPreciseTimestamp(3);
        }
    }

    /**
     * 前の呼び出しから MIN_INTERVAL_MILLISECONDS ミリ秒たっていなければ、たつまで待つ。
     */
    private function waitForInterval(): void
    {
        if ($this->lastRequestAtMs === null) {
            return;
        }
        $waitMs = $this->lastRequestAtMs + self::MIN_INTERVAL_MILLISECONDS - (int) now()->getPreciseTimestamp(3);
        if ($waitMs > 0) {
            Sleep::for($waitMs)->milliseconds();
        }
    }

    /**
     * 応答の本文を返す。対象のデータが無い 404(not_found)は、0 件として空の本文を返す。
     *
     * 429(上限)・5xx(全体の上限・メンテナンス)は、時間を置けば通りうるので、それ以外の失敗(パラメーターの誤り・
     * アプリ ID やアクセスキーの誤り)と分ける。
     *
     * @return array<mixed>
     *
     * @throws RakutenRetryLaterException
     * @throws RakutenApiException
     */
    private static function body(Response $response): array
    {
        $status = $response->status();
        $error = self::stringAt($response->json(), 'error') ?? '';
        if ($status === 404 && $error === 'not_found') {
            return [];
        }

        if (! $response->successful()) {
            $message = "楽天の商品検索 API が {$status} {$error} を返した";
            if ($status === 429 || $response->serverError()) {
                throw new RakutenRetryLaterException($message);
            }

            throw new RakutenApiException($message, $status, $error === '' ? null : $error);
        }

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['Items'] ?? null)) {
            throw new RakutenApiException("楽天の商品検索 API が {$status} で、読めない応答(JSON でない・Items が無い)を返した", $status);
        }

        return $body;
    }

    /**
     * formatVersion=2 の応答では、Items は商品の連想配列の一覧(formatVersion=1 の Item の入れ子が無い)。
     *
     * @param  array<mixed>  $body
     * @return list<RakutenItem>
     */
    private static function items(array $body): array
    {
        $items = [];
        $list = $body['Items'] ?? null;
        foreach (is_array($list) ? $list : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemCode = self::stringAt($item, 'itemCode');
            if ($itemCode === null) {
                continue;
            }
            $items[] = self::item($itemCode, $item);
        }

        return $items;
    }

    /**
     * 旧(RakutenFactory::generateFromWord)が取っていた項目を、旧と同じ取り方で取る。
     * - 商品のページは affiliateUrl(アフィリエイト ID を渡したときだけ入る)。無ければ itemUrl
     * - 画像は、画像があるとき(imageFlag が 1)だけ、64×64 の画像(smallImageUrls)の先頭の 1 枚から、大きさを
     *   指定する `?_ex=` 以降を取り除いた URL(元の大きさの画像)
     *
     * @param  array<mixed>  $item
     */
    private static function item(string $itemCode, array $item): RakutenItem
    {
        $price = $item['itemPrice'] ?? null;
        $availability = $item['availability'] ?? null;

        return new RakutenItem(
            itemCode: $itemCode,
            title: self::stringAt($item, 'itemName'),
            price: is_int($price) || (is_string($price) && ctype_digit($price)) ? (int) $price : null,
            detailUrl: self::stringAt($item, 'affiliateUrl') ?? self::stringAt($item, 'itemUrl'),
            imageUrl: self::imageUrl($item),
            available: is_numeric($availability) ? (int) $availability === 1 : null,
        );
    }

    /**
     * @param  array<mixed>  $item
     */
    private static function imageUrl(array $item): ?string
    {
        $flag = $item['imageFlag'] ?? null;
        $urls = $item['smallImageUrls'] ?? null;
        if (! is_numeric($flag) || (int) $flag !== 1 || ! is_array($urls)) {
            return null;
        }
        $first = $urls[0] ?? null;
        if (! is_string($first) || $first === '') {
            return null;
        }
        $position = strpos($first, '?_ex=');

        return $position === false ? $first : substr($first, 0, $position);
    }

    private static function stringAt(mixed $value, string $key): ?string
    {
        $child = is_array($value) ? ($value[$key] ?? null) : null;

        return is_string($child) && $child !== '' ? $child : null;
    }
}
