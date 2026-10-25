# cocktail-db

「宅飲みカクテルデータベース」(https://cocktail.trc-vlackhistory.net/)のリプレイス版。今は Hello World の段階。

- 公開先(開発中): https://cocktail-next.trc-vlackhistory.net/
- BE: Laravel 13(PHP 8.4)。`/api/*` が API、それ以外のパスは React の SPA を返す
- FE: React + Vite(TypeScript)。ビルド結果は `backend/public/spa` に出力し、Laravel の `resources/views/spa.blade.php` がマニフェストを読んで HTML を組み立てる
- 型の共有: Laravel のコードから Scramble で `openapi/openapi.json` を出力し、FE は openapi-typescript で型を生成して openapi-fetch で呼ぶ
- サーバー: mixhost(共用レンタルサーバー、cPanel)。GitHub Actions から SSH(rsync)でデプロイ

## 構成

```
backend/    Laravel(mixhost の ~/cocktail-db に置く)
frontend/   React + Vite
openapi/    Laravel から出力した OpenAPI(FE の型の元)
.github/workflows/deploy.yml
```

サーバー上の配置:

```
~/cocktail-db/                          アプリ本体(rsync で上書き。.env と storage/ は残す)
~/public_html/cocktail-db/public  ->  ~/cocktail-db/public   サブドメインのドキュメントルート(シンボリックリンク)
```

アプリ本体を `public_html` の外に置き、公開するのは `public/` だけにしている(`.env` などが Web から見えないように)。

## ローカルでの起動

```bash
# BE
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan serve            # http://127.0.0.1:8000

# FE(別のターミナル)
cd frontend
npm install
npm run dev                  # http://localhost:5173 。/api は Laravel に流れる
```

Laravel から本番と同じ形で確かめるときは、`frontend` で `npm run build` してから http://127.0.0.1:8000 を開く。

## API を変えたら

```bash
cd backend && php artisan scramble:export --path=../openapi/openapi.json
cd ../frontend && npm run gen:api     # src/api/schema.ts を作り直す(build でも自動で走る)
```

`openapi/openapi.json` の更新を忘れると、テストのワークフローが止まる。

## テスト

```bash
cd backend
php artisan test --exclude-group=db   # 手元(MariaDB が無いとき)。DB を使うテストを飛ばす
php artisan test                      # MariaDB があるとき。DB_* を環境変数で渡す
```

- DB を使うテストは `db` グループに入れ、本番と同じ MariaDB で動かす(SQLite では代用しない)
- CI(`.github/workflows/test.yml`)は MariaDB 10.11 を立て、`db` グループも含めて全部流す。PR と `develop` への push で動く
- E2E(Playwright)は `e2e/` にある。`frontend` で `npm run build` してから、`e2e` で `npm test`(`php artisan serve` を自動で起こす)。CI では `e2e` ジョブで流れる
- テスト名の先頭はテストケースの ID(`TC-<issue 番号>-<連番>`)。ケースの表は PR 本文にある

## ブランチ

| ブランチ | 役割 |
|---|---|
| `develop` | 開発のベース(既定のブランチ)。作業ブランチはここから切り、PR でここに戻す |
| `release` | リリース用。`develop` から PR でマージすると、デプロイが動く |
| `issue-<番号>-<短い名前>` | 作業ブランチ。issue から `gh issue develop <番号> --base develop --name issue-<番号>-<名前>` で作ると、issue に結び付く |

- `develop`・`release` には直接 push できない(管理者も)。PR からマージする(マージコミット)
- コミットメッセージは `issue-<番号>: <何をしたか>` で始め、本文に `Refs #<番号>` を書く(GitHub 上で issue に結び付く)

## デプロイ

`release` にマージすると GitHub Actions(`.github/workflows/deploy.yml`)が動く。先にテスト(`test.yml`)を流し、通ったときだけデプロイする。

1. テスト(openapi.json が最新かも含む)
2. FE をビルド
3. `composer install --no-dev`
4. rsync で `~/cocktail-db/` に送る(`.env`・`storage/`・`.well-known/` は除く)
5. サーバーで `php artisan optimize`
6. `/up` と `/api/hello` を叩いて確かめる

GitHub 側に要る設定:

| 種類 | 名前 | 中身 |
|---|---|---|
| Secret | `MIXHOST_SSH_KEY` | デプロイ専用の SSH 秘密鍵(公開鍵は cPanel の「SSH アクセス」に `cocktail_db_deploy` で登録済み) |
| Secret | `MIXHOST_SSH_HOST` | SSH の接続先ホスト(公開リポジトリの Actions のログに出さないため Secret にしている) |
| Secret | `MIXHOST_SSH_USER` | cPanel のユーザー名(同上) |
| Variable | `MIXHOST_KNOWN_HOSTS` | サーバーのホスト鍵(`ssh-keyscan -t ed25519 <host>` の出力) |
| Variable | `APP_URL` | `https://cocktail-next.trc-vlackhistory.net` |

サーバーの `~/cocktail-db/.env` は初回に手で置いたもので、リポジトリには入れない。

## 撤去

1. GitHub の Secret・Variables を消す(リポジトリごと消すなら不要)
2. cPanel の「SSH アクセス」で鍵 `cocktail_db_deploy` を削除
3. cPanel の「ドメイン」で `cocktail-next.trc-vlackhistory.net` を削除
4. SSH で `~/cocktail-db` と `~/public_html/cocktail-db` を削除
