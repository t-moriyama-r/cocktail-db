# 旧のカテゴリ・ミドルの詳細と、旧の管理画面の URL の転送

#78 で決めたこと。新ではカテゴリとミドルの詳細を 1 つの画面(`/category/{ID}`)にまとめ、ノードの ID も振り直した(#29)。外から貼られたリンク・検索エンジンに残った旧の URL から来た人が、同じノードの新しいページに届くよう、旧の URL を 301 で移す。

## 決まったこと

1. 旧のカテゴリ・ミドルの詳細の URL を、GET で開くと、カテゴリ木を移した先のノードの詳細へ 301 で移す(`backend/routes/web.php`・`LegacyCategoryUrlController`)
   - `/detail/category/{旧ID}` → `/category/{新ID}`
   - `/detail/middle/{旧ID}` → `/category/{新ID}`
   - 旧 ID と新 ID の対応は、カテゴリ木を移したとき(#29)の `legacy_id_maps`(`m_category`・`m_middle` → `categories`)で引く
2. 対応表に無い旧 ID は、推測で転送せず 404 にする(SPA の HTML を 404 の状態で返し、画面は「ページが見つかりません」)。数字でない ID・対応先のノードが後で消えた ID も同じく 404
3. 旧の管理画面の URL(`/Cocktail/admin` と、その下のすべてのパス)を、GET で開くと `/admin` へ 301 で移す
4. 受けるのは GET だけ。ほかのメソッドは 405。付いてきたクエリは移った先に持ち越さない(旧の詳細はクエリを使っていない。#117 の旧のログインの URL と同じ扱い)
5. カクテルと銘柄は ID が変わらないので、転送しない(`/detail/cocktail/{ID}`・`/detail/brand/{ID}` は旧と同じ URL のまま)。`/card/*` の転送は #58 で行う
6. 旧の管理画面の URL も 301 にする(#78 の「やること」)。#117 の旧のログインの URL は、行き先を後で変えられるよう 302 にした(`docs/decisions/legacy-login-urls.md`)が、こちらは新の管理画面の入口(`/admin`)から動かさない

旧の管理画面の画面そのもの(一覧・編集)は、旧でも `/admin/...` にあった(旧の `laravel6/resources/js/routes.js`)。`/Cocktail/admin/...` は、旧の管理画面が呼んでいた API(POST)と、ログインの画面の別名(`/Cocktail/admin/login`)だけ。`/admin/...` は新の管理画面のルートがそのまま受ける(この転送の対象外)。

## 旧との差分

テストの表では `差分あり(意図: docs/decisions/legacy-urls.md)` としてここを指す。旧システムは手元で動かしていない(旧の挙動は、cocktail-legacy の `laravel13/routes/web.php` と `laravel6/resources/js/routes.js` を読んだもの)。

- `/detail/category/{旧ID}`: 旧は SPA の HTML を 200 で返し、画面がそのカテゴリの詳細を出した → 新は `/category/{新ID}` へ 301 で移す
- `/detail/middle/{旧ID}`: 旧は SPA の HTML を 200 で返し、画面がそのミドルの詳細を出した → 新は `/category/{新ID}` へ 301 で移す
- 無い旧 ID の `/detail/category/{ID}`・`/detail/middle/{ID}`: 旧は SPA の HTML を 200 で返し、画面が呼んだ API が 500 だった → 新は 404
- 数字でない ID の `/detail/category/{ID}`・`/detail/middle/{ID}`: 旧は SPA の HTML を 200 で返し、画面はトップを出した(画面のルートが `\d+` だけを受け、ほかはトップに落ちる) → 新は 404
- `/Cocktail/admin` 以下への GET: 旧は SPA の HTML を 200 で返し、画面はトップを出した(`/Cocktail/admin/login` も、画面のルートに無いのでトップ) → 新は `/admin` へ 301 で移す
- `/Cocktail/admin/get/*` への POST: 旧はログイン中の管理者に、管理画面の編集に使うデータを返した → 新は 405(新の管理画面は `/api/admin/*` を使う)
