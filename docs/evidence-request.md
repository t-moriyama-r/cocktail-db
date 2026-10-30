# 証跡を頼むコメントの形

証跡(CLAUDE.md の「ブランチ・コミット・PR」の 5)を撮れない環境のエージェントが、撮影を頼むコメント(先頭が `<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=evidence -->`)に、何を撮るかを機械が読める形で書く決まり(#599)。

撮る側(手元の見回りと、これから足す Actions の撮影。#487)が、コメントの文章を読み解かなくても、同じ画面・同じリクエストを同じデータで撮れるようにするため。

- 頼むコメントには、` ```evidence ` のコードブロックを **1 つだけ** 置き、中身を YAML で書く。撮るものが複数あるときは、1 つのブロックの `items` に並べる
- ブロックの外には、今までどおり人が読む説明(引き継いでしたこと・何を見てほしいか)を書く。ブロックには撮るものだけを書く
- 書いたら、`bash .github/scripts/evidence-request.sh <本文のファイル>` で確かめてから投稿する。必須の項目が足りないと、足りないところを出して 1 で終わる。通れば、既定の値を埋めた中身を JSON で出す
- `evidence-request.sh` は YAML を Ruby の標準の Psych で読むので、`ruby` が要る(GitHub のランナーには入っている)。テストは `.github/scripts/tests/evidence-request.test.sh` で、この文書の例も取り出して流す(例を変えたら、テストも通ることを確かめる)

## 形

先頭の項目:

| 項目 | 必須 | 中身 |
|---|---|---|
| `version` | ○ | `1`(形を変えるときに上げる) |
| `before` | | 修正前に撮るコミットの SHA(16 進 7〜40 桁)。省くと PR の base |
| `after` | | 修正後に撮るコミットの SHA。省くと PR の head。見た目の案を選ぶ PR で「決める前」と「最終」を比べるときは、両方書く |
| `items` | ○ | 撮るものを 1 つ以上並べる(下) |

`items` の 1 つずつ(どの種類でも共通):

| 項目 | 必須 | 中身 |
|---|---|---|
| `kind` | ○ | `screen`(画面)・`api`(API の応答)・`command`(コマンドの結果)のどれか |
| `cases` | ○ | この証跡で確かめるテストケースの ID の配列(`[TC-599-01]`。PR 本文の表の ID) |
| `note` | | 撮る人への補足(何を見ればよいか) |
| `data` | | 入れるデータ。`seed: <シーダーのクラス名>`。省くと `E2eSeeder`(E2E と同じデータ) |

### `kind: screen`(画面)

| 項目 | 必須 | 中身 |
|---|---|---|
| `path` | ○ | 開く画面のパス(`/` で始める。クエリを含めてよい)。ホスト・スキームは書かない(撮る側が起こしたアプリに付ける) |
| `widths` | | 静止画を撮る幅の配列。省くと `[375, 768, 1024, 1440]`(`docs/design/style.md` の「証跡を撮る幅」)。書くときは、xs・sm・md・lg の区切りそれぞれに入る幅を 1 つ以上入れる(境目の 599/600px・959/960px を足すときに書く) |
| `video` | | 動画を撮る幅の配列。`widths` の中から選び、xs と lg の幅を 1 つ以上入れる。省くと `widths` のいちばん狭い幅と広い幅 |
| `steps` | | 開いた後にする操作の配列(下)。省くと開いたところを撮る |

`steps` の 1 つずつは「操作: 値」を 1 つだけ書く。何に対して(`click` などの値・`target`)は Playwright のロケーター(`role=button[name="検索"]`・`text=ジン` など)で書く。

| 操作 | 値 |
|---|---|
| `goto` | 移るパス(`/` で始める) |
| `click` | 押すもの |
| `fill` | `target`(入れる欄)と `value`(入れる文字) |
| `select` | `target`(選ぶ欄)と `value`(選ぶ値) |
| `press` | 押すキー(`Enter` など) |
| `hover` | 指を載せるもの |
| `wait_for` | 出るまで待つもの |
| `scroll_to` | 見えるところまで動かすもの |

### `kind: api`(API の応答)

修正前と修正後に同じリクエストを送り、応答を `curl -si` で撮る(新しい API なら修正前は 404)。

| 項目 | 必須 | 中身 |
|---|---|---|
| `request.method` | ○ | `GET`・`POST`・`PUT`・`PATCH`・`DELETE` |
| `request.path` | ○ | `/api/` で始まるパス(クエリを含めてよい)。ホストは書かない |
| `request.headers` | | 送るヘッダー(「名前: 値」)。`Authorization`・`Cookie` など秘密の入るものは書けない |
| `request.body` | | 送る本文(対応表なら JSON にして送る)。`GET`・`DELETE` には書かない |

### `kind: command`(コマンドの結果)

API にも出ない変更(マイグレーション・モデル・サービスの中だけ)の、`SHOW CREATE TABLE`・`php artisan tinker`・テストの実行結果を撮る。

| 項目 | 必須 | 中身 |
|---|---|---|
| `run` | ○ | 流すコマンド |
| `workdir` | | 流すディレクトリ(リポジトリの中の相対パス。`/` で始めない・`..` を使わない)。省くとリポジトリの直下 |

### 書けないもの

公開リポジトリなので(CLAUDE.md の「このリポジトリは public」)、次のものは書かない。`evidence-request.sh` も、見つけたら落とす。

- ホスト・スキーム(`https://…`)。パスだけを書く
- `/home/`・`/Users/`・`/root/` で始まる絶対パス
- 秘密の入るヘッダー(`Authorization`・`Cookie`・`Proxy-Authorization`・`X-XSRF-TOKEN`・`X-CSRF-TOKEN`)
- 知らない項目(綴りの誤りを見逃さないため)

`evidence-request.sh` は書いた人を見ない。読む側は、信頼する人・bot が書いたコメント(`untrusted-comments.sh` で外したもの。`docs/review-checklist.md` の RC-05)のブロックだけを渡す。

## 例

FE の画面と API を 1 つずつ撮るときの頼むコメント:

````markdown
<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=evidence -->
引き継いで、検索の結果が 0 件のときの文言を直した。この環境では撮れないので、次を撮って「## 証跡」に貼ってほしい。

```evidence
version: 1
items:
  - kind: screen
    cases: [TC-599-01, TC-599-02]
    note: 0 件のときの文言が、表の上に出ること
    path: /search?q=zzz
    steps:
      - fill: { target: 'role=searchbox', value: ジン }
      - press: Enter
      - wait_for: 'role=table'
  - kind: api
    cases: [TC-599-03]
    request:
      method: POST
      path: /api/cocktails/search
      headers: { Accept: application/json }
      body: { name: zzz }
```
````

`evidence-request.sh` に通すと、既定の値を埋めて次のように出す(読みやすいよう改行を足した)。

```json
{
  "version": 1,
  "items": [
    {
      "kind": "screen",
      "cases": ["TC-599-01", "TC-599-02"],
      "note": "0 件のときの文言が、表の上に出ること",
      "path": "/search?q=zzz",
      "steps": [
        { "fill": { "target": "role=searchbox", "value": "ジン" } },
        { "press": "Enter" },
        { "wait_for": "role=table" }
      ],
      "widths": [375, 768, 1024, 1440],
      "video": [375, 1440],
      "data": { "seed": "E2eSeeder" }
    },
    {
      "kind": "api",
      "cases": ["TC-599-03"],
      "request": {
        "method": "POST",
        "path": "/api/cocktails/search",
        "headers": { "Accept": "application/json" },
        "body": { "name": "zzz" }
      },
      "data": { "seed": "E2eSeeder" }
    }
  ]
}
```

マイグレーションだけの変更で、修正前後のテーブルの形を撮るとき:

```evidence
version: 1
items:
  - kind: command
    cases: [TC-599-04]
    workdir: backend
    run: php artisan db:show --table=cocktails
```

区切りの境目を変えた画面で、境目の両側も撮るとき(`widths` を書くと、既定の 4 つは足されない。4 つの幅も書く):

```evidence
version: 1
items:
  - kind: screen
    cases: [TC-599-05]
    path: /
    widths: [375, 599, 600, 768, 959, 960, 1024, 1440]
    video: [375, 1440]
```
