# cocktail-db

「宅飲みカクテルデータベース」(https://cocktail.trc-vlackhistory.net/)のリプレイス版。今は Hello World の段階。

- 公開先(開発中): https://cocktail-next.trc-vlackhistory.net/ (`release`)
- dev 環境(ステージング): https://cocktail-dev.trc-vlackhistory.net/ (`staging`。Basic 認証で閉じている。下の「dev 環境」)
- BE: Laravel 13(PHP 8.4)。`/api/*` が API、それ以外のパスは React の SPA を返す
- FE: React + Vite(TypeScript)。ビルド結果は `backend/public/spa` に出力し、Laravel の `resources/views/spa.blade.php` がマニフェストを読んで HTML を組み立てる
- 型の共有: Laravel のコードから Scramble で `openapi/openapi.json` を出力し、FE は openapi-typescript で型を生成して openapi-fetch で呼ぶ
- サーバー: mixhost(共用レンタルサーバー、cPanel)。GitHub Actions から SSH(rsync)でデプロイ

## 構成

```
backend/    Laravel(mixhost の ~/cocktail-db に置く)
frontend/   React + Vite
openapi/    Laravel から出力した OpenAPI(FE の型の元)
.github/workflows/deploy.yml       release → 本番
.github/workflows/deploy-dev.yml   staging → dev 環境
.github/workflows/branch-flow.yml  staging・release への PR の元のブランチを確かめる
```

サーバー上の配置:

```
~/cocktail-db/                          アプリ本体(rsync で上書き。.env と storage/ は残す)
~/public_html/cocktail-db/public  ->  ~/cocktail-db/public   サブドメインのドキュメントルート(シンボリックリンク)
~/cocktail-db-dev/                      dev 環境のアプリ本体(.env はデプロイのたびに作り直す。REVISION に出したコミット)
~/public_html/cocktail-db-dev/.htaccess                      dev 環境の Basic 認証(cPanel の「ディレクトリのプライバシー」が書く)
~/public_html/cocktail-db-dev/public  ->  ~/cocktail-db-dev/public   dev 環境のドキュメントルート(シンボリックリンク)
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
- 移行のテストは、旧 DB の接続(`legacy`)で 2 つ目の DB `legacy_test` を使う。同じ MariaDB に `legacy_test` を作り、接続先を `LEGACY_DB_HOST` などで渡す(DB 名は `phpunit.xml` が `legacy_test` に固定する)。表と旧データは `tests/Support/LegacyFixture.php` が `database/legacy/schema.sql`(旧 DB の DDL)から作る
- CI(`.github/workflows/test.yml`)は MariaDB 10.11 を立て、`db` グループも含めて全部流す。PR と `develop` への push で動く
- E2E(Playwright)は `e2e/` にある。`frontend` で `npm run build` してから、`e2e` で `npm test`(`php artisan serve` を自動で起こす)。CI では `e2e` ジョブで流れる
- テスト名の先頭はテストケースの ID(`TC-<issue 番号>-<連番>`)。ケースの表は PR 本文にある

## ブランチ

| ブランチ | 役割 |
|---|---|
| `develop` | 開発のベース(既定のブランチ)。作業ブランチはここから切り、PR でここに戻す |
| `staging` | ステージング用。`develop` から PR でマージすると、dev 環境へのデプロイが動く |
| `release` | リリース用。`staging` から PR でマージすると、本番へのデプロイが動く |
| `issue-<番号>-<短い名前>` | 作業ブランチ。issue から `gh issue develop <番号> --base develop --name issue-<番号>-<名前>` で作ると、issue に結び付く |

流れ: 作業ブランチ →(PR)→ `develop` →(PR)→ `staging`(dev 環境で確かめる)→(PR)→ `release`(本番)

- `develop`・`staging`・`release` には直接 push できない(管理者も)。PR からマージする(マージコミット)
- `staging` へは `develop` から、`release` へは `staging` からしか PR をマージできない(`branch-flow.yml` のチェックが落ちる)。ステージングに何を出したか・本番に何を出すかは、その PR の差分で見る
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

## dev 環境

`staging` を、Basic 認証で閉じた dev 環境(ステージング。https://cocktail-dev.trc-vlackhistory.net/)に出す(`.github/workflows/deploy-dev.yml`)。本番とは別のディレクトリ・DB(`<user>_cocktail_dev`)を使う。

- **`staging` にマージしたとき**に出す。`develop` から `staging` への PR を作り、その差分(前回ステージングに出したところからの変更)を見てマージする
- **出し直したいとき**: Actions の「deploy-dev」→「Run workflow」(ブランチは `staging`)、または `gh workflow run deploy-dev.yml --ref staging`。前回出したコミット(サーバーの `~/cocktail-db-dev/REVISION`)と同じなら出さないので、同じコミットを出し直すとき(`.env` の中身を変えた後など)は `force` を付ける(`-f force=true`)。`staging` 以外のブランチからは出さない
- 流れは本番と同じ(テスト → FE のビルド → rsync → `.env` → `migrate` → `optimize` → 動作確認)。動作確認では、資格情報なしで 401 になることも確かめる
- `.env` はデプロイのたびに Secret から作り直す(手で直さない)。`APP_KEY` だけは初回にサーバーで作ったものを使い続ける
- Basic 認証のユーザーは cPanel の「ディレクトリのプライバシー」(`public_html/cocktail-db-dev`)で足す・消す。ドキュメントルートの 1 つ上にかけているので、デプロイで消えない

GitHub 側に要る設定(本番の `MIXHOST_*` も使う):

| 種類 | 名前 | 中身 |
|---|---|---|
| Secret | `DEV_DB_PASSWORD` | dev の DB ユーザー(`<user>_cocktail_dev`)のパスワード。単一引用符は使えない |
| Secret | `DEV_BASIC_AUTH` | 動作確認に使う Basic 認証の `<ユーザー名>:<パスワード>` |
| Variable | `DEV_APP_URL` | `https://cocktail-dev.trc-vlackhistory.net` |

Secret は GitHub の画面(Settings → Secrets and variables → Actions)か、自分で開いたターミナルの `gh secret set <名前>` で入れる。入力を受け付けない場所(Claude Code の `!` など)で `gh secret set` を打つと、値が空のまま保存される。空のときは、デプロイの最初の check で止まる。

## 撤去

1. GitHub の Secret・Variables を消す(リポジトリごと消すなら不要)
2. cPanel の「SSH アクセス」で鍵 `cocktail_db_deploy` を削除
3. cPanel の「ドメイン」で `cocktail-next.trc-vlackhistory.net` を削除
4. SSH で `~/cocktail-db` と `~/public_html/cocktail-db` を削除
5. dev 環境も消すなら: cPanel で `cocktail-dev.trc-vlackhistory.net`・DB `<user>_cocktail_dev` とそのユーザー・`public_html/cocktail-db-dev` のパスワード保護を削除し、SSH で `~/cocktail-db-dev` と `~/public_html/cocktail-db-dev` を削除。Secret の `DEV_*`・Variable の `DEV_APP_URL`・Environment の `dev` を消す
