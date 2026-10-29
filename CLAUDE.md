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
| `backend/` | Laravel(PHP。CI は 8.5、サーバーの版は `README.md` の「サーバーの PHP の版」)。`/api/*` の API と、SPA を返すルート | `php artisan test`(下の「BE のテスト」) |
| `frontend/` | React + Vite(TypeScript) | `npm test`(Vitest)、`npm run lint`(oxlint)、`npx tsc -b`(型チェック)、`npm run build`(`gen:api`・`tsc -b`・`vite build`) |
| `openapi/` | `backend` から出力した `openapi.json`(FE の型の元) | CI で最新かを確かめる(下の「API を変えたら」) |
| `e2e/` | Playwright の E2E。ビルドした FE を Laravel から配信して画面を確かめる | `npm test`(下の「E2E」) |
| `ops/` | サーバーで動かす運用スクリプト(`ops/backup`: バックアップ・世代管理・復元の検証(決まりと入れる手順は `docs/backup.md`)、`ops/migrate`: 移行と、旧 DB のダンプの確かめ(`docs/legacy-local.md` から呼ぶ)) | CI の `scripts`・`ops-db` ジョブ(下の「BE のテスト」) |
| `docs/` | 開発の決まりごとの資料(ラベルの意味は `docs/labels.md`、旧システムを手元で動かす手順は `docs/legacy-local.md`、画面のスタイルの書き方と見た目の基準は `docs/design/style.md`) | — |

### BE のテスト

`README.md` の「テスト」と同じ。

```bash
cd backend
php artisan test --exclude-group=db   # 手元に MariaDB が無いとき。DB を使うテストを飛ばす
php artisan test                      # MariaDB があるとき(DB_* を環境変数で渡す)。CI はこちら
vendor/bin/pint                       # コード整形(Laravel Pint)
```

- DB を使うテストは `db` グループに入れ、本番と同じ MariaDB で動かす。SQLite で代用しない
- CI(`.github/workflows/test.yml`)は MariaDB 10.11 を立て、`db` グループも含めて全部流す。PR(`develop`・`staging`・`release` 向け)と `develop` への push で動く。draft の PR では全ジョブを飛ばし、ready にしたときから流す(#410)。`release` 向けの PR は、lint とテストの確認(`backend`・`backend-lint`・`frontend`・`actionlint`)だけで、`e2e`・`scripts`・`ops-db` は飛ばす。ブランチ保護の必須のチェックは、`develop` が `backend`・`backend-lint`・`frontend`・`actionlint`・`e2e`、`staging` が `branch-flow` だけ、`release` が `backend`・`backend-lint`・`frontend`・`actionlint`・`branch-flow`(`e2e` は飛ばすので入れない)。`staging`・`release` は「マージ前にブランチを最新にする」(`strict`)も求めない。昇格の PR の head は `develop`(`staging`)そのもので常に先へ進み、CI の必須のチェックや `strict` があると、進むたびに CI の待ちでマージが止まるため(中身は `develop` に入る前に PR ごとに CI を通っている。#506)。同じワークフローの `backend-lint` ジョブが、リポジトリにある PHP ファイルの構文(`php -l`)と書式(`vendor/bin/pint --test`)を確かめ、`vendor/bin/phpstan analyse` で型を確かめる(設定は `backend/phpstan.neon`)。`frontend` ジョブが、FE の `gen:api`・型チェック・lint・`npm test` を流す。`actionlint` ジョブが、`.github/workflows/` の YAML の誤りと、`run` に書いたシェルの誤り(shellcheck の warning 以上)を確かめる(下の「ワークフローを変えたら」)。`scripts` ジョブが `.github/scripts` と `ops/migrate` の DB を使わないシェルスクリプトの検査とテストを、`ops-db` ジョブが MariaDB を使う `ops/backup`・`ops/migrate` のテストを流す(どちらも必須のチェックではない)

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
- 表に「旧での結果」列を足し、`同じ`(出た値も書く)/ `差分あり(意図: …)`(根拠。下の「旧との差分」)/ `旧に無い` / `未確認(理由)` のどれかを書く。意図の書けない差分は回帰として扱い、旧に合わせるか issue で相談する。旧の不具合を勝手に直さない

#### 旧との差分

旧と挙動を変えると決めたものは、決めたところに「旧との差分」の節を置き、テストの表からそこを指す。意図した仕様変更と回帰を、読む人によらず見分けられるようにするため。

- 旧と挙動を変えると決めたら、決めた issue の本文か、`docs/decisions/` の文書に「旧との差分」の節を置く(見出しは `旧との差分`。決定を別の issue から引くときは `旧との差分(意図: #<決めた issue>)`)。差分ごとに、旧で何が起きていて、新で何に変えるかを 1 行で書く
- 条件検索の差分は、`docs/search-expected-diffs.md` の、決めた issue ごとの節を「旧との差分」の節として扱う(`legacy:compare-search` が機械的に確かめる)
- `差分あり(意図: …)` には、その節を持つ issue か文書(`docs/decisions/` の文書か `docs/search-expected-diffs.md`)を書く(例: `差分あり(意図: #247)`・`差分あり(意図: docs/decisions/recipe-count.md)`)。節の無い issue・PR を指してはいけない
- PR 本文の表の下に「旧との差分の根拠」の節を置き、`差分あり` の行ごとに、指した節の 1 行をそのまま引く(例: `TC-247-03 → #247: 旧は… → 新は…`)。レビュアーは issue の本文を読めないことがある(CodeRabbit で確かめた。#273)ので、PR 本文だけで根拠を確かめられるようにする
- 実装中に、どの節にも無い差分を見つけたら、旧に合わせるか、issue で相談する(意図の書けない差分と同じ扱い)
- レビュー(CodeRabbit の `.coderabbit.yaml`・代わりの Claude の `ai-claude-review.yml`)も、表に「旧での結果」の列があるか、`差分あり` に根拠と引用があるか、表に無い差分がコードに入っていないかを確かめる

#### モックの範囲と確かめる対象

テストは、振る舞いを変えないリファクタでは落ちないように書く。自分たちのモジュールをモックしたり、呼び出しの回数を確かめたりするテストは、中の作りを変えただけで落ちてテストごと直すことになり、リファクタの担保にならないため。

- **モックは境界だけにする**。差し替えてよいのは、プロセスの外にあって自分たちが持っていないもの(外部 API・時刻・乱数・メール送信など。Laravel なら `Http::fake`・`Mail::fake`・`travelTo` など)。自分たちのクラス・モジュール・フックはモックしない。DB は本物の MariaDB を使う(上の「BE のテスト」)
- **確かめるのは外から見える結果**(返り値・HTTP の応答・DB に残った行・画面に出た文字や役割)。内部の state・private なメソッドの結果には頼らない。呼び出しの回数・引数を確かめるのは、外への送信そのものが仕様のとき(ログに残す、メールを送る、外部 API に送る中身、など)だけ
- **FE の API は `fetch` の層で差し替え**、スキーマで定義された応答には `src/api/schema.ts` の型を当てる(手で型を書かない。スキーマにないエラー応答は型指定の対象外)。差し替える client は、`baseUrl` を `http://localhost/api` のような絶対 URL にしたスキーマ型の client にする(相対の `/api` の client に `fetch` だけを差し替えても届かない)。差し替えの仕組みはテストのセットアップの 1 か所に置き、テストのファイルごとに作らない
- **振る舞いを変えないリファクタの PR では、既存のテストを変えずに通る**ことを確かめる。テストを直さないと通らないなら、振る舞いが変わっているか、テストが中の作りに依存している
- レビュー(CodeRabbit の `.coderabbit.yaml` のテストのパスへの指示・代わりの Claude の `ai-claude-review.yml`)も、自前のモジュールのモック・呼び出し回数の検証・内部の state への依存が無いかを確かめる

## ワークフローを変えたら

`.github/workflows/` を変えたら、push の前に手元で actionlint を流す(CI の `actionlint` ジョブと同じ版・同じ設定)。Git Bash では、パスの書き換えを止めるため先頭に `MSYS_NO_PATHCONV=1` を付ける。

```bash
docker run --rm -e SHELLCHECK_OPTS=--severity=warning -v "$PWD:/repo" -w /repo rhysd/actionlint:1.7.12
```

## レビューで繰り返し出た指摘

2 件以上の PR のレビューで出た種類の指摘は、`docs/review-checklist.md` にルールとして置いてある。コードを書く前と、PR にする前に読み、当てはまるところを直す。レビューの指摘を直すとき・コンフリクトを解くときも同じ。

## API を変えたら

BE の API の形(ルート・リクエスト・レスポンス)を変えたら、同じ PR で次を行う。

```bash
cd backend && php artisan scramble:export --path=../openapi/openapi.json
cd ../frontend && npm run gen:api     # src/api/schema.ts を作り直す(npm run build でも先に走る)
```

`openapi/openapi.json` を更新し忘れると、CI の「Check OpenAPI is up to date」が落ちる。FE から API を呼ぶときは、生成した型と openapi-fetch を使い、型を手で書かない。

## カテゴリ木

- カテゴリ木(閉包テーブル `category_closures`)は、`CategoryTree` サービス(`app/Services/CategoryTree.php`)経由でだけ書き換える。閉包テーブルを直接 INSERT・UPDATE・DELETE しない
- `CategoryTree` に無い書き換えが要るときは、閉包テーブルを直接書かずに `CategoryTree` にメソッドを足す。足すのがその issue の範囲を超えるなら、issue で相談する

## 旧ソースの参照

- リプレイス元の旧アプリのソースは、秘密情報を取り除いて **private のリポジトリ `t-moriyama-r/cocktail-legacy`** に置いてある(参照専用。このリポジトリには入れない)。clone して読む
- 本番の BE は `laravel13/`、本番の画面のビルドは `production-public/`、画面の Vue のソースは `laravel6/resources/js/`。旧と同じ挙動を確かめるときに見るのは本番の版で、手元の古い版と違うところは cocktail-legacy の README の「本番との違い」にある
- GitHub Actions のエージェントからは、まだ読めない(このリポジトリの `GITHUB_TOKEN` では別の private リポジトリを読めない)。旧実装を見る必要がある issue は、手元で進める
- 旧ソースから写すときも、絶対パス・サーバー名・DB 名・外部サービスの設定名をこのリポジトリに持ち込まない
- 旧システムを手元で動かす手順は `docs/legacy-local.md`

## ブランチ・コミット・PR

ブランチとコミットの決まりは `README.md` の「ブランチ」のとおり。要点と、PR の流れ:

1. 1 PR = 1 issue。ついでの修正を混ぜない(見つけたら別の issue にする)
   - issue・PR のタイトルの先頭に、対象を示す接頭辞(`【BE】`・`【FE】`・`【CI】`・`【移行】`・`【運用】`)を付ける。`area:*` ラベルとの対応は `docs/labels.md` の「タイトルの接頭辞」。PR のタイトルは、閉じる issue と同じ接頭辞の後に `issue-<番号>: <何をしたか>` を続ける(例: `【BE】issue-90: …`)
2. ブランチは issue から `develop` を元に作る: `gh issue develop <番号> --base develop --name issue-<番号>-<短い英名> --checkout`
3. コミットメッセージは `issue-<番号>: <何をしたか>` で始め、本文に `Refs #<番号>` を書く
4. PR は `develop` 向けに下書き(draft)で作り、本文の先頭に `Closes #<番号>` を書く。作った人を assignee にする。同じファイルを変える PR がまだ開いていて、その上に積むときだけ、ブランチと PR の向き先を土台の PR のブランチにする(土台を `ai-auto-merge.yml` が自動でマージすると、積んだ PR の向き先を `develop` に付け替えてから土台のブランチを消し、積んだ PR の CI を流し直す。人がマージしてブランチを消したときは GitHub が付け替え、`ci-rerun.yml` が 1 時間以内に CI を流し直す)
5. 本文にテストケースの表と結果、確かめたこと(コマンドと結果)を書く。**FE・BE のコード(文書・テスト・CI の設定・`ops/`・ロックファイル以外)を変えたら、何がどう変わったかを、修正前後のスクリーンショット・動画・比較画像(修正前後を並べた画像)の 3 つで「## 証跡」に貼る**。どれか 1 つで済ませない。差分のコードと文章だけでは、レビューする人が実際にどう変わったかを確かめられないため
   - 撮るもの: 画面に出る変更は画面。画面の無い BE(API)は、修正前と修正後に同じリクエストを `curl -si` で送った応答(evidence-capture の `http2html.js` で HTML にして撮る。新しい API なら修正前は 404)。API にも出ない変更(マイグレーション・モデル・サービスの中だけ)は、`SHOW CREATE TABLE` や `php artisan tinker` で呼んだ結果、その変更を確かめるテストの実行結果。修正前は PR の base のコミットを別の worktree に取り出して動かす
   - FE の画面を変える PR は、幅の区切りごとの「証跡を撮る幅」(`docs/design/style.md` の「幅の区切り」。xs 375px・sm 768px・md 1024px・lg 1440px)の 4 つすべてで、修正前後の静止画を撮る。比較画像は幅ごとに 1 枚。動画は、操作で変わるものがある幅で撮る(少なくとも lg と xs)。区切りの境目(599/600px・959/960px)で出し方を変える CSS を足した・変えたときは、境目の両側も撮る。幅によって変わらないことが明らかなもの(文言だけの変更など)でも、4 つの幅は撮る(省くと、崩れていないことを確かめていない証跡になる)。「## 証跡」には撮った幅を `<数字>px` で書く。画面に出ない FE の変更(純関数・型・テストだけ)で `.tsx`・`.css` を変えたときは、本文に `<!-- ai-dev:no-viewport-evidence 理由 -->` を書く。BE だけの PR(画面が無い)は対象外(#432)
   - 文書・テスト・CI の設定だけの変更は撮らなくてよいが、撮らない理由を「## 証跡」に書く(CI の変更は CI の画面を撮る)
   - 撮れない環境(ブラウザ・PHP が無いなど)では、「撮れなかった」と書いて ready にしない。draft のまま、何を撮ってほしいかを PR にコメントして `needs-local`(見回り待ち。人の判断も手も要らない)を付ける。コメントの先頭には `<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=evidence -->` を付ける(`ai-draft-sweep.yml` は `needs-local` の付いた draft を拾わない。手元の見回りが撮って貼る)
   - `evidence-gate.yml` が、ready の PR でこれを確かめる(決め方は `.github/scripts/evidence-gate.sh`。`frontend/src/` の `.tsx`・`.css` を変えた PR では、4 つの区切りそれぞれに入る幅が「## 証跡」に書かれているかも見る)。赤のあいだは `ai-auto-merge.yml` がマージしない
   - **見た目の案を人に選んでもらう PR**(閉じる issue に `needs-review` が付き、PR 本文で色・余白・レイアウト・スタイルの方式などの案を並べて選んでもらうもの。例: #348)は、決着したら、本文に「最終的にどうなったか(証跡)」の節を置き、同じ中身を PR の最後のコメントにも貼る。選ぶ前の案の画像だけでは、何に決まって最終的にどう見えるのかが PR から分からないため
   - 節に書くのは、決まったこと(どのコメントで誰が決めたか)、まだ決まっていないこと、決まった後に変えたところ(レビュー対応で色を変えた、など)、最終の見た目の証跡(決める前 → 最終の比較画像と、最終の見た目の動画)。見た目が変わるたびに(人の判断を反映した・レビュー対応で直した)撮り直して差し替える
   - Actions の Claude Code は画面を撮れない。この種の PR で見た目を変えたら、節を文字で書いたうえで、何を撮ればよいかをコメントして `needs-local` を付ける(`ai-review-loop.yml`)。撮って貼るのは手元のエージェントで、貼ったら `needs-local` を外す(外すと `ai-auto-merge.yml` がマージできる状態かを見直す)。貼るコメントの先頭には `<!-- ai-dev:agent -->` を付ける(オーナーのアカウントで書いた印の無いコメントは、Actions に人の返信として拾われる)
6. 実装が終わって手元のテストが通り、証跡を貼ったら ready にし(ready の前に手元で `.github/scripts/evidence-gate.sh <owner/repo> <番号>` が通ることを確かめる)、CI を待つ。CI(`test.yml`)は draft では全ジョブを飛ばし、ready にしたときから流れる(#410)。落ちたら直して push し、緑になったらレビュアーを動かす。レビュアーは `.github/ai-dev.yml` の `reviewer`(今は CodeRabbit。設定は `.coderabbit.yaml`)。OSS のリポジトリでは CodeRabbit が自動で動かないので、PR に `@coderabbitai review` とコメントして頼む。ready にするのは PR を作ったエージェントの役目(実装が終わったら ready にする)。エージェントが途中で落ちて draft のまま残った PR は、`ai-draft-sweep.yml` が 1 時間ごとに見て(最後のコミット・コメントから 1 時間たったものを、実装したエージェントが落ちたとみなす。GitHub の `schedule` が遅れた・落とされたときは、PR に動きがあれば `patrol-kick.yml` が起こす)、Actions の Claude Code が引き継いで仕上げ、ready にしてレビューを頼む(判断が要るものは、ready にせず `needs-human` を付ける。証跡が要るだけのものは `needs-local` を付ける)
7. 指摘への対応は、手元のエージェントではなく GitHub Actions の Claude Code(`ai-review-loop.yml`)が行う。レビューを頼んだら手元の作業はそこで終える。Actions は、指摘を直すか、直さない理由をスレッドに返信する。判断が要るものは、何を決めてほしいかを PR にコメントしてから `needs-human` ラベルを付ける(Slack に通知が行く)。返されないまま残った指摘は、`ai-review-sweep.yml` が 2 時間ごと(PR に動きのある日は、CI の終わりに `patrol-kick.yml` が前の回から 1 時間たったところで起こすので 1 時間ごと)に見つけて対応させる。`develop` とコンフリクトした ready の PR(draft は選ばない)は、`ai-conflict-resolve.yml` の Claude Code が 1 時間ごとに見つけて `develop` を取り込み(rebase・force push はしない)、CI と代わりのレビューを流して流れに戻す。両方の意図を残せない衝突は、何を決めてほしいかを PR にコメントして `needs-human` を付ける
8. エージェントは自分でマージしない。指摘の対応が終わって CI が緑になった PR を、`ai-auto-merge.yml` がマージする。人の手でマージする PR(`.github/ai-dev.yml` が `merge: auto` でない・`no-auto-merge` が付いている・閉じる issue に `needs-review` が付いている)は、マージせずに `needs-approval` を付けて Slack に知らせる。自動でマージする PR でも、`gh pr merge` が GitHub に断られたら、エラーの 1 行を PR にコメントして `needs-approval` を付け、Slack に知らせる(#218)。人の番になるのは Slack に知らせが来たときだけ(`needs-human`: 途中で判断が要る、`needs-approval`: マージできる状態になった、`needs-decision`: issue の着手前に決めることがある)。`needs-local`(証跡の撮影など見回り待ち)は、人の判断も手も要らず、PC を開いたときに手元の見回りが片付けるので、Slack に知らせない(#516)。`needs-manual`(Secret の設定・ログインなど、人が手を動かさないと進まない手元の作業待ち)は、待っていても進まないので、人が済ませて外す。`needs-local` は、待っていれば見回りが片付ける作業だけに付け、人の手が要る作業には `needs-manual` を付ける(#496)。返事をしないまま残ったものは、毎朝まとめて知らせ直す
9. `develop`(dev 環境に出る)→ `staging`(stg 環境に出る)→ `release`(本番に出る)の順に PR でマージする。`staging`・`release` へのマージはデプロイになるので、必ず人が行う(自動ではマージしない)。`staging` へは `develop` から、`release` へは `staging` からしか出せない(`branch-flow.yml`)。
   - 昇格の PR のタイトル(一覧で、いつの分か・本番のどの版かを分かるようにするため。#334)
     - `staging` 向け: `【運用】staging <PR を作った日 YYYY-MM-DD>`(例: `【運用】staging 2026-10-26`)。補足は後ろに括弧で足してよい(例: `【運用】staging 2026-10-26(stg 環境への切り替えを含む)`)。機械では確かめない
     - `release` 向け: `【運用】release v<MAJOR>.<MINOR>.<PATCH>`(例: `【運用】release v1.2.0`)。補足は足さない(足すと下のチェックが落ちる)。形が合わない・版が前のタグ以下のときは、`branch-flow.yml` の `branch-flow` が落ちる(タイトルを直すと流れ直す)
   - 版の番号は SemVer で、PR を作る人(人かエージェント)が、前のタグ(`git tag -l 'v*'`)と差分から決めてタイトルに書く。前のタグから 1 つだけ上げ、上げた位置より下の数は 0 に戻す
     - MAJOR: 旧システムからの切り替えのような、利用者から見て大きく変わるとき。最初の本番の版は `v1.0.0` にする
     - MINOR: 機能を足したとき
     - PATCH: 不具合の修正・見た目の調整だけのとき
   - `release` 向けの PR をマージすると、`release-tag.yml` がタイトルの版でマージコミットにタグ(`v1.2.0`)を打ち、GitHub Release(本文は自動生成のリリースノート)を作る。デプロイ(`deploy.yml`)とは別のワークフローで、デプロイの成否とは関係なく作る
   - 昇格の PR は必ず手元のエージェントが作る。**レビューは CodeRabbit ではなく、作った手元の Claude がする**(#479)。PR を作ったら、`base...head` の差分を読み、`gh pr review <番号> --comment` で、本文の先頭に `<!-- ai-dev:agent --> <!-- ai-dev:local-review -->` を付けたレビューを書く(指摘が無くても書く。指摘は本文に並べる)。Actions(`request-review.sh`)は昇格の PR に CodeRabbit・代わりの Claude のレビューを頼まず、`ai-auto-merge.yml` はこの目印のレビューだけをレビュー済みとみなす(`head-reviewed.sh`)。head は `develop`(`staging`)そのもので常に先へ進むので、head に後からコミットが入ってもレビューし直さない(増えた分は `develop` に入る前に PR ごとにレビュー済み。`head-reviewed.sh` も、いまのコミットへのものでなくても目印のレビューが 1 つあればレビュー済みとみなす。#506)
   - この PR(昇格の PR)の中身は、`develop` に入る前に PR ごとにレビュー済み。指摘が残ってもマージは止めず、Actions の Claude Code は直さない(head が保護されたブランチ(`develop`・`staging`)なので直せない)。`needs-human` も付かない
   - **指摘は昇格の PR では直さない**(head は保護されたブランチで、直すと `develop` との差分ができる)。レビューを書いた手元のエージェントが、次のように片付ける(#506)
     - 妥当な指摘は、1 件ずつ `develop` で直す issue にする
     - レビューの後に、指摘ごとの issue の URL を並べたコメント(先頭に `<!-- ai-dev:agent -->`)を昇格の PR に書いて、指摘を閉じる。妥当でない指摘は、妥当でない理由をそのコメントに書く
     - 公開できない指摘(セキュリティの穴)は issue にせず、下書きの security advisory にする(このリポジトリは public なので、issue・コメントに中身を書かない)
   - CI が緑で、手元の Claude がレビューし終えると、`ai-auto-merge.yml` が `needs-approval` を付けて Slack に知らせる。残っている指摘の件数も PR にコメントされる。人は差分と指摘を見て、マージするか決める

ラベルの意味の詳しい説明は `docs/labels.md` を見る。

## URL の直後は必ず空白か改行にする

URL を地の文に書くときは、**直後を必ず半角スペースか改行にする**。括弧・句読点・日本語・バッククォートを URL に続けて書いてはいけない。地の文の URL に例外は無い(下の Markdown リンクと Slack 形式は、URL の範囲が記号で閉じているので対象外)。下のコードブロックの「悪い」「良い」の例と、`https://…` のように URL の形だけを示したものは例示で、この決まりの対象外。

GitHub・Slack・ターミナルの自動リンクは、空白までを URL として拾う。`https://…/pull/449#discussion_r4398741625(` のように 1 文字でも続くと、その文字まで含んだ 404 のリンクになる(#449 で起きた)。

- 対象は、エージェントが書くもの(issue・PR の本文・コメント・レビューの返信・コミットメッセージ・ユーザーへの返答)と、スクリプト・ワークフローが組み立てる本文(`gh pr comment` の `--body`・Slack の文面など)のすべて
- URL は行末に置くのを基本にする。補足は URL の前に書くか、空白を挟んで後ろに書く
- URL を括弧で囲まない。補足を括弧で書くなら、URL との間に空白を入れる
- スクリプトで URL に文字をつなげて組み立てるときも同じ。`"\(.url)(…)"` ではなく `"\(.url) (…)"` にする
- Markdown のリンク(`[テキスト](URL)`)と Slack の `<URL|テキスト>` は、URL の範囲が記号で閉じているので、この決まりの対象外

```text
悪い: - https://github.com/o/r/pull/1#discussion_r1(`a.yml`)
悪い: 詳しくは https://github.com/o/r/pull/1を見る
悪い: PR(https://github.com/o/r/pull/1)を見る
良い: - https://github.com/o/r/pull/1#discussion_r1 (`a.yml`)
良い: - `a.yml` のスレッド: https://github.com/o/r/pull/1#discussion_r1
```

## 人に判断を頼むとき

人に決めてもらうこと(`needs-human`・`needs-decision` の頼むコメント、issue の「判断が要ること」、手元のエージェントがターミナルで聞くとき)は、文章を読み込まなくても、何を決めるのか・案ごとに何が変わるのかがつかめる形で頼む。文章だけの説明は、理解するまでに時間がかかるため。

頼むコメントは、上から次の順に書く。

1. **1 行目に、何を決めてほしいかを 1 文で**。Slack の通知(先頭の 600 文字)と毎朝のまとめ(1 行目)に出る。目印のコメント(`<!-- ai-dev:… -->`)はこの前に置いてよい
2. **図**。案ごとに何がどう変わるかを、Mermaid のコードブロック(` ```mermaid `。GitHub が図にする)で描く。処理の流れ・ラベルや状態の移り変わり・どのワークフローがどれを呼ぶかはフローチャートかシーケンス図、案の違いは案ごとに並べた図にする。図にしても分かりやすくならない判断(はい・いいえで答えられる 1 点だけ、など)は省いてよい
   - ラベルの字数: ノードは全角 12 字まで、矢印のラベルは全角 6 字まで(半角は 0.5 字と数える)。長くなるときは `<br>` で自分で折る。折ったノードは 1 行を全角 7 字までにする(箱の幅が全角 7 字ほどで、それより長い行は端末のフォントの差ではみ出す)。GitHub の Mermaid は文字の幅を測ってから箱の大きさを決め、矢印のラベルの箱は文字の幅ちょうどで余白が無いので、見る人の端末のフォントが少し広いだけで末尾が切れる(切れる幅はラベルの長さに比例する。#478 で確かめた)
   - 説明の文は図に入れず、比較表に書く。図には「何がどこへ流れるか」だけを残す。ファイル名・コマンドのような区切りの無い長い英数字は、箱の中で折り返せずにはみ出すので、図に入れない
   - Actions の Claude Code が書いた頼むコメントは、後の手順が `.github/scripts/mermaid-labels-comments.sh` で数え、上限を超えたラベルをログに警告で出す。手元では、書いた本文のファイルを `bash .github/scripts/mermaid-labels.sh <ファイル>` で確かめてから投稿する
3. **案を並べた比較表**。列に案(A・B・…)、行に「こう選ぶと、こうなる」「その代わり、ここが困る」、最後の行におすすめと理由
4. **結果が画面に出る判断は、案ごとに「この案だと、この画面はこう見える」**。画面に出るもの(並び・出す値・文言・色・余白・レイアウト・操作)が案によって変わるなら、ロジックの判断に見えても対象にする(例: #427 の並べ替えで、`-` と出す行を最後に置くか。案ごとに表の並びが変わる)。図と比較表だけでは、選んだ後に画面がどうなるのかが分からないため
   - 手元のエージェントは、案ごとに同じ画面・同じ幅・同じデータで撮ったスクリーンショット・動画と、案を並べた比較画像を貼る。違うところは枠と吹き出しで示す。まだ実装していない案も、手元で一時的に書き換えて撮る
   - Actions の Claude Code は画面を撮れないので、案ごとの見え方を文字で再現したもの(表なら、実際のデータで案ごとに上から数行を並べた Markdown の表。文言なら、案ごとの出る文)を載せる。そのうえで、撮ってほしいもの(画面・幅・データ・どの案か・比べるコミット)を書いて手元に頼む(上の手順 5 と同じ)。手元のエージェントが撮って、先頭に `<!-- ai-dev:agent -->` を付けたコメントで貼る
5. **短く答えられる形**(「おすすめで」「B で」「2 だけ B で」など)
6. 経緯や細かい理由など、文章での説明は最後に置き、`<details><summary>詳しく</summary>…</details>` に畳む

設計の用語や記号に頼らず、このアプリで実際に起きる場面を具体例に、話し言葉で書くのは今までどおり。

手元のエージェントがターミナルで聞くときは、選択肢を出す前に、図や案ごとのモックアップを載せたページ(HTML)か、結果が画面に出る判断なら案ごとのスクリーンショット・動画・比較画像を作って見せる。

## 文書と既存コードが食い違うとき

CLAUDE.md・`README.md`・`docs/` の記述と、既存コードの実際の書き方が食い違っているときは、**既存コードの書き方に従う**。文書は古くなっていることがある。

ただし、これはコードの書き方・命名・構成などの作法の食い違いに限る。作法とは、命名・ディレクトリ構成・コードスタイル・書式のこと。この文書で「〜しない」「〜する」と明示した決まり(「禁止事項」「このリポジトリは public」「カテゴリ木」のほか、API の型を手で書かない・テスト名にケース ID を付ける・DB テストを SQLite で代用しない・旧システムの実測を期待値にする、など)には当てはめない(既存コードが違反していても従わず、食い違いとして挙げる)。

- 見つけた食い違いは、作業の最後の返答(PR の本文ではなく、エージェントがユーザーに返す最後の返答)に**すべて**挙げる。どの文書のどの記述と、どのコードの書き方が食い違うか、どちらに従ったかを書く。直すかどうかはユーザーが決める
- 文書を直すのは、issue の範囲に入っているときだけ。ついでに直さない(1 PR = 1 issue)
- ただし、その PR の実装で古くなる記述(「まだ無い」「#<番号> で作る」と書いたものを作った・ジョブやコマンドの中身を変えた・表や列を足した、など)は、同じ PR で直す。これは issue の範囲に入る。古い記述が残ると、次に読むエージェントが実態と違う規約に従ってしまう
- PR を作る前に、差分に出た名前(ファイル・クラス・コマンド・ワークフローのジョブ・表・issue の番号)で `CLAUDE.md`・`README.md`・`docs/` を grep し、この PR で古くなる記述が無いかを確かめる
- ワークフローの cron・間隔・モデルを変えたときは、ほかのワークフローのコメントにも書かれている(「ほかの定期実行の 0・20 分と重ならないよう」など)。`grep -rn "schedule:" -A2 .github/workflows/` で全部の cron を出し、ワークフローのコメントと文書の「〇時間ごと」「〇分」と突き合わせる

## 禁止事項

- **このプロジェクトでは GitHub Copilot を使わない**。コード補完・Copilot のコードレビュー・「Fix with Copilot」「Explain error」・Copilot への issue のアサイン、すべて。レビュアーは CodeRabbit か Claude(`.github/ai-dev.yml` の `reviewer`)
- 上の「このリポジトリは public」に挙げたものを commit しない・書かない
- `develop`・`release` に直接 push・force push しない(保護で拒否される)
- 閉包テーブルを `CategoryTree` を通さずに書き換えない
- DB を使うテストを SQLite で代用しない
- エージェントは PR をマージしない

## サブディレクトリの CLAUDE.md

`backend/CLAUDE.md`・`backend/AGENTS.md` は、Laravel の雛形が置いた Laravel Boost の導入案内で、このリポジトリの規約ではない(`composer.json` に Boost は入っていない)。この CLAUDE.md の規約を優先し、issue に無い作業として Boost を入れない(入れるなら別の issue にする)。
