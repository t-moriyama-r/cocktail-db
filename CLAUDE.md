# CLAUDE.md

このリポジトリで作業するエージェント・開発者向けの規約。仕組みの説明(起動・デプロイ・サーバーの配置)は `README.md` にあり、ここでは繰り返さない。

## このリポジトリは public

誰でも読める。次のものは **commit しない。issue・PR・コミットメッセージ・レビューの返信にも書かない**。

- SSH の接続先ホスト・サーバーのユーザー名・DB 名
- `/home/...` で始まる絶対パス(ユーザー名が入るため)
- `.env`・秘密鍵・トークン・パスワードなどの秘密の値、DB のダンプ、アップロードされた画像などの本番データ
- 証跡(スクリーンショット・動画・ログ)に写ったこれらのもの。貼る前に画像を目視で確かめる

次のものは書いてよい(`README.md` もこの範囲で書いている)。

- サービスの公開 URL(`README.md` に公開先として出しているもの)
- `~/` から始まるサーバー上の配置(`~/cocktail-db` など)
- GitHub の Secret・Variable の名前(値は書かない)
- cPanel に登録したデプロイ用の公開鍵の名前(秘密ではない)

接続先などは GitHub の Secret・Variable に置き(`README.md` の「デプロイ」)、サンプルが要るときはプレースホルダ(`<host>`・`<user>` など)で書く。

## 構成とテストのコマンド

| ディレクトリ | 中身 | テスト・チェック |
|---|---|---|
| `backend/` | Laravel(PHP 8.4)。`/api/*` の API と、SPA を返すルート | `php artisan test`(下の「BE のテスト」) |
| `frontend/` | React + Vite(TypeScript) | `npm test`(Vitest)、`npm run lint`(oxlint)、`npx tsc -b`(型チェック)、`npm run build`(`gen:api`・`tsc -b`・`vite build`) |
| `openapi/` | `backend` から出力した `openapi.json`(FE の型の元) | CI で最新かを確かめる(下の「API を変えたら」) |
| `e2e/` | Playwright の E2E。ビルドした FE を Laravel から配信して画面を確かめる | `npm test`(下の「E2E」) |
| `ops/` | (まだ無い)サーバーで動かす運用スクリプト(バックアップ・切り替えなど)。#14 などで作る | — |
| `docs/` | 開発の決まりごとの資料(ラベルの意味は `docs/labels.md`、旧システムを手元で動かす手順は `docs/legacy-local.md`) | — |

### BE のテスト

`README.md` の「テスト」と同じ。

```bash
cd backend
php artisan test --exclude-group=db   # 手元に MariaDB が無いとき。DB を使うテストを飛ばす
php artisan test                      # MariaDB があるとき(DB_* を環境変数で渡す)。CI はこちら
vendor/bin/pint                       # コード整形(Laravel Pint)
```

- DB を使うテストは `db` グループに入れ、本番と同じ MariaDB で動かす。SQLite で代用しない
- CI(`.github/workflows/test.yml`)は MariaDB 10.11 を立て、`db` グループも含めて全部流す。PR(`develop`・`release` 向け)と `develop` への push で動く。同じワークフローの `backend-lint` ジョブが、リポジトリにある PHP ファイルの構文(`php -l`)と書式(`vendor/bin/pint --test`)を確かめる。`frontend` ジョブが、FE の `gen:api`・型チェック・lint・`npm test` を流す

### FE のテスト

```bash
cd frontend
npm run gen:api   # 型の元を最新にする(テスト・型チェックの前に)
npx tsc -b        # 型チェック
npm run lint      # oxlint
npm test          # Vitest(vitest run)
```

### E2E

```bash
cd frontend && npm run build          # FE を backend/public/spa にビルドする(E2E はこれを相手にする)
cd ../e2e
npm ci && npx playwright install chromium   # 初回だけ
npm test                              # php artisan serve を起こして Playwright を流す。起動済みならそれを使う
```

- CI は `test.yml` の `e2e` ジョブ。MariaDB を立て、`php artisan migrate --seed --seeder=E2eSeeder` でデータを入れてから流す。E2E が前提にするデータは `E2eSeeder` に足す
- 失敗したときは、スクリーンショットと trace が `e2e-report` のアーティファクトに残る(`npx playwright show-trace <zip>` で開く)
- 画面の挙動を変える issue の受け入れ条件は、ここにテストを足して確かめる

### テストの書き方

- テストケースは、実装の前に表(ID・観点・技法・前提・操作・期待値・種別・テスト)で定義してから書く。表は PR 本文に載せる
- テスト名の先頭にケース ID を付ける(`TC-<issue 番号>-<連番 2 桁>`。例: Pest なら `it('TC-6-01: …')`)
- 自動テストで確かめられないもの(CI の挙動・画面の見た目など)は、種別を「CI」「手動」にして証跡で確かめる。確かめていないものは「未確認」と書く
- **旧システムの挙動を期待値の基準にする**。旧ソースを読んで推測した値ではなく、旧システムで同じ操作をして実際に出た結果を期待値にする。旧システムは手元で動かす(`docs/legacy-local.md`)
- 表に「旧での結果」列を足し、`同じ`(出た値も書く)/ `差分あり(意図: …)`(決めた issue・PR)/ `旧に無い` / `未確認(理由)` のどれかを書く。意図の書けない差分は回帰として扱い、旧に合わせるか issue で相談する。旧の不具合を勝手に直さない

## API を変えたら

BE の API の形(ルート・リクエスト・レスポンス)を変えたら、同じ PR で次を行う。

```bash
cd backend && php artisan scramble:export --path=../openapi/openapi.json
cd ../frontend && npm run gen:api     # src/api/schema.ts を作り直す(npm run build でも先に走る)
```

`openapi/openapi.json` を更新し忘れると、CI の「Check OpenAPI is up to date」が落ちる。FE から API を呼ぶときは、生成した型と openapi-fetch を使い、型を手で書かない。

## カテゴリ木

- カテゴリ木(閉包テーブル `category_closures`)は、`CategoryTree` サービス(`app/Services/CategoryTree.php`)経由でだけ書き換える。閉包テーブルを直接 INSERT・UPDATE・DELETE しない
- `CategoryTree` は **まだ無い**。#24 で作る。それより前の issue で閉包テーブルを書き換える必要が出たら、直接書かずに #24 を待つか、issue で相談する

## 旧ソースの参照

- リプレイス元の旧アプリのソースは、秘密情報を取り除いて **private のリポジトリ `t-moriyama-r/cocktail-legacy`** に置いてある(参照専用。このリポジトリには入れない)。clone して読む
- 本番の BE は `laravel13/`、本番の画面のビルドは `production-public/`、画面の Vue のソースは `laravel6/resources/js/`。旧と同じ挙動を確かめるときに見るのは本番の版で、手元の古い版と違うところは cocktail-legacy の README の「本番との違い」にある
- GitHub Actions のエージェントからは、まだ読めない(このリポジトリの `GITHUB_TOKEN` では別の private リポジトリを読めない)。旧実装を見る必要がある issue は、手元で進める
- 旧ソースから写すときも、絶対パス・サーバー名・DB 名・外部サービスの設定名をこのリポジトリに持ち込まない
- 旧システムを手元で動かす手順は `docs/legacy-local.md`

## ブランチ・コミット・PR

ブランチとコミットの決まりは `README.md` の「ブランチ」のとおり。要点と、PR の流れ:

1. 1 PR = 1 issue。ついでの修正を混ぜない(見つけたら別の issue にする)
2. ブランチは issue から `develop` を元に作る: `gh issue develop <番号> --base develop --name issue-<番号>-<短い英名> --checkout`
3. コミットメッセージは `issue-<番号>: <何をしたか>` で始め、本文に `Refs #<番号>` を書く
4. PR は `develop` 向けに下書き(draft)で作り、本文の先頭に `Closes #<番号>` を書く。作った人を assignee にする
5. 本文にテストケースの表と結果、確かめたこと(コマンドと結果)を書く。**画面に出る挙動が変わるなら、修正前後のスクリーンショット・動画・比較画像(修正前後を並べた画像)の 3 つをすべて貼る**。どれか 1 つで済ませない。画面に出ない変更(API だけ・DB だけ・テストだけ・ドキュメントだけ)は、撮らない理由を書く
6. CI が通ったら ready にし、レビュアーを動かす。レビュアーは `.github/ai-dev.yml` の `reviewer`(今は CodeRabbit。設定は `.coderabbit.yaml`)。OSS のリポジトリでは CodeRabbit が自動で動かないので、PR に `@coderabbitai review` とコメントして頼む。CI が通っても draft のまま残った PR は、`ai-draft-sweep.yml` が毎日見て、決まりを満たしていれば ready にしてレビューを頼む(満たさなければ足りないものをコメントする)
7. 指摘への対応は、手元のエージェントではなく GitHub Actions の Claude Code(`ai-review-loop.yml`)が行う。レビューを頼んだら手元の作業はそこで終える。Actions は、指摘を直すか、直さない理由をスレッドに返信する。判断が要るものは、何を決めてほしいかを PR にコメントしてから `needs-human` ラベルを付ける(Slack に通知が行く)。返されないまま残った指摘は、`ai-review-sweep.yml` が 3 時間ごとに見つけて対応させる
8. エージェントは自分でマージしない。指摘の対応が終わって CI が緑になった PR を、`ai-auto-merge.yml` がマージする。人の手でマージする PR(`.github/ai-dev.yml` が `merge: auto` でない・`no-auto-merge` が付いている・閉じる issue に `needs-review` が付いている)は、マージせずに `needs-approval` を付けて Slack に知らせる。人の番になるのは Slack に知らせが来たときだけ(`needs-human`: 途中で判断が要る、`needs-approval`: マージできる状態になった、`needs-decision`: issue の着手前に決めることがある)。返事をしないまま残ったものは、毎朝まとめて知らせ直す
9. `develop` → `staging`(dev 環境に出る)→ `release`(本番に出る)の順に PR でマージする。`staging`・`release` へのマージはデプロイになるので、人が行う。`staging` へは `develop` から、`release` へは `staging` からしか出せない(`branch-flow.yml`)

ラベルの意味の詳しい説明は `docs/labels.md` を見る。

## 禁止事項

- **このプロジェクトでは GitHub Copilot を使わない**。コード補完・Copilot のコードレビュー・「Fix with Copilot」「Explain error」・Copilot への issue のアサイン、すべて。レビュアーは CodeRabbit か Claude(`.github/ai-dev.yml` の `reviewer`)
- 上の「このリポジトリは public」に挙げたものを commit しない・書かない
- `develop`・`release` に直接 push・force push しない(保護で拒否される)
- 閉包テーブルを `CategoryTree` を通さずに書き換えない
- DB を使うテストを SQLite で代用しない
- エージェントは PR をマージしない

## サブディレクトリの CLAUDE.md

`backend/CLAUDE.md`・`backend/AGENTS.md` は、Laravel の雛形が置いた Laravel Boost の導入案内で、このリポジトリの規約ではない(`composer.json` に Boost は入っていない)。この CLAUDE.md の規約を優先し、issue に無い作業として Boost を入れない(入れるなら別の issue にする)。
