# sitemap.xml と robots.txt

#114 で決めたこと。検索エンジンに詳細ページを見つけてもらうため、詳細ページの URL を並べた `sitemap.xml` を返し、`robots.txt` にその場所を書く。

## 決まったこと

1. `GET /sitemap.xml` に、詳細ページの URL を並べる(`backend/app/Services/Sitemap.php`)
   - 載せるもの: 下書きを除くカクテル(`/detail/cocktail/{id}`)・カテゴリ木のノード(`/category/{id}`)・銘柄(`/detail/brand/{id}`)・用語(`/dictionary/{id}`)。詳細ページのルート(#111。`DetailPageController`)が 200 で返すものと同じ
   - URL は `APP_URL` とパスをつないだもの(詳細ページの canonical と同じ形)
   - 並びはカクテル → カテゴリ → 銘柄 → 用語、それぞれ ID の昇順
   - `lastmod` に行の更新日時(`updated_at`)を W3C の日時の形で入れる。更新日時の無い行には付けない
2. 1 ファイルに載せる URL はサイトマップの決まり(sitemaps.org)の上限の 50,000 件まで
   - 50,000 件以下: `/sitemap.xml` が `urlset` で全部を載せる。`/sitemap-1.xml` などは 404
   - 50,000 件を超えたら: 上の並びで 50,000 件ずつ `/sitemap-1.xml`・`/sitemap-2.xml`…に分け、`/sitemap.xml` はそれらを並べたサイトマップインデックス(`sitemapindex`)にする。ファイルの数を超えた番号は 404
   - 下書きのカクテルは件数に数えない
3. `GET /robots.txt` は、旧と同じくどのページもクロールしてよいとし、`Sitemap: <APP_URL>/sitemap.xml` を足す。`APP_URL` は環境ごとに違うので、`public/robots.txt` の静的なファイルをやめて Laravel のルートで返す(静的なファイルがあると Web サーバーがそれを先に返す)
4. dev・stg も同じ中身を返す。dev・stg は Basic 認証がかかっていて、クローラーは読めない

## 旧との差分

テストの表では `差分あり(意図: docs/decisions/sitemap.md)` としてここを指す。

- `/robots.txt`: 旧は `User-agent: *` と `Disallow:` の 2 行だけを返した → 新は、その後に空行と `Sitemap: <APP_URL>/sitemap.xml` を足す
- `/sitemap.xml`: 旧は sitemap を持たず、画面の HTML を返した → 新は詳細ページの URL を並べた XML を返す(50,000 件を超えたらサイトマップインデックス)

## 旧ソースで確かめた事実

本番の BE(`laravel13/`)と本番の画面のビルド(`production-public/`)を読んだ。旧システムを動かして確かめてはいない(手元に旧の DB のダンプが無い)。

| どこ | 何をしているか |
|---|---|
| `production-public/robots.txt`・`laravel13/public/robots.txt` | `User-agent: *` と `Disallow:` の 2 行 |
| `laravel13/routes/web.php` | sitemap のルートは無い。最後の `Route::get('/{any}', …)->where('any', '.*')` がすべてのパスで画面の HTML(`welcome`)を返す |
| 旧ソース全体 | `sitemap` という語は、外から持ってきたアイコンのフォントのほかに出てこない |
