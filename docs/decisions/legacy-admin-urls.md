# 旧の管理画面の一覧・編集の URL の扱い

#626 で決めたこと。新の管理画面は URL を変えた(旧 `/admin/list/Cocktail` → 新 `/admin/cocktails` など。#119・#123・#64・#66・#126)。旧の URL をブックマークしている管理者が、切り替えの後に開いても迷わないよう、新に同じ画面があるものは新の URL へ移す。旧のログインの URL(#117。`docs/decisions/legacy-login-urls.md`)と同じ扱いにする。

## 決まったこと

1. 旧の管理画面の URL のうち、新に同じ画面があるものを、GET で開くと新の URL へ 302 で移す(`backend/routes/web.php`)

   | 旧の URL | 新の URL |
   |---|---|
   | `/admin/list/Category` | `/admin/categories`(カテゴリ木。#123) |
   | `/admin/list/Middle` | `/admin/categories`(カテゴリとミドルは 1 つの木にまとめた。`docs/decisions/admin-layout.md` の 3) |
   | `/admin/list/Brand` | `/admin/brands` |
   | `/admin/list/Dictionary` | `/admin/dictionaries` |
   | `/admin/list/Cocktail` | `/admin/cocktails` |
   | `/admin/list/CocktailDraft` | `/admin/cocktails?status=draft` |
   | `/admin/edit/Brand/`(新規) | `/admin/brands/new` |
   | `/admin/edit/Brand/{ID}` | `/admin/brands/{ID}`(銘柄は旧の ID のまま移した。`docs/schema.md`) |
   | `/admin/edit/Dictionary/`(新規) | `/admin/dictionaries/new` |
   | `/admin/edit/Dictionary/{ID}` | `/admin/dictionaries/{ID}`(用語辞典も旧の ID のまま) |
   | `/admin/edit/Cocktail/`(新規) | `/admin/cocktails/new`(#127) |
   | `/admin/edit/Cocktail/{ID}` | `/admin/cocktails/{ID}`(カクテルも旧の ID のまま。#127) |

2. 移すだけで、ほかのことはしない。ログインの確かめは移った先の画面がする(未ログインなら、移った先の URL を持たせてログインの画面へ移る。`docs/decisions/admin-layout.md` の 5)。付いてきたクエリは移った先に持ち越さない(下書きの一覧の `?status=draft` は行き先の一部なので付ける)
3. 受けるのは GET だけ。ほかのメソッドは 405。状態コードは #117 と同じく 302(301 はブラウザが覚えてしまい、後で行き先を変えられなくなるため)
4. 移すのは、旧の画面のリンクと同じ綴り(大文字小文字)と、ID が数字のものだけ。旧は末尾のスラッシュの有無を区別しなかったので、新も両方を移す
5. 新に同じ画面がまだ無いものは、今は移さない(管理画面の 404 の画面のまま)。移すのは、その画面の issue で行う

   | 旧の URL | 移す issue | メモ |
   |---|---|---|
   | `/admin/edit/Category/{ID}`・`/admin/edit/Middle/{ID}`・`/admin/edit/Category/`・`/admin/edit/Middle/`(新規) | #308(ノードの編集フォーム) | ノードは ID を振り直したので、旧 ID から新 ID を `legacy_id_maps`(`m_category`・`m_middle` → `categories`)で引く。#78 の旧の詳細の URL の転送と同じ引き方 |
   | `/admin/autoinput/paraphrase` | #67(言い換え・スキップ語の画面) | |

6. 旧のオートインプット(`/admin/autoinput/exe`・`/admin/autoinput/next`)は移さない(404 のまま)。新には引き継がない機能で、移す先の画面が無い(#68・`docs/decisions/admin-layout.md` の 3)

## 旧との差分

テストの表では `差分あり(意図: docs/decisions/legacy-admin-urls.md)` としてここを指す。旧システムは手元で動かしていない(旧の挙動は、cocktail-legacy の `laravel6/resources/js/routes.js` と `laravel13/routes/web.php` を読んだもの)。

- 決まったこと 1 の旧の URL への GET: 旧はサーバーが SPA の HTML を 200 で返し、画面がその URL のまま一覧・編集の画面を出した(未ログインならログインの画面へ移した) → 新は新の URL へ 302 で移し、画面は移った先の URL で出す
- `/admin/list/Category`・`/admin/list/Middle`: 旧はカテゴリ・ミドルの別々の一覧を出した → 新はどちらもカテゴリ木(`/admin/categories`)へ移す
- 大文字小文字の違う旧の URL(`/admin/list/brand` など): 旧は画面のルーター(vue-router)が大文字小文字を区別せず、同じ画面を出した → 新は移さず、管理画面の 404 の画面を出す(旧の画面のリンクの綴りだけを移す。決まったこと 4)
- 決まったこと 5・6 の旧の URL: 旧は編集・言い換え・オートインプットの画面を出した → 新は(今は)管理画面の 404 の画面を出す。5 は画面の issue が入ったときに移す

## 旧ソースで確かめた事実

| どこ | 何をしているか |
|---|---|
| `laravel6/resources/js/routes.js` | `admin` の `children` に、`list/Category`・`list/Middle`・`list/Brand`・`list/Dictionary`・`list/Cocktail`・`list/CocktailDraft`、`edit/{種類}/:id(\\d+)` と `edit/{種類}/`(新規。種類は Category・Middle・Brand・Dictionary・Cocktail)、`autoinput/exe`・`autoinput/next`・`autoinput/paraphrase` がある。`beforeEnter` で未ログインならログインの画面(`Login`)へ移す |
| `laravel6/resources/js/app.js` | ルーターは `mode: 'history'`。`pathToRegexpOptions` を渡していないので、vue-router 3 の既定で大文字小文字を区別せず、末尾のスラッシュも区別しない |
| `production-public/js/app.js`(本番の画面のビルド) | 上の `admin` の `children` が同じパスで入っている。ルートに `caseSensitive` を付けていないので、大文字小文字を区別しない正規表現になる |
| `laravel13/routes/web.php` | API 以外のパスは SPA の HTML を返す(`/admin/...` もサーバーには専用のルートが無い) |
