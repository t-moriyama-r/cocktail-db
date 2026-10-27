# Amazon Creators API

Amazon の商品の情報(商品名・画像・価格)を取るための API。PA-API 5(Product Advertising API 5.0)の後継で、PA-API 5 はもう使えない。この資料は、収集(#73)・表示の API(#47)・画面(#107)・情報をいつまで出してよいかの決まり(#247)を作るときに見る。

- 書いてあるのは、公式の資料で確かめたことだけ。確かめた日は **2026-10-26**。資料のページには更新日が書かれていないので、使う前に出典を開いて変わっていないかを確かめる
- 出典は、日本のアソシエイト・セントラルにある英語の資料(`https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/…`)。日本語の版は無い(`…/docs/ja-jp/…` は 404)。米国のサイト(`affiliate-program.amazon.com`)にも同じ内容がある
- 規約の文言は、出典の日本語をそのまま引いている
- 確かめていないことは、最後の「確かめていないこと」にまとめた

## 1. PA-API 5 との関係

- PA-API 5 は deprecated(廃止)で、Creators API に置き換わった。PA-API 5 を呼ぶと `403 Forbidden`(`AccessDeniedException`。メッセージは「Product Advertising API is deprecated. Please migrate to Creators API …」)が返る
- PA-API 5 の鍵(AWS の Access Key・Secret Key)は Creators API では使えない。Creators API に登録して、新しい資格情報を作る
- 主な違い

  | 項目 | PA-API 5 | Creators API |
  |---|---|---|
  | 認証 | AWS Signature Version 4 | OAuth 2.0(client credentials)。Bearer トークン |
  | エンドポイント | `webservices.amazon.com/paapi5/…` | `creatorsapi.amazon/catalog/v1/…` |
  | パラメータ名 | PascalCase(`ItemIds`) | lowerCamelCase(`itemIds`) |
  | 価格 | `Offers`(`Offers.Listings`・`Offers.Summaries`) | `Offers` は無い。`OffersV2` を使う |

出典:
- PA-API 5 Deprecation Notice: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/paapiv5-deprecation
- Migrating to Creators API from PA-API: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/migrating-to-creatorsapi-from-paapi

## 2. 使えるようになる条件

- 日本(Amazon.co.jp)のアソシエイト・プログラムに参加していて、最終の承認(final acceptance)を受けていること
- **直近 30 日に、条件を満たした売上(qualifying sales)が 10 件以上あること**。満たさないと `403` の `AccessDeniedException`(reason が `AssociateNotEligible`)が返る
- **売上の無い日が 30 日続くと、Creators API を使えなくなる**。紹介した売上が出荷されると、2 日以内に使えるようになる(使えないあいだも、SiteStripe などほかのリンクの作り方は使える)
- 登録できるのは、アソシエイトのアカウントの主(primary account owner)だけ。アソシエイト・セントラルの「ツール」→「Creators API」で、アプリケーションを作り、資格情報を作る
- アプリケーションは 1 ストアに 2 個まで、資格情報は 1 アプリケーションに 2 組まで。資格情報の secret は作ったときに一度しか出ない

出典:
- Welcome to Creators API(Prerequisites): https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/introduction
- Register for Creators API: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/onboarding/register-for-creators-api
- API Rates: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/api-rates
- Error Codes and Messages(`AssociateNotEligible`): https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/troubleshooting/error-codes-and-messages

## 3. 資格情報とトークンの取得先

資格情報は 3 つ: Credential ID(Client ID)・Credential Secret(Client Secret)・Version。Version はストアの地域で決まり、どのトークンの取得先を使うかだけを決める(呼べるマーケットプレイスは制限しない)。

| 地域 | Version | トークンの取得先 | マーケットプレイス |
|---|---|---|---|
| NA | 3.1 | `https://api.amazon.com/auth/o2/token` | US・CA・MX・BR |
| EU | 3.2 | `https://api.amazon.co.uk/auth/o2/token` | UK・DE・FR ほか |
| **FE** | **3.3** | **`https://api.amazon.co.jp/auth/o2/token`** | **JP**・SG・AU |

日本のストアなら FE(3.3)の `api.amazon.co.jp` から取る。取り方(Login with Amazon の client credentials):

```bash
curl -X POST https://api.amazon.co.jp/auth/o2/token \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "client_credentials",
    "client_id": "<Credential ID>",
    "client_secret": "<Credential Secret>",
    "scope": "creatorsapi::default"
  }'
```

- 返る `access_token` を `Authorization: Bearer <access_token>` に付けて API を呼ぶ
- トークンは 1 時間有効(`expires_in: 3600`)。呼ぶたびに取り直さず、期限が近づくまで使い回す(同じ Client ID を使うプロセスのあいだでも共有する)。毎回取ると、トークンの取得先への呼び出しが無駄になり、制限に当たることがある
- 期限が切れたトークンでは `401`(`UnauthorizedException`、reason が `TokenExpired`)が返る
- 資格情報は、クライアント側のコードや公開のリポジトリに置かない(公式の資料にもそう書いてある)。このリポジトリでは GitHub の Secret かサーバーの `.env` に置く。`App\Services\Affiliate\AmazonClient`(#135)は、`.env` の `AMAZON_CREDENTIAL_ID`・`AMAZON_CREDENTIAL_SECRET`・`AMAZON_PARTNER_TAG` か、管理画面の設定(`settings` 表。保存した値が `.env` より優先する)から読む

出典:
- Using cURL(Regional Endpoints・Token Generation): https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/get-started/using-curl
- Understanding Versions: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/understanding-versions
- Best Programming Practices(Cache access tokens until they expire): https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/best-programming-practices

## 4. 日本のマーケットプレイス

API の呼び出し先は、どの地域でも `https://creatorsapi.amazon`。日本を呼ぶときは、ヘッダとパラメータでマーケットプレイスを指定する。

| 項目 | 日本の値 |
|---|---|
| ヘッダ `x-marketplace`(必須) | `www.amazon.co.jp` |
| パラメータ `marketplace` | `www.amazon.co.jp` |
| パラメータ `partnerTag`(必須) | 日本のストアのトラッキング ID(値はこのリポジトリに書かない) |
| 言語の既定 | `ja_JP`(ほかに `en_US`・`zh_CN`) |
| 通貨の既定 | `JPY`(ほかは選べない) |

- `partnerTag` は、呼ぶマーケットプレイスのストアのもの。売上はこのタグで紐づくので、すべての呼び出しに付ける(付け忘れた分は後から付け直せない)
- 検索の分類(`searchIndex`)は日本用の一覧がある。お酒は `GroceryAndGourmetFood`(Food & Beverage)が近いが、お酒がここに入るかは確かめていない
- ほかのヘッダは `Content-Type: application/json` と `Authorization`。バージョンのヘッダは要らない

出典:
- Locale Information for JP Marketplace: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/locale-reference/japan
- Common Request Headers and Parameters: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/common-request-headers-and-parameters

## 5. GetItems(ASIN を指定して取る)

- `POST https://creatorsapi.amazon/catalog/v1/getItems`
- `itemIds`(必須): ASIN の配列。**1 回に 10 件まで**。`itemIdType` は `ASIN` だけ(既定も `ASIN`)
- `partnerTag`(必須)、`marketplace`、`resources`(返してほしいもの。既定は `itemInfo.title` だけ)、`languagesOfPreference`(1 つだけ)、`currencyOfPreference`、`condition`(`Any`・`New`)
- 取れない ASIN の扱いは、取れた件数で変わる(出典: `processing-of-errors`)
  - 一部だけ取れない: `200` で、取れた商品と、取れない ASIN の `errors` を返す(例: `ItemNotAccessible`「The ItemId … is not accessible through the Creators API.」)。そのため応答の並びは頼みの並びと一致しないことがある。各商品は `asin` の値で突き合わせる
  - すべて取れない: `404` `ResourceNotFoundException`(部分応答は無い。例のメッセージは `No items found for the requested item IDs.`)
  - すべて取れる: `200` で `errors` は無い
- 収集(#73)は、全件取れない `404` を致命的なエラーと区別して扱う(10 件まとめて頼んだ ASIN が全部売り切れ・取扱外でも、収集を止めない)
- 指定できる主な `resources`: `itemInfo.title`・`itemInfo.byLineInfo` など `itemInfo.*`、`images.primary.small|medium|large`・`images.variants.*`、`browseNodeInfo.*`、`offersV2.listings.*`(下の 7)、`parentASIN`

```json
{
  "itemIds": ["<ASIN>", "<ASIN>"],
  "itemIdType": "ASIN",
  "marketplace": "www.amazon.co.jp",
  "partnerTag": "<tag>",
  "resources": ["itemInfo.title", "images.primary.medium", "offersV2.listings.price", "offersV2.listings.availability"]
}
```

出典:
- GetItems: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/api-reference/operations/get-items
- エラーの扱い(部分応答・全件失敗): https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/troubleshooting/processing-of-errors
- エラーコードとメッセージ(404 の例): https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/troubleshooting/error-codes-and-messages

## 6. SearchItems(キーワードで探す)

- `POST https://creatorsapi.amazon/catalog/v1/searchItems`
- `keywords`・`actor`・`artist`・`author`・`brand`・`title` のどれか 1 つは必須。`partnerTag` も必須
- `searchIndex`(既定 `All`)か `browseNodeId` で分類をしぼれる
- 1 回で返るのは **10 件まで**: `itemCount`(1〜10、既定 10)と `itemPage`(1〜10、既定 1)で、検索結果の先頭から最大 100 件までをたどれる
- ほかに `availability`(既定 `Available`。`IncludeOutOfStock` で在庫切れも)、`condition`、`deliveryFlags`、`minPrice`・`maxPrice`(通貨の最小単位)、`minReviewsRating`、`minSavingPercent`、`sortBy`(`AvgCustomerReviews`・`Featured`・`NewestArrivals`・`Price:HighToLow`・`Price:LowToHigh`・`Relevance`)
- 応答は `searchResult` の下に `items`・`totalResultCount`・`searchURL`(Amazon の検索結果のページへのリンク)

出典:
- SearchItems: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/api-reference/operations/search-items

## 7. OffersV2(価格・在庫)

- 価格と在庫は `OffersV2` で取る。`resources` に `offersV2.listings.price`・`offersV2.listings.availability` などを指定する(GetItems・SearchItems・GetVariations で使える)
- OffersV2 が返すのは、Amazon の商品ページのカート(buybox)に出ている出品(featured listings)だけ。それが無ければ在庫切れとして返る。PA-API 5 にあった `Offers.Summaries`(最安値・最高値・出品数)や配送の情報(`DeliveryInfo`)・プライムの対象かは無い
- 価格は `offersV2.listings[].price.money` の `amount`(数値)・`currency`(`JPY`)・`displayAmount`(表示用の文字列)。ほかに `pricePerUnit`(単位あたりの価格)、`savingBasis`(割引の基準の価格。`savingBasisType` は `LIST_PRICE`・`LOWEST_PRICE`・`LOWEST_PRICE_STRIKETHROUGH`・`WAS_PRICE`)、`savings`(割引の額と率)
- **返る価格は、そのマーケットプレイスの国内の配送先を前提にした既定の値で、客が見る価格と違うことがある**
- 在庫は `availability.type`: `AVAILABLE_DATE`・`IN_STOCK`・`IN_STOCK_SCARCE`・`LEADTIME`・`OUT_OF_STOCK`・`PREORDER`・`UNAVAILABLE`・`UNKNOWN`
- `violatesMAP` が true の出品は、メーカーの最低広告価格(MAP)を下回るため、Amazon 上でもカートに入れるまで価格を出さないもの
- ほかに `condition`(新品・中古)、`merchantInfo`(販売者)、`isBuyBoxWinner`、`dealDetails`(セール)、`type`(`LIGHTNING_DEAL`・`SUBSCRIBE_AND_SAVE`)、`loyaltyPoints.points`(Amazon ポイント。日本だけ)
- 資料の応答の例は米国(`USD`・`$`)のもの。日本の応答の `displayAmount` の書式は確かめていない

出典:
- OffersV2: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/api-reference/resources/offersV2

## 8. 呼べる回数の上限

- 上限は 2 つ: **TPS**(1 秒あたりの回数)と **TPD**(1 日あたりの回数)。TPS に余裕があっても、TPD を使い切ると制限される
- **1 回の呼び出しが 1 件**。GetItems に ASIN を 10 件入れても 1 件と数える
- 資格情報を作ってから最初の 30 日は、**1 TPS・8640 TPD** まで
- その後は、Creators API のリンク経由で直近 30 日に出荷された売上で決まり、毎日計算し直される: 売上 **5 セントごとに 1 TPD**、**4,320 ドルごとに 1 TPS(最大 10 TPS)**。資料の金額はドルで、日本円でどう数えるかは書かれていない
- 上限を超えると `429`(`ThrottleException`)が返る。応答に待つ秒数 `retryAfterSeconds` が入ることがある。指数的に間隔を空けて(exponential backoff)やり直す
- 1 秒単位で数えるので、呼び出しは 1 日を通して均して送る
- 売上を正しく数えてもらうため、アカウントの主の資格情報で呼び、`partnerTag` を必ず付け、API が返したリンクの URL のパラメータを変えない(下の 10)

出典:
- API Rates: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/api-rates
- Best Programming Practices(Apply fine-grained request rate controls): https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/best-programming-practices
- Error Codes and Messages(ThrottleException): https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/troubleshooting/error-codes-and-messages

## 9. 規約: 日本で適用されるライセンス

Creators API の資料の「Read the License」は、マーケットプレイスごとのライセンスへのリンクを並べ、「The terms and conditions for each locale apply to any use of the Creators API in that locale.」(各ロケールの条件が、そのロケールでの Creators API の利用に適用される)と書いている。日本のリンク先は次のページ。

- **Amazon.co.jp Product Advertising API ライセンス契約**: https://affiliate.amazon.co.jp/help/operating/paapilicenseagreement

このページの題名と本文は、今も「Product Advertising API」の名前のままで、Creators API という言葉は出てこない。つまり、**日本での Creators API の利用には、Creators API の資料が日本のライセンスとして指している PA-API のライセンス契約が適用される**、というのが確かめられた範囲。Creators API 専用の日本のライセンスがあるかは見つけていない(下の「確かめていないこと」)。

以下の 9.1〜9.5 は、このライセンス契約の「利用要件」の項から引いた。ライセンス契約のほかに、アソシエイト・プログラム運営規約も読むよう資料に書かれている。

出典:
- Read the License: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/license-agreement

### 9.1 取った商品の情報を保存してよい期間

利用要件 (n) の原文:

> 乙は、画像で構成される商品関連コンテンツを保存またはキャッシュしてはいけませんが、画像で構成される商品関連コンテンツへのリンクについては最長24時間保存することができます。乙は、画像で構成されていない他の商品関連コンテンツを、データキャッシュの目的で、最長24時間保存することができますが、その場合、乙は、その後直ちに Product Advertising APIにリクエスト送信を行いまたは新しいデータフィードを取り込み、乙のサイト上の商品関連コンテンツを刷新することにより、商品関連コンテンツを直ちに刷新し、再表示しなければなりません。

- 画像でない情報(商品名・価格など): **最長 24 時間**。過ぎたら取り直して出し直す
- 画像の URL(リンク): **最長 24 時間**
- 画像そのもの: **保存もキャッシュもしてはいけない**
- (n) には続けて、サイトがクライアントアプリケーションを含む場合、そのクライアントアプリケーションは商品の情報を保存・キャッシュしてはいけない、とある
- 利用要件 (g): Amazon に出なくなった情報、または Amazon が使えなくなったと知らせた情報は、すぐにサイトから消す

出典: https://affiliate.amazon.co.jp/help/operating/paapilicenseagreement(利用要件 (n)・(g))

### 9.2 ASIN を持ってよい期間

利用要件 (n) の原文(9.1 の続き):

> 別途甲から通知がない限り、乙は、個別のAmazon Standard Identification Number（以下「ASIN」といいます。）を、本ライセンスの終了まで、期間の制限なく保存することができます。

- ASIN は、Amazon から別に知らせが無い限り、**ライセンスが終わるまで期限なく**持ってよい

出典: https://affiliate.amazon.co.jp/help/operating/paapilicenseagreement(利用要件 (n))

### 9.3 資料が勧めるキャッシュの時間(9.1 とは別のもの)

Creators API の資料の Best Programming Practices には、キャッシュの時間(TTL)の目安の表がある。「Creators API responses can be cached on your end under the guidelines defined in the License Agreement.」(ライセンス契約の決まりの範囲でキャッシュしてよい)とあり、そのうえでの目安として出ている。

| 項目 | キャッシュの時間 |
|---|---|
| Offers | 1 時間 |
| BrowseNodeInfo | 1 時間 |
| ほかのすべて(BrowseNodes・DetailPageURL・Images・ItemInfo など) | 1 日 |

- この表が守らなければならない決まりなのか、勧めにすぎないのかは、資料に書かれていない。9.1 の 24 時間はライセンス契約の決まりで、こちらは資料の勧め、として分けて扱う
- 表に書かれているのは「Offers」で、「OffersV2」とは書かれていない
- 同じ項に、Amazon から得た客の情報をキャッシュするのは規約違反、とある

出典: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/best-programming-practices(Use caches carefully)

### 9.4 価格を出すときの決まり(取得時刻と決まった文言)

利用要件 (o) の原文:

> 乙が商品関連コンテンツをデータフィードから取得する場合またはProduct Advertising APIへのリクエスト送信頻度もしくは乙のサイト上に表示される商品関連コンテンツの刷新頻度が1時間に1回以下の場合、乙は、乙のサイト上の価格情報または発送可能時期についての情報に隣接して、時刻/日付の表示を含めるものとします。ただし、アプリケーション上に表示される価格情報および発送可能時期の情報のリクエストおよび刷新と同日中は、表示のうち日付の部分を省略することができます。許容される表示の例は以下のとおりです。
>
> Amazon.co.jp 価格： ¥ 3,200 (2008年1月7日 14:11時点　−詳細はこちら−)
>
> Amazon.co.jp 価格： ¥ 3,200 (14:11 時点　−詳しくはこちら−)
>
> また、乙は、下記の免責文言を、価格情報または発送可能時期についての情報に隣接して表示するか、またはハイパーリンク、ポップアップ、スクリプト・ポップアップその他類似の方法で表示しなければなりません。
>
> 「価格および発送可能時期は表示された日付/時刻の時点のものであり、変更される場合があります。本商品の購入においては、購入の時点でAmazon.co.jpに表示されている価格および発送可能時期の情報が適用されます。」
>
> 乙は、上記の例において、「詳細はこちら」および「詳しくはこちら」をクリックすることにより、エンドユーザが免責文言を確認できるようにすることもできます。

- 決まりがかかる条件: データフィードから取る、**または取り直す(出し直す)のが 1 時間に 1 回以下**のとき
- そのとき、価格(か発送可能時期)の**すぐ横に、取った時刻と日付**を出す。取り直したのと同じ日のうちは、日付を省いてよい
- あわせて、上の**免責文言**を、価格の横に出すか、リンク・ポップアップなどで見られるようにする(例の「−詳細はこちら−」を押すと見られる形でもよい)

出典: https://affiliate.amazon.co.jp/help/operating/paapilicenseagreement(利用要件 (o))

### 9.5 商品の情報を出すときのほかの決まり

- 利用要件 (q): テキストの商品の情報を出すなら、利用者にはっきり見えるところに次の文言を出す(「本アプリケーション内」か「本サイト上」を選ぶ)

  > 「［「本アプリケーション内」／「本サイト上」］で表示されるコンテンツの一部は、アマゾンジャパン合同会社またはその関連会社により提供されたものです。これらのコンテンツは「現状有姿」で提供されており、随時変更または削除される場合があります。」

- 利用要件 (d): 商品の情報は、Amazon の関連するページ(その商品の詳細ページなど)にだけリンクさせる
- 利用要件 (f): 商品の情報に足したり削ったり変えたりしない(画像の縦横比を保った大きさの変更など、決まった例外はある)
- 利用要件 (p): 決められた 1 秒あたりの回数(契約の文面は「8」)を超えない。この 8 が何の数字かは契約から読み取れない。8 章の TPS(最初の 30 日は 1 TPS)とどちらが優先かは未確認。安全側として、収集(#73)は 8 章の TPS を超えない運用にする(8 を上限とは読まない)

出典: https://affiliate.amazon.co.jp/help/operating/paapilicenseagreement(利用要件 (d)・(f)・(p)・(q))

## 10. リンク(売上の紐づけ)

- API が返す `detailPageURL`(商品ページ)と `searchURL`(検索結果のページ)には、`partnerTag` が付いている。**URL のパラメータは変えずにそのまま使う**。変えると売上が紐づかず、紹介料を失う
- Amazon はこのタグで API の使い方を見て、上限を調整する

出典:
- API Rates: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/api-rates
- Best Programming Practices(Use your Partner tag correctly): https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/best-programming-practices

## 11. SDK

Node.js・Python・PHP・Java の SDK がある。トークンの取得と使い回し・リクエストの組み立てを SDK がする。配布は、資料のページからアーカイブをダウンロードして展開する形(PHP は展開した先で `composer install`)。Packagist に載っているかは確かめていない。

出典:
- Using SDK: https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/get-started/using-sdk

## 確かめていないこと

- Creators API 専用の日本のライセンスがあるか。見つけたのは、Creators API の資料が日本のライセンスとして指す PA-API のライセンス契約だけ(9)
- 9.3 のキャッシュの時間の表が、守らなければならない決まりか
- このサイトのアソシエイトのアカウントが、Creators API を使える条件(直近 30 日に 10 件)を満たしているか、実際の上限がいくつか
- 9.5 の利用要件 (p) の「8」が何の数字か(優先は未確認で、9.5 では 8 章の TPS を超えない運用にしている)
- 日本のマーケットプレイスで実際に呼んだときの応答(`displayAmount` の書式など)。資料の例はすべて米国のもの
- GetItems の応答のキーが、資料の中で `itemResults` と `itemsResult` の 2 通りに書かれている。どちらが正しいか
- GetItems の `errors` のコードが、資料の中で `ItemNotAccessible`・`InvalidParameterValue`・`ItemNotFound` と揺れている
- エラーの扱い(5 の部分応答・全件失敗)は英語版だけで確かめた。日本語版と、日本のマーケットプレイスでの実機の応答は未確認。`error-codes-and-messages` の GetItems の節自体は、全件失敗のときの HTTP ステータスを書いていない
- お酒がどの `searchIndex` に入るか
- SDK の版と、PHP の SDK を Composer のパッケージとして入れられるか
