# 旧のカテゴリ・ミドルの詳細と、旧の管理画面・SNS カードの URL の転送

#78 で決めたこと(SNS カードの URL は #113)。新ではカテゴリとミドルの詳細を 1 つの画面(`/category/{ID}`)にまとめ、ノードの ID も振り直した(#29)。外から貼られたリンク・検索エンジンに残った旧の URL から来た人が、同じノードの新しいページに届くよう、旧の URL を 301 で移す。

## 決まったこと

1. 旧のカテゴリ・ミドルの詳細の URL を、GET で開くと、カテゴリ木を移した先のノードの詳細へ 301 で移す(`backend/routes/web.php`・`LegacyCategoryUrlController`。付け替えは `App\Support\LegacyCategoryUrl`)
   - `/detail/category/{旧ID}` → `/category/{新ID}`
   - `/detail/middle/{旧ID}` → `/category/{新ID}`
   - 旧 ID と新 ID の対応は、カテゴリ木を移したとき(#29)の `legacy_id_maps`(`m_category`・`m_middle` → `categories`)で引く
2. 対応表に無い旧 ID は、推測で転送せず 404 にする(SPA の HTML を 404 の状態で返し、画面は「ページが見つかりません」)。数字でない ID・対応先のノードが後で消えた ID も同じく 404
3. 旧の管理画面の URL(`/Cocktail/admin` と、その下のすべてのパス)を、GET で開くと `/admin` へ 301 で移す
4. 受けるのは GET だけ。ほかのメソッドは 405。付いてきたクエリは移った先に持ち越さない(旧の詳細はクエリを使っていない。#117 の旧のログインの URL と同じ扱い)
5. カクテルと銘柄は ID が変わらないので、転送しない(`/detail/cocktail/{ID}`・`/detail/brand/{ID}` は旧と同じ URL のまま)。`/card/*` の転送は下の 7(#113)
6. 旧の管理画面の URL も 301 にする(#78 の「やること」)。#117 の旧のログインの URL は、行き先を後で変えられるよう 302 にした(`docs/decisions/legacy-login-urls.md`)が、こちらは新の管理画面の入口(`/admin`)から動かさない
7. 旧の SNS カードの URL(`/card/{command}/{ID}`)を、GET で開くと、同じものの詳細ページへ 301 で移す(#113。`LegacyCardUrlController`)。旧はこの URL で OGP だけの HTML を返していた(`SNSCardController@display`)が、新は詳細ページの HTML が OGP を持つ(#112。`docs/decisions/ogp.md`)
   - `command` は旧が受けていた 8 つの表記だけを受ける: `cocktail`・`Cocktail`・`brand`・`Brand`・`category`・`Category`・`middle`・`Middle`
   - カクテル → `/detail/cocktail/{ID}`、銘柄 → `/detail/brand/{ID}`(ID は変わらない)。カテゴリ・ミドル → 1 と同じ付け替えで `/category/{新ID}`
   - 知らない `command`(`COCKTAIL` のようなほかの表記も)・無い ID・数字でない ID・下書きのカクテル・対応先のノードが消えた ID は、推測で転送せず 404(2 と同じく SPA の HTML を 404 の状態で返す)
   - 先頭に 0 の付いた ID(`/card/cocktail/012`)は、旧と同じく数として読み、0 を除いた ID の詳細へ移す
   - 4 と同じく、受けるのは GET だけで、クエリは持ち越さない。`/card`・`/card/{command}`・`/card/{command}/{ID}/…` のように形の違うパスは、これまでどおり SPA の HTML を 200 で返す(旧も画面の HTML を 200 で返していた)

旧の管理画面の画面そのもの(一覧・編集)は、旧でも `/admin/...` にあった(旧の `laravel6/resources/js/routes.js`)。`/Cocktail/admin/...` は、旧の管理画面が呼んでいた API(POST)と、ログインの画面の別名(`/Cocktail/admin/login`)だけ。`/admin/...` は新の管理画面のルートがそのまま受ける(この転送の対象外)。

## 旧との差分

テストの表では `差分あり(意図: docs/decisions/legacy-urls.md)` としてここを指す。旧システムは手元で動かしていない(旧の挙動は、cocktail-legacy の `laravel13/routes/web.php` と `laravel6/resources/js/routes.js` を読んだもの)。

- `/detail/category/{旧ID}`: 旧は SPA の HTML を 200 で返し、画面がそのカテゴリの詳細を出した → 新は `/category/{新ID}` へ 301 で移す
- `/detail/middle/{旧ID}`: 旧は SPA の HTML を 200 で返し、画面がそのミドルの詳細を出した → 新は `/category/{新ID}` へ 301 で移す
- 無い旧 ID の `/detail/category/{ID}`・`/detail/middle/{ID}`: 旧は SPA の HTML を 200 で返し、画面が呼んだ API が 500 だった → 新は 404
- 数字でない ID の `/detail/category/{ID}`・`/detail/middle/{ID}`: 旧は SPA の HTML を 200 で返し、画面はトップを出した(画面のルートが `\d+` だけを受け、ほかはトップに落ちる) → 新は 404
- `/Cocktail/admin` 以下への GET: 旧は SPA の HTML を 200 で返し、画面はトップを出した(`/Cocktail/admin/login` も、画面のルートに無いのでトップ) → 新は `/admin` へ 301 で移す
- `/Cocktail/admin/get/*` への POST: 旧はログイン中の管理者に、管理画面の編集に使うデータを返した → 新は 405(新の管理画面は `/api/admin/*` を使う)
- `/card/{command}/{ID}` で、ID の行がある(旧の 8 つの表記): 旧は OGP だけの HTML(本文なし)を 200 で返した → 新は同じものの詳細ページへ 301 で移す(#113。カテゴリ・ミドルは `/category/{新ID}`)
- `/card/{command}/{ID}` で、旧が受けていない表記の `command`(`COCKTAIL`・`dictionary` など): 旧はサイト共通の OGP の HTML を 200 で返した → 新は 404(#113 の受け入れ条件。判断表の JC-04 による: 意味の無い結果)
- `/card/{command}/{ID}` で、無い ID: 旧はサイト共通の OGP の HTML を 200 で返した → 新は 404(#113 の受け入れ条件。判断表の JC-04 による: 意味の無い結果)
- `/card/cocktail/{ID}` で、下書きのカクテル: 旧はそのカクテルの OGP の HTML を 200 で返した → 新は 404(移った先の詳細が下書きを 404 にする(#111)ので、転送しても 404 に着くだけのため)
- `/card/{command}/{ID}` で、数に直せない ID(`abc`・`1abc`): 旧は `int` の引数に渡せず 500 になる作りだった → 新は 404(判断表の JC-04 による: 旧の 500)
- `/card/{command}/{ID}` で、負の数の ID(`-1`): 旧は行が無いのでサイト共通の OGP の HTML を 200 で返す作りだった → 新は 404(無い ID と同じ。判断表の JC-04 による: 意味の無い結果)
- `/card/{command}/{ID}` で、小数の形の ID(`1.0`): 旧は数として読み、ID 1 の OGP の HTML を 200 で返す作りだった → 新は 404(2 の数字でない ID と同じ扱い。旧も SNS のクローラーを `/card` に回す書き換えは数字だけの ID に限っていた(`docs/decisions/ogp.md` の「旧ソースで確かめた事実」)ので、旧が作る URL には出ない)
