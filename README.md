# cocktail-db

「宅飲みカクテルデータベース」(https://cocktail.trc-vlackhistory.net/)のリプレイス版。今は Hello World の段階。

- 公開先(開発中): https://cocktail-next.trc-vlackhistory.net/ (`release`)
- dev 環境: https://cocktail-dev.trc-vlackhistory.net/ (`develop`。Basic 認証で閉じている。下の「dev 環境・stg 環境」)
- stg 環境(ステージング): https://cocktail-stg.trc-vlackhistory.net/ (`staging`。同上)
- BE: Laravel 13(PHP。CI は 8.5、サーバーは下の「サーバーの PHP の版」)。`/api/*` が API、それ以外のパスは React の SPA を返す
- FE: React + Vite(TypeScript)。ビルド結果は `backend/public/spa` に出力し、Laravel の `resources/views/spa.blade.php` がマニフェストを読んで HTML を組み立てる
- スタイル: CSS Modules + CSS 変数(下の「画面のスタイル」)
- 型の共有: Laravel のコードから Scramble で `openapi/openapi.json` を出力し、FE は openapi-typescript で型を生成して openapi-fetch で呼ぶ
- サーバー: mixhost(共用レンタルサーバー、cPanel)。GitHub Actions から SSH(rsync)でデプロイ

## 構成

```
backend/    Laravel(mixhost の ~/cocktail-db に置く)
frontend/   React + Vite
openapi/    Laravel から出力した OpenAPI(FE の型の元)
.github/workflows/deploy.yml       release → 本番
.github/workflows/deploy-dev.yml   develop → dev 環境
.github/workflows/deploy-stg.yml   staging → stg 環境
.github/workflows/deploy-env.yml   dev・stg へのデプロイの中身(上の 2 つから呼ぶ)
.github/workflows/branch-flow.yml  staging・release への PR の元のブランチ(release 向けはタイトルの版も)を確かめる
.github/workflows/release-tag.yml  release にマージした PR のタイトルの版で、タグと GitHub Release を作る
.github/workflows/evidence-gate.yml  FE・BE のコードを変えた PR に、修正前後の証跡(静止画・動画。画面を変えた PR は幅の区切りごと)があるかを確かめる
```

サーバー上の配置:

```
~/cocktail-db/                          アプリ本体(rsync で上書き。.env と storage/ は残す)
~/public_html/cocktail-db/public  ->  ~/cocktail-db/public   サブドメインのドキュメントルート(シンボリックリンク)
~/cocktail-db-dev/                      dev 環境のアプリ本体(.env はデプロイのたびに作り直す。REVISION に出したコミット)
~/public_html/cocktail-db-dev/.htaccess                      dev 環境の Basic 認証(cPanel の「ディレクトリのプライバシー」が書く)
~/public_html/cocktail-db-dev/public  ->  ~/cocktail-db-dev/public   dev 環境のドキュメントルート(シンボリックリンク)
~/cocktail-db-stg/ ・ ~/public_html/cocktail-db-stg/           stg 環境(dev と同じ形)
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

## 画面のスタイル

CSS Modules(画面ごとの `*.module.css`)と CSS 変数で書く。色・余白・文字の大きさの変数と幅の区切り(600px・960px・1264px。旧と同じ)は `frontend/src/styles/` にある。

選んだ理由(候補の比べ方は #294 と `docs/design/style.md` の「方式の候補」):

- 依存を足さずに始められる。Vite が CSS Modules を標準で扱い、実行時のコードも無い(Tailwind CSS・vanilla-extract は依存を足す)
- 旧の SCSS(幅の区切りなど)をほぼそのまま CSS に直せる
- 見た目の違いを変数に寄せられる。#294 の試作では、画面の CSS を変えずに変数だけで 3 案を切り替えられた
- oxlint は CSS を見ないので、ぶつからない。クラス名に型が付かないのは弱いところで、要るとなったら後から型を出す道具を足す

書き方と見た目の基準(色・余白・文字の大きさ・幅の区切り)は `docs/design/style.md` にある。

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
| `develop` | 開発のベース(既定のブランチ)。作業ブランチはここから切り、PR でここに戻す。push されるたびに dev 環境に出る |
| `staging` | ステージング用。`develop` から PR でマージすると、stg 環境へのデプロイが動く |
| `release` | リリース用。`staging` から PR でマージすると、本番へのデプロイが動く |
| `issue-<番号>-<短い名前>` | 作業ブランチ。issue から `gh issue develop <番号> --base develop --name issue-<番号>-<名前>` で作ると、issue に結び付く |

流れ: 作業ブランチ →(PR)→ `develop` →(PR)→ `staging`(stg 環境で確かめる)→(PR)→ `release`(本番)

- `develop`・`staging`・`release` には直接 push できない(管理者も)。PR からマージする(マージコミット)
- `staging` へは `develop` から、`release` へは `staging` からしか PR をマージできない(`branch-flow.yml` のチェックが落ちる)。ステージングに何を出したか・本番に何を出すかは、その PR の差分で見る
- `staging`・`release` への PR のタイトルは、`staging` 向けが作った日(`【運用】staging 2026-10-26`)、`release` 向けが本番に出す版(`【運用】release v1.2.0`。SemVer)。`release` にマージすると、その版のタグと GitHub Release ができる(`release-tag.yml`)。形と版の上げ方は `CLAUDE.md` の「ブランチ・コミット・PR」の 9
- `staging`・`release` への PR は、自動ではマージしない。レビューは CodeRabbit ではなく、PR を作った手元の Claude がする(`CLAUDE.md` の「ブランチ・コミット・PR」の 9)。CI が緑でそのレビューが済むと、`needs-approval` が付いて Slack に知らせが来るので、人が差分を見てマージする。AI レビューの指摘が残っていてもマージは止まらない(中身は `develop` に入る前にレビュー済み。指摘は昇格の PR では直さず、`develop` で直す issue にする)。`release` 向けの PR の CI は、lint とテスト(`backend`・`backend-lint`・`frontend`・`actionlint`)だけ
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

## dev 環境・stg 環境

Basic 認証で閉じた確認用の環境が 2 つある。中身は `.github/workflows/deploy-env.yml` で、環境ごとに別のディレクトリ・DB を使う(`develop` の migration が `staging` の DB を先に進めないように)。

| 環境 | URL | 出すブランチ | ワークフロー | アプリ本体 | DB |
|---|---|---|---|---|---|
| dev | https://cocktail-dev.trc-vlackhistory.net/ | `develop` | `deploy-dev.yml` | `~/cocktail-db-dev` | `<user>_cocktail_dev` |
| stg(ステージング) | https://cocktail-stg.trc-vlackhistory.net/ | `staging` | `deploy-stg.yml` | `~/cocktail-db-stg` | `<user>_cocktail_stg` |

- **dev**: `develop` に push される(PR がマージされる)たびに出す。`ai-auto-merge.yml` のマージは `GITHUB_TOKEN` なので push のワークフローが起きず、マージの後に `ai-auto-merge.yml` が `deploy-dev.yml` を直接呼ぶ。マージされた変更を、ステージングに出す前に画面で確かめる
- **stg**: `staging` にマージしたときに出す。`develop` から `staging` への PR を作り、その差分(前回ステージングに出したところからの変更)を見てマージする
- **出し直したいとき**: Actions の「deploy-dev」「deploy-stg」→「Run workflow」(ブランチはそれぞれ `develop`・`staging`)、または `gh workflow run deploy-dev.yml --ref develop`・`gh workflow run deploy-stg.yml --ref staging`。前回出したコミット(サーバーの `~/<アプリ本体>/REVISION`)と同じなら出さないので、同じコミットを出し直すとき(`.env` の中身を変えた後など)は `force` を付ける(`-f force=true`)。決まったブランチ以外からは出さない
- 流れは本番と同じ(テスト → FE のビルド → rsync → `.env` → `migrate` → `optimize` → 動作確認)。動作確認では、資格情報なしで 401 になることも確かめる
- `.env` はデプロイのたびに Secret から作り直す(手で直さない)。`APP_KEY` だけは初回にサーバーで作ったものを使い続ける
- Basic 認証の資格情報は dev と stg で同じ。ユーザーは cPanel の「ディレクトリのプライバシー」(`public_html/cocktail-db-dev`・`public_html/cocktail-db-stg`)で足す・消す。ドキュメントルートの 1 つ上にかけているので、デプロイで消えない

GitHub 側に要る設定(本番の `MIXHOST_*` も使う):

| 種類 | 名前 | 中身 |
|---|---|---|
| Secret | `DEV_DB_PASSWORD` | dev の DB ユーザー(`<user>_cocktail_dev`)のパスワード。単一引用符は使えない |
| Secret | `STG_DB_PASSWORD` | stg の DB ユーザー(`<user>_cocktail_stg`)のパスワード。単一引用符は使えない |
| Secret | `DEV_BASIC_AUTH` | 動作確認に使う Basic 認証の `<ユーザー名>:<パスワード>`(dev・stg 共通) |
| Variable | `DEV_APP_URL` | `https://cocktail-dev.trc-vlackhistory.net` |
| Variable | `STG_APP_URL` | `https://cocktail-stg.trc-vlackhistory.net` |

Secret は GitHub の画面(Settings → Secrets and variables → Actions)か、自分で開いたターミナルの `gh secret set <名前>` で入れる。入力を受け付けない場所(Claude Code の `!` など)で `gh secret set` を打つと、値が空のまま保存される。空のときは、デプロイの最初の check で止まる。

## サーバーの PHP の版

サーバーでは、画面と API のリクエストを処理する PHP(Web)と、デプロイが SSH で流す `php artisan`(CLI)の版が、別の仕組みで決まる。

- **Web**: `.htaccess` の `AddHandler application/x-httpd-ea-php85 .php .php8 .phtml` のような行で決まる。ドキュメントルートから上へたどって、最初に見つかった指定が効く。どの環境のディレクトリにも指定が無いときは、`~/public_html/.htaccess`(アカウント全体の既定。cPanel の MultiPHP Manager が書く)の版になる
- **CLI**: `php`(cPanel の `/usr/local/bin/php`)は、今いるディレクトリから上へたどってドメインのドキュメントルートに当たればそのドメインの版、当たらなければサーバー全体の既定の版で動く。アプリ本体の `~/cocktail-db*` は `public_html` の外にあるので、サーバー全体の既定になる(アカウントでは変えられない)。版を決めて動かすには、`/usr/local/bin/ea-php85` のような版の付いたコマンドを使う
- CI(`test.yml`)は PHP 8.5 でテストする。デプロイの `composer install`(`deploy.yml`・`deploy-env.yml` の `setup-php`)は、サーバーの CLI と同じ 8.4 のままにしている

2026-10-29 に確かめたときの版:

| 環境 | Web | CLI(`php artisan`) |
|---|---|---|
| dev・stg・本番 | 8.5(どの環境にも指定が無く、`~/public_html/.htaccess` の ea-php85 になっている) | 8.4(サーバー全体の既定) |

### 切り替えと切り戻し

dev → stg → 本番の順に、1 つずつ切り替えて確かめる。

1. **Web**: 環境のディレクトリの `.htaccess`(dev は `~/public_html/cocktail-db-dev/.htaccess`、stg は `~/public_html/cocktail-db-stg/.htaccess`、本番は `~/public_html/cocktail-db/.htaccess`。本番は無ければ作る)に、次のブロックを足す。Basic 認証と同じく、ドキュメントルート(`public` → アプリ本体の `public/`)の 1 つ上なので、デプロイで消えない。MultiPHP Manager でドメインごとに選ぶと、ドキュメントルートの `.htaccess`(アプリ本体の `public/.htaccess`)に書かれ、次のデプロイの `rsync --delete` で消えるので使わない

   ```apache
   # cocktail-db: この環境の PHP の版(README の「サーバーの PHP の版」)
   <IfModule mime_module>
     AddHandler application/x-httpd-ea-php85 .php .php8 .phtml
   </IfModule>
   ```

2. **CLI**: デプロイのワークフロー(本番は `deploy.yml`、dev・stg は `deploy-env.yml`)のサーバーで流す `php artisan` を、版の付いたコマンド(`/usr/local/bin/ea-php85 artisan`)にし、同じワークフローの `setup-php` の `php-version` も `'8.5'` にする。`deploy-env.yml` は dev と stg で共通なので、環境ごとに切り替えるには版を入力で渡せるようにする。これはワークフローを変える PR で行う
3. **確かめる**: その環境を出し直し(「dev 環境・stg 環境」の「出し直したいとき」)、デプロイの動作確認が通ること、画面と API に不具合が無いこと、`~/public_html/cocktail-db*/error_log` と `storage/logs/laravel.log` に新しいエラーや deprecation(非推奨の警告)が無いことを見る。デプロイの `composer install` は 8.4 のままなので、8.4 で入れた `vendor` を 8.5 の Web が読む状態になり、CI(`vendor` も 8.5 で入れる)では確かめられない。8.5 で変わった挙動が `vendor` 側にあっても、ここで初めて出るので、dev で先にログを見てから stg・本番に進む。Web の版は、`<?php echo PHP_VERSION;` だけのファイルをアプリ本体の `public/` に一時的に置いて開き、確かめたらすぐ消す(次のデプロイでも消える)
4. 問題が無ければ、次の環境に進む

切り戻すときは、その環境の 1 のブロックの `ea-php85` を `ea-php84` に書き換え、2 のコマンドと `php-version` を 8.4 に戻して出し直す。Web の指定は書き換えたときから効く。

## 撤去

1. GitHub の Secret・Variables を消す(リポジトリごと消すなら不要)
2. cPanel の「SSH アクセス」で鍵 `cocktail_db_deploy` を削除
3. cPanel の「ドメイン」で `cocktail-next.trc-vlackhistory.net` を削除
4. SSH で `~/cocktail-db` と `~/public_html/cocktail-db` を削除
5. dev 環境も消すなら: cPanel で `cocktail-dev.trc-vlackhistory.net`・DB `<user>_cocktail_dev` とそのユーザー・`public_html/cocktail-db-dev` のパスワード保護を削除し、SSH で `~/cocktail-db-dev` と `~/public_html/cocktail-db-dev` を削除。Secret の `DEV_*`・Variable の `DEV_APP_URL`・Environment の `dev` を消す。stg 環境も同じ形(`cocktail-stg`・`<user>_cocktail_stg`・`cocktail-db-stg`・`STG_*`・Environment の `stg`)
