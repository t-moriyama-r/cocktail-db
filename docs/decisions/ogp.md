# OGP・Twitter カード

#112 で決めたこと。SNS(X・Facebook・LINE・Slack など)のクローラーは JS を動かさないので、サーバーが返す SPA の HTML(`backend/resources/views/spa.blade.php`)に OGP(`og:*`)と Twitter カード(`twitter:*`)を入れる。値は `backend/app/Support/SocialCard.php` で作る。

出どころ:

- `og:description` を `<meta name="description">` と同じ切り方にすること: #111 のオーナーの判断(問い 1 の A。「#112 の `og:description` も同じ関数を通して同じ切り方にする」)
- 画像が無いときの既定の画像: #112 の受け入れ条件
- ほかは、旧と同じにした。旧と同じにできないところ・旧に無いところは、下の「旧との差分」に置いた

オーナーの判断(PR #584 のコメント「A」。問い 1・問い 2 とも A):

- 画像の無いカテゴリ・銘柄は、アフィリエイトの画像を使わず、既定の画像にする(Amazon の画像を保存・キャッシュしない決まり、`docs/decisions/affiliate-freshness.md` に触れないため)
- 既定の画像は、仮に置いた `backend/public/og-default.png` のまま進める。差し替えたくなったら、ファイルを置き換える

## 決まったこと

1. **詳細ページ(カクテル・カテゴリ・銘柄・用語)は、ページごとの値**を入れる。無い ID・下書きのカクテル(404)には、詳細でないページと同じサイト共通の値を入れる

   | タグ | 値 |
   |---|---|
   | `og:url` | canonical と同じ(`APP_URL` + そのページのパス。クエリなし。#111) |
   | `og:type` | `website` |
   | `og:title`・`twitter:title` | `名前 \| 宅飲みカクテルデータベース`(旧の SNS 向けの HTML と同じ形。`<title>` の `名前(英名) ｜ 宅飲みカクテルデータベース(RC)` とは違う。旧も違った) |
   | `og:description`・`twitter:description` | `<meta name="description">` と同じ文(`MetaDescription`。参照は名前に、タグを除き、改行は空白に、120 字を超えたら 119 字+「…」、空ならサイト共通の文) |
   | `og:image`・`twitter:image` | 画像の列がファイル名なら `APP_URL/storage/<ファイル名>`(パスの区切りごとに URL エンコード)、外部の URL ならそのまま、空なら既定の画像 `APP_URL/og-default.png` |
   | `twitter:card` | `summary` |

2. **詳細でないページ(トップ・一覧・検索・知らない URL など)は、サイト共通の値**を入れる

   | タグ | 値 |
   |---|---|
   | `og:url` | `APP_URL/`(旧と同じくトップを指す) |
   | `og:type` | `website` |
   | `og:title`・`twitter:title` | `宅飲みカクテルデータベース` |
   | `og:description`・`twitter:description` | `読んで楽しい、を目指したデータベース。` |
   | `og:image`・`twitter:image` | 既定の画像 `APP_URL/og-default.png` |
   | `twitter:card` | `summary_large_image` |

3. 既定の画像は 1200×630 の PNG。`summary` のカードは真ん中を正方形に切って小さく出すので、文字は真ん中の 630px の幅に収める

## 旧との差分

テストの表では `差分あり(意図: docs/decisions/ogp.md)` としてここを指す。

- 値を出す相手: 旧は Facebook・X・Pinterest のクローラーだけを、Web サーバーの書き換え(User-Agent を見る)で SNS 向けの HTML(`/card/<種類>/<ID>`)に回してページごとの値を出し、ほかのクローラー(LINE・Slack など)にはサイト共通の値を出していた → 新は User-Agent によらず、SPA の HTML にページごとの値を入れる
- 用語の詳細: 旧は SNS 向けの HTML に回さず、サイト共通の値を出していた → 新はほかの詳細と同じくページごとの値を入れる
- 詳細の `og:url`: 旧は `APP_URL/detail/<種類>/<ID>` を組み立てていた(カテゴリは `/detail/category/<ID>`) → 新は canonical と同じ(カテゴリは `/category/<ID>`)
- `og:description`: 旧は説明文の参照を名前にし、改行を `<br />` のまま入れ、字数で切らず、説明文が空なら空にしていた → 新は `<meta name="description">` と同じ文にする(#111 の問い 1 の A)
- 詳細の `og:image`: 旧は画像の列が空なら、カテゴリ・ミドル・銘柄はアフィリエイトの画像を使い、それも無ければ空にしていた。画像の列が外部の URL でも前に `storage/` を付けていた → 新は外部の URL はそのまま、空なら既定の画像にする(アフィリエイトの画像は使わない。オーナーの判断。上)
- 画像のファイル名: 旧はエンコードせずに URL にしていた(`asset()`) → 新はパスの区切りごとに URL エンコードする(空白や日本語の入ったファイル名でも、SNS が取りに来られる URL にするため)
- サイト共通の `og:image`・`twitter:image`: 旧は空 → 新は既定の画像
- 無い ID の詳細(SNS のクローラー): 旧は SNS 向けの HTML で、`og:title` をサイト名、`og:description` を「読んで楽しい、を目指して作ったお酒のデータベース。」、`og:url` を詳細の URL にしていた → 新は 404 で、詳細でないページと同じサイト共通の値
- `itemprop`(Google 向けの name・description・image): 旧は SNS 向けの HTML に出していた → 新は出さない(検索エンジン向けの title・description・canonical は #111 で入れた)
- 記号のエスケープ: 旧は名前・説明文をエスケープせずに HTML に入れていた → 新はエスケープする(名前や説明文に `"` や `<` があると HTML が壊れるため)

## 旧ソースで確かめた事実

本番の BE(`laravel13/`)と、本番のドキュメントルート(`production-public/`)を読んだ。旧システムを動かして確かめてはいない。

| どこ | 何をしているか |
|---|---|
| `production-public/.htaccess` | User-Agent が `facebookexternalhit`・`Twitterbot`・`Pinterest` で、パスが `detail/(cocktail\|brand\|middle\|category)/<数字>` なら `/card/<種類>/<ID>` に回す |
| `laravel13/routes/web.php` | `card/{command}/{id}` を `SNSCardController@display` に向けている |
| `laravel13/app/Http/Controllers/SNSCardController.php` | `og:url`・`og:type`(`website`)・`og:title`(`名前 \| 宅飲みカクテルデータベース`)・`og:description`(`Markup::generateDiscriptionStr`)・`og:image`・`twitter:card`(`summary`)・`twitter:title`・`twitter:description`・`twitter:image`・`itemprop` を、エスケープせずに出す。画像は列があれば `asset('storage/'.$image)`、無ければアフィリエイトの画像(カテゴリ・ミドルは有名な銘柄の行、銘柄は銘柄の行)。行が無ければ、サイト名と「読んで楽しい、を目指して作ったお酒のデータベース。」 |
| `laravel13/resources/views/welcome.blade.php` | 画面の HTML。`og:url` はトップ、`og:title` はサイト名、`og:description` は「読んで楽しい、を目指したデータベース。」、`og:image` は空、`twitter:card` は `summary_large_image` |
