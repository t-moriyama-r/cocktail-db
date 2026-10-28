# ラベル

このリポジトリの issue・PR に付けるラベルの意味と、付けると何が起きるか。

ラベルは、次に着手する issue を選ぶスクリプト(next-issue スキルの `next-issue.mjs`)と、`.github/workflows/` のワークフローが読む。
名前を変えると、それらが読めなくなる。変えるときは、読んでいる側も一緒に直す。

## issue の階層

issue は「根 → Epic → 機能 → 作業」の木にして、GitHub の sub-issue でつなぐ。AI エージェントが実装するのは作業(`task`)だけ。

| ラベル | 付けるもの | 誰がいつ付けるか | 付けると何が起きるか |
|---|---|---|---|
| `epic` | 根と Epic(`[Epic] …`) | issue を分けるとき(issue-breakdown スキル・人) | next-issue が、開いている `epic` から sub-issue を深さ優先でたどった順番を、同じ優先度の issue の並び順に使う(先に書いた Epic ほど前に並ぶ) |
| `feature` | 機能。作業が 3 件以上まとまるもの | issue を分けるとき | ほかの issue が `Blocked by` で `feature` の issue を待っているとき、その子(sub-issue)がすべて済んでいれば、親が開いていても済んだものとして扱う。子が `feature` なら、その子も同じ判定で見る(子の `feature` は誰も閉じないため) |
| `task` | 作業。1 件の PR で終わるもの | issue を分けるとき。issue のテンプレート(`.github/ISSUE_TEMPLATE/task.yml`)から作ると自動で付く | next-issue が着手する issue の候補にするのは、開いていて `task` が付いた issue だけ |

依存は、本文に `Blocked by #N` と書く(1 行に 1 件)。next-issue はこの書式だけを読み、`#N` が閉じる(`feature` なら子がすべて済む)まで、その issue を選ばない。

## 領域

| ラベル | 意味 |
|---|---|
| `area:infra` | CI・ワークフロー・開発の仕組み |
| `area:backend` | Laravel(API・サービス・コマンド) |
| `area:frontend` | React(公開画面) |
| `area:db` | テーブル・マイグレーション・木の操作 |
| `area:admin` | 管理画面と管理 API |
| `area:migration` | 旧 DB からのデータ移行 |
| `area:ops` | 本番の運用・切り替え |

`area:*` は issue を絞り込むための分類で、付けても自動では何も起きない。1 つの issue に複数付けてよい。

### タイトルの接頭辞

issue・PR のタイトルの先頭に、対象を示す接頭辞を付ける(一覧で BE・FE・CI のどれの話かをタイトルだけで分かるようにするため)。接頭辞は `area:*` から決める。

| `area:*` | 接頭辞 |
|---|---|
| `area:backend`・`area:db` | `【BE】` |
| `area:frontend` | `【FE】` |
| `area:infra` | `【CI】` |
| `area:migration` | `【移行】` |
| `area:ops` | `【運用】` |

- `area:admin` は管理画面と管理 API なので、BE・FE の側に当てる(`area:backend` なら `【BE】`、`area:frontend` なら `【FE】`)
- 複数の領域にまたがるときは、上の表の順(BE → FE → CI → 移行 → 運用)に並べて続ける(例: `【BE】【FE】`)
- PR は、閉じる issue と同じ接頭辞にして、その後ろに `issue-<番号>: ` を続ける(例: `【CI】issue-262: …`)
- 昇格の PR(`develop` → `staging`・`staging` → `release`)は `【運用】` を付け、`issue-<番号>: ` の代わりに、`staging` 向けは PR を作った日(`【運用】staging 2026-10-26`)、`release` 向けは本番に出す版(`【運用】release v1.2.0`)を続ける。形と版の上げ方は `CLAUDE.md` の「ブランチ・コミット・PR」の 9。`release` 向けの形は `branch-flow.yml` が確かめ、マージするとその版のタグと GitHub Release ができる(`release-tag.yml`)
- Epic(`[Epic] …`)は領域をまたぐので付けない。機能(`feature`)・作業(`task`)には付ける
- 領域が決まらないときは、作る前に issue で相談する

## 着手の順番(next-issue が読む)

| ラベル | 付けるもの | 誰がいつ付けるか | 付けると何が起きるか |
|---|---|---|---|
| `needs-decision` | 着手する前に、人に決めてもらうことがある issue | issue を作るときに、本文の「判断が要ること」を書いて付ける(issue-breakdown・人)。判断になっていない返信のときは、Actions の Claude Code が付けたままにする | next-issue が選ばない。付けたときに Slack に知らせる(下の「人に頼む」)。リポジトリのオーナーが issue にコメントすると、`ai-decision-loop.yml` の Claude Code が本文に「決まったこと」を書き、このラベルを外す。外れると next-issue が選ぶようになる |
| `later` | 今回の完了に入れない issue | issue を分けるとき・人が後回しにすると決めたとき | next-issue が選ばない。外すと候補に戻る |
| `priority:high` | 依存の数に関係なく先にやる issue(規約の文書・自動化の仕組みなど) | issue を分けるとき・人 | next-issue が、ほかの並び(待っている issue の数・Epic の順・番号)より先に並べる |
| `needs-review` | 出来上がりを人が見て承認してからマージする issue(設計書・データの扱いなど) | issue を分けるとき・人 | next-issue は選ぶ(`[要レビュー]` と表示する)。この issue を `Closes #N` で閉じる PR は自動でマージしない。レビューの指摘への対応が終わり、CI が緑でマージできる状態になったら、`ai-auto-merge.yml` が PR に `needs-approval` を付けて、人の承認(マージ)を待つ |

## PR のマージ(ai-auto-merge.yml が読む)

CodeRabbit の指摘への対応が終わり(最新のコミットを見ていて、未解決のスレッドが 0 件)、CI が緑(付いたチェックに失敗が無く、ブランチの保護で必須にしたチェックがすべて成功している。CI が承認待ちで走っていないコミットは緑とみなさない)で、コンフリクトの無い PR を、`ai-auto-merge.yml` がマージできる状態とみなし、自動でマージする。

いまのコミットで CI が動いていない(必須のチェックが揃っていない・CI の `pull_request` の回が成功していない)PR は、`gh pr merge` を呼ばずに見送り、`ci-rerun.yml` にその PR の CI を流し直させる(`.github/scripts/ci-rerun-request.sh`。#268)。流し直した CI が緑で終わると、`ai-auto-merge.yml` が呼び直されてマージに進む。

ほかの条件がそろい、止めているのがエージェントの返信済みで未解決のスレッドだけのとき(Actions の Claude Code が直さない理由を返したスレッドは解決にしない。人が立てたスレッドも含む)は、人が解決するまで止まるので、`ai-auto-merge.yml` が残っているスレッドへのリンクと決めてほしいこと(返信で良ければ解決する・直してほしければそのスレッドに返信する)をコメントし、`needs-human` を付けて Slack に知らせる(`.github/scripts/thread-handoff.sh`。同じコミットでは 1 回だけ)。スレッドの解決ではワークフローが起きないので、人がすべて解決したら、`ai-review-sweep.yml` の見回りがこの理由で付けた `needs-human` を外し、`ai-auto-merge.yml` を呼び直す。

人の手でマージする PR(`.github/ai-dev.yml` が `merge: auto` でない・`no-auto-merge` が付いている・閉じる issue に `needs-review` が付いている)は、同じ条件がそろったところで、マージせずに `needs-approval` を付けて Slack に知らせる(下の「人に頼む」)。直さない理由を返して未解決のまま残るスレッドもあるので、こちらは未解決ではなく返信待ちのスレッドが 0 件かで見る。自動のレビューと CI が終わる前には人を呼ばない。

自動でマージする PR でも、条件を満たして `gh pr merge` まで進んだのに GitHub に断られることがある(ワークフローを書き換える PR で `workflows` の権限が無い・base branch policy で断られる、など)。そのときは、断られた理由(エラーの 1 行)を PR にコメントし(目印 `<!-- ai-dev:agent --> <!-- ai-dev:needs-approval -->`)、`needs-approval` を付けて Slack に知らせ、状態のラベルを付け直す(#218)。もう `needs-approval` が付いていれば知らせない。`needs-approval` の付いた PR は見回り(`ai-review-sweep.yml`)も見送るので、同じ失敗を繰り返さない。

向き先が `staging`・`release` の PR(昇格の PR)は、自動ではマージしない。デプロイになるので、必ず人がマージする。AI レビューは流すが、指摘が残ってもマージを止めず(`needs-human` も付けず、Actions の Claude Code も動かさない。head が保護されたブランチ(`develop`・`staging`)なので直せない)、CI が緑で、いまのコミットを AI がレビューし終えたら、`needs-approval` を付けて Slack に知らせる。残っている指摘の件数は PR にコメントする。

`ai-in-actions`・`needs-human`・`needs-decision`・`needs-local`・`needs-approval` が PR に付いているあいだは、どちらもしない。

| ラベル | 付けるもの | 誰がいつ付けるか | 付けると何が起きるか |
|---|---|---|---|
| `no-auto-merge` | 自動でマージしたくない PR | 人が、いつでも | 自動でマージしない。マージできる状態になったら `needs-approval` を付けて知らせる。外すと、自動マージの条件をもう一度確かめる |
| `ai-in-actions` | GitHub Actions の Claude Code がレビューの指摘に対応している PR | `ai-review-loop.yml` が、CodeRabbit のレビューに対応し始めたとき、`needs-human` か `needs-approval` の PR にオーナーがコメントしたとき、`ai-conflict-resolve.yml` がコンフリクトを解消し始めたときに付ける。リポジトリにまだ無ければ、そのときに作る | 自動でマージしない。対応が終わると Claude Code が外し、`ai-auto-merge.yml` にマージできる状態かを確かめさせる。途中で止まったときもワークフローが外す。印だけ残ったものは、`ai-review-sweep.yml` が 3 時間たってから拾い直す |


## 人に頼む(Slack に知らせる)

次のラベルが付くと、`notify-needs-human.yml` が Slack に知らせる(送り方は下の「Slack の設定」)。通知には、PR・issue と、PR が閉じる issue へのリンク、頼むコメント(目印 `<!-- ai-dev:<ラベル> -->` の付いたいちばん新しいもの)の抜粋が入る(図のコードブロックは「(図は GitHub で見る)」に置き換える。頼むコメントの形は `CLAUDE.md` の「人に判断を頼むとき」)。そのため、ラベルより先に頼むコメントを書く。頼む中身(目印の付いたコメント、`needs-decision` なら本文の「判断が要ること」、ほかは人のいちばん新しいコメント)が見つからないときは、開いても答えることが無いので送らない(毎朝のまとめには載る)。

- bot(GitHub Actions の中の Claude Code など)が付けたラベルでは送らない。Actions の中で付けたときは、ワークフローが `gh workflow run notify-needs-human.yml` で送る
- 本文に目印 `ai-dev:pending-body` がある issue(issue-breakdown が作っている途中のもの)では送らない。作り終えたときに、開いている `needs-decision` の issue を 1 通にまとめて送る
- 付いたときの通知は 1 回きりなので、毎朝 9:00(日本時間)に、まだこれらのラベルが付いて開いている PR・issue を、ラベルごとに待っている日数を添えて 1 通にまとめて知らせ直す。0 件の日は送らない。手動で送るときは、`notify-needs-human.yml` を `number` を空、`label` を `all` にして動かす
- 下の表の `needs-local`(手元の作業待ち)は、人の判断を待っていないので、付いたときには知らせない。手元のエージェントが拾うので、付いてから 2 日たっても残っているものだけを、毎朝のまとめに載せる(`.github/scripts/notify-digest.jq`)
- 頼んだラベルが外れるか、PR・issue が閉じるか、同じラベルで頼み直したら、その通知に ✅ のリアクションが付き、本文の先頭に「【済】」が付く(bot で送ったときだけ)。ラベルが外れた・閉じたときのイベント、ラベルを外すワークフロー(`ai-review-loop.yml`・`ai-decision-loop.yml`・`ai-review-sweep.yml`)の終わり、毎朝の見回りで、`notify-needs-human.yml` の `mark-done` ジョブが付ける。手動で付けさせるときは、`label` を `done` にして動かす

### Slack の設定

送るのは `.github/scripts/slack-post.sh`。`notify-workflow-merged.yml`(ワークフローを変えた PR のマージ)と `notify-started.yml`(エージェントが着手した issue。`gh workflow run notify-started.yml -f issues=<番号>,<番号> -f note=<任意>` で呼ぶ)も同じ送り先に送る。

- **bot で送る(おすすめ)**: Slack App の bot token を secret `SLACK_BOT_TOKEN` に、送り先の人のメンバー ID(`U` で始まるもの。Slack のプロフィールの「︙」→「メンバー ID をコピー」)をリポジトリ変数 `SLACK_NOTIFY_USER` に入れる。通知は、その人と bot の DM(Slack の左側の「App」の下)に届く。App の bot に要る権限(OAuth & Permissions の Bot Token Scopes)は、`chat:write`(送る)・`reactions:write`(✅ を付ける)・`im:write`(DM を開く)・`im:history`(頼んだ通知を探す)。権限を足したら、App をワークスペースに入れ直す
- **Webhook で送る**: bot が無ければ、secret `SLACK_WEBHOOK_URL`(Incoming Webhook)に送る。「済」は付かない
- どちらも無いときは、送らずに警告だけ出す

| ラベル | 付けるもの | 誰がいつ付けるか | 付けると何が起きるか |
|---|---|---|---|
| `needs-human` | 人の判断が要る PR・issue | エージェント(手元・Actions)が、レビューの指摘で判断に迷うとき・指摘が堂々めぐりになったとき・`develop` とのコンフリクトで両方の意図を残せないとき・再レビューの回数(`review_rounds`)を超えたとき・返信済みで未解決のスレッドだけが自動マージを止めているとき(`ai-auto-merge.yml`。上の「PR のマージ」)に付ける。人が付けてもよい。証跡を撮れない・ワークフローの変更を push できないなど、人の判断は要らず手元の環境でしかできない作業は、これではなく下の `needs-local` で頼む | Slack に知らせる。自動でマージしない。PR なら、オーナーがコメントすると `ai-review-loop.yml` の Claude Code がこのラベルを外して `ai-in-actions` を付け、判断どおりに直して再レビューを頼む。返信済みで未解決のスレッドの理由で付けたものは、人がスレッドをすべて解決すると `ai-review-sweep.yml` が外す。issue に付けたときは通知だけで、引き継ぐワークフローは無い |
| `needs-decision` | 着手する前に決めることがある issue | 上の「着手の順番」 | Slack に知らせる(本文の「判断が要ること」を添える) |
| `needs-local` | 手元の環境でないとできない作業(証跡の撮影・workflow の push など)を待っている PR | クラウドのセッション・夜間のルーチン・Actions の Claude Code(`ai-review-loop.yml`・`ai-draft-sweep.yml`・`ai-conflict-resolve.yml`)が、FE・BE のコードを変えた PR で修正前後の証跡を撮れないとき(draft のまま付ける。CLAUDE.md の手順 5)・見た目の案を人に選んでもらう PR で最終の証跡を撮れないとき・ワークフローのファイルを変えたコミットを push できないとき・ほかの issue への追記など手元の権限で済む作業があるときに付ける。付ける前に、頼むコメントを書く。先頭の目印は `<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=<種類> -->` で、種類は `evidence`(証跡を撮って貼る)・`push`(workflow の差分を当てて push する)・`other`(そのほかの手元の権限で済む作業)。本文には、撮るもの・当てる差分と、終わったら何をするか(ready にする・レビューを頼む)を、手元のエージェントがそのまま流せる形で書く。人の判断も一緒に要るときは、これではなく `needs-human` を付けて、手元の作業も同じコメントに書く | Slack には知らせない(人の判断を待っていないため)。付いてから 2 日たっても残っているものだけ、毎朝のまとめに「手元の作業待ち」として載る(手元のエージェントがしばらく動いていないことに気づけるように)。自動でマージせず、見回り(`ai-draft-sweep.yml`・`ai-review-sweep.yml`・`ai-conflict-resolve.yml`)もレビューの依頼も見送る(`.github/scripts/held-labels.sh`)。手元のエージェント(スキル集の daily-start・pr-patrol)が見つけて片付け、このラベルを外してから、先頭が `<!-- ai-dev:agent --> <!-- ai-dev:local-done kind=<種類> -->` のコメントで何をしたかを書く。外すのは手元のエージェント |
| `needs-approval` | マージできる状態になり、人の承認(マージ)を待っている PR | `ai-auto-merge.yml` が、人の手でマージする PR(上の「PR のマージ」)で、レビューの指摘への対応が終わり、CI が緑でコンフリクトが無くなったときに付ける。付ける前に、マージできる状態になったことを PR にコメントする(閉じる issue に `needs-review` があれば、Claude Code が書いた見てほしいところへのリンクを添える)。自動でマージする PR でも、`gh pr merge` が GitHub に断られたときは、断られた理由(エラーの 1 行)を PR にコメントしてから付ける(#218) | Slack に知らせる。自動でマージしない。人は証跡を見て、よければマージする(マージが承認)。オーナーが PR にコメントすると、`ai-review-loop.yml` の Claude Code が、直してほしいことがあればこのラベルを外して直し、再レビューを頼む。マージできる状態に戻れば、`ai-auto-merge.yml` がまた付ける。「OK」だけなら、マージで承認するよう返す |

## PR の状態(表示用)

開いている PR には、いま誰の番かを表す `状態:…` のラベルが 1 つだけ付く。`.github/scripts/pr-status.sh` が、下書きか・上のラベル・コンフリクト・必須のチェック・レビュー・返信待ちと未解決の指摘・GitHub がマージを許すか(`mergeStateStatus`)から毎回出し直して付け替える。上から見て最初に当てはまったものになる。`状態:マージ待ち`・`状態:人のマージ待ち` は、GitHub が実際にマージを許す PR(`mergeStateStatus` が `CLEAN`・`HAS_HOOKS`・`UNSTABLE`)にだけ付き、マージを止めている理由はそれぞれ別のラベルになる。

| ラベル | いつ | 次に動くもの |
|---|---|---|
| `状態:手元の作業待ち` | `needs-local` が付いていて、`needs-human`・`needs-decision` が付いていない(下書きでも) | 手元のエージェント(daily-start・pr-patrol)が片付けて、`needs-local` を外す |
| `状態:作業中` | 下書き(`needs-local` だけが付いた下書きは、上の `状態:手元の作業待ち`) | PR を作ったエージェントが ready にする(途中で落ちたものは `ai-draft-sweep.yml` が引き継いで仕上げる) |
| `状態:人の判断待ち` | `needs-human`・`needs-decision` が付いている | 人が PR にコメントで答える |
| `状態:コンフリクト` | `develop` とコンフリクトしている | `ai-conflict-resolve.yml` の Claude Code が `develop` を取り込んで直す(1 時間ごと。draft は選ばない)。直せない衝突は `needs-human` で人に頼む |
| `状態:CI失敗` | ブランチ保護で必須にしたチェックが失敗している | 自動では直らない。人かエージェントが直す |
| `状態:レビュー対応中` | `ai-in-actions` が付いている、か、AI レビュアーの指摘に返信していないものがある(向き先が `staging`・`release` の PR と、`needs-approval` の PR は除く) | `ai-review-loop.yml` の Claude Code(止まっていれば `ai-review-sweep.yml` が呼び直す) |
| `状態:レビュー待ち` | いまのコミットを AI レビュアーがまだ見ていない(`needs-approval` の PR は除く) | CodeRabbit(上限なら `ai-claude-review.yml`)。push されたコミットは、CI の終わりに `review-on-push.yml` が依頼を出す(取りこぼしは `ai-review-sweep.yml` が拾う) |
| `状態:CI実行中` | 必須のチェックが終わっていない | CI(`test.yml`) |
| `状態:指摘の解決待ち` | 解決(resolve)されていないスレッドがある。指摘への返信は済んでいる(直さない理由を返したスレッドは、Actions が解決にしない)。ブランチ保護が会話の解決を必須にしているので、このままではマージできない | 人が返信を確かめてスレッドを解決にする(納得できなければ返信する)。解決しても、ラベルが変わるのは次の出し直し(下)のとき |
| `状態:CI未実行` | GitHub がマージを止めていて(`BLOCKED`)、上のどれでもない。いまの保護では、必須のチェックがいまのコミットで成功で付いていないとき(ドキュメントだけの変更で `test.yml` が動かなかった、CI が承認待ちで走っていない、など) | 自動では直らない。CI を流し直すか、人が保護を飛ばしてマージするかを決める |
| `状態:確認中` | GitHub がマージできるかをまだ計算している(`UNKNOWN`) | 次の出し直しで決まる |
| `状態:マージ不可` | GitHub がマージを止めていて、上のどのラベルでもない(`BEHIND` など) | 人が GitHub の PR の画面で理由を確かめる |
| `状態:人のマージ待ち` | マージできる。`needs-approval` が付いているか、向き先が `staging`・`release` で、いまのコミットを AI がレビューし終えている | 人が確かめてマージする |
| `状態:マージ待ち` | マージできる。上のどれでもない(レビューの対応が済み、指摘が解決され、必須のチェックが成功している) | `ai-auto-merge.yml` |

- 付け替えるのは `pr-status.yml`。PR の動き・レビュー・CodeRabbit のコメント・CI と AI のワークフローの終わりで動く。スレッドの解決では動かない(GitHub Actions はスレッドの解決をきっかけに起動できない)。開いている PR のすべての出し直しは、`ai-review-sweep.yml` が 2 時間ごと(PR に動きのある日は `patrol-kick.yml` が起こすので 1 時間ごと)に行う。ジョブが途中で止まって古いラベルが残っても、そこで直る。手で付け替えても、事実どおりに戻る
- 手元から今すぐ揃えるときは `gh workflow run pr-status.yml`(番号を付けなければ、開いている PR すべて)
- 表示のためのラベルで、ワークフローの条件には使わない。止めたい・人や手元に頼みたいときは、上の `no-auto-merge`・`needs-human`・`needs-local` などを付ける

## GitHub の既定のラベル

`bug`・`documentation`・`enhancement`・`duplicate`・`invalid`・`question`・`wontfix`・`good first issue`・`help wanted`・`accessibility` は、リポジトリを作ったときに GitHub が用意したもの。issue の種類を表すだけで、付けても自動では何も起きない(next-issue は `task` が無い issue を選ばないので、`bug` だけでは着手の候補にならない)。
