# ラベル

このリポジトリの issue・PR に付けるラベルの意味と、付けると何が起きるか。

ラベルは、次に着手する issue を選ぶスクリプト(next-issue スキルの `next-issue.mjs`)と、`.github/workflows/` のワークフローが読む。
名前を変えると、それらが読めなくなる。変えるときは、読んでいる側も一緒に直す。

## issue の階層

issue は「根 → Epic → 機能 → 作業」の木にして、GitHub の sub-issue でつなぐ。AI エージェントが実装するのは作業(`task`)だけ。

| ラベル | 付けるもの | 誰がいつ付けるか | 付けると何が起きるか |
|---|---|---|---|
| `epic` | 根と Epic(`[Epic] …`) | issue を分けるとき(issue-breakdown スキル・人) | next-issue が、開いている `epic` から sub-issue を深さ優先でたどった順番を、同じ優先度の issue の並び順に使う(先に書いた Epic ほど前に並ぶ) |
| `feature` | 機能。作業が 3 件以上まとまるもの | issue を分けるとき | ほかの issue が `Blocked by` で `feature` の issue を待っているとき、その子(sub-issue)がすべて閉じていれば、親が開いていても済んだものとして扱う |
| `task` | 作業。1 件の PR で終わるもの | issue を分けるとき。issue のテンプレート(`.github/ISSUE_TEMPLATE/task.yml`)から作ると自動で付く | next-issue が着手する issue の候補にするのは、開いていて `task` が付いた issue だけ |

依存は、本文に `Blocked by #N` と書く(1 行に 1 件)。next-issue はこの書式だけを読み、`#N` が閉じる(`feature` なら子がすべて閉じる)まで、その issue を選ばない。

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

CodeRabbit の指摘への対応が終わり(最新のコミットを見ていて、未解決のスレッドが 0 件)、CI が緑で、コンフリクトの無い PR を、`ai-auto-merge.yml` がマージできる状態とみなし、自動でマージする。

人の手でマージする PR(`.github/ai-dev.yml` が `merge: auto` でない・`no-auto-merge` が付いている・閉じる issue に `needs-review` が付いている)は、同じ条件がそろったところで、マージせずに `needs-approval` を付けて Slack に知らせる(下の「人に頼む」)。直さない理由を返して未解決のまま残るスレッドもあるので、こちらは未解決ではなく返信待ちのスレッドが 0 件かで見る。自動のレビューと CI が終わる前には人を呼ばない。

`ai-in-actions`・`needs-human`・`needs-decision`・`needs-approval` が PR に付いているあいだは、どちらもしない。

| ラベル | 付けるもの | 誰がいつ付けるか | 付けると何が起きるか |
|---|---|---|---|
| `no-auto-merge` | 自動でマージしたくない PR | 人が、いつでも | 自動でマージしない。マージできる状態になったら `needs-approval` を付けて知らせる。外すと、自動マージの条件をもう一度確かめる |
| `ai-in-actions` | GitHub Actions の Claude Code がレビューの指摘に対応している PR | `ai-review-loop.yml` が、CodeRabbit のレビューに対応し始めたとき、`needs-human` か `needs-approval` の PR にオーナーがコメントしたときに付ける。リポジトリにまだ無ければ、そのときに作る | 自動でマージしない。対応が終わると Claude Code が外し、`ai-auto-merge.yml` にマージできる状態かを確かめさせる。途中で止まったときもワークフローが外す。印だけ残ったものは、`ai-review-sweep.yml` が 3 時間たってから拾い直す |


## 人に頼む(Slack に知らせる)

次のラベルが付くと、`notify-needs-human.yml` が Slack に知らせる(送り方は下の「Slack の設定」)。通知には、PR・issue と、PR が閉じる issue へのリンク、頼むコメント(目印 `<!-- ai-dev:<ラベル> -->` の付いたいちばん新しいもの)の抜粋が入る。そのため、ラベルより先に頼むコメントを書く。頼む中身(目印の付いたコメント、`needs-decision` なら本文の「判断が要ること」、ほかは人のいちばん新しいコメント)が見つからないときは、開いても答えることが無いので送らない(毎朝のまとめには載る)。

- bot(GitHub Actions の中の Claude Code など)が付けたラベルでは送らない。Actions の中で付けたときは、ワークフローが `gh workflow run notify-needs-human.yml` で送る
- 本文に目印 `ai-dev:pending-body` がある issue(issue-breakdown が作っている途中のもの)では送らない。作り終えたときに、開いている `needs-decision` の issue を 1 通にまとめて送る
- 付いたときの通知は 1 回きりなので、毎朝 9:00(日本時間)に、まだこれらのラベルが付いて開いている PR・issue を、ラベルごとに待っている日数を添えて 1 通にまとめて知らせ直す。0 件の日は送らない。手動で送るときは、`notify-needs-human.yml` を `number` を空、`label` を `all` にして動かす
- 頼んだラベルが外れるか、PR・issue が閉じるか、同じラベルで頼み直したら、その通知に ✅ のリアクションが付き、本文の先頭に「【済】」が付く(bot で送ったときだけ)。ラベルが外れた・閉じたときのイベント、ラベルを外すワークフロー(`ai-review-loop.yml`・`ai-decision-loop.yml`)の終わり、毎朝の見回りで、`notify-needs-human.yml` の `mark-done` ジョブが付ける。手動で付けさせるときは、`label` を `done` にして動かす

### Slack の設定

送るのは `.github/scripts/slack-post.sh`。`notify-workflow-merged.yml`(ワークフローを変えた PR のマージ)と `notify-started.yml`(エージェントが着手した issue。`gh workflow run notify-started.yml -f issues=<番号>,<番号> -f note=<任意>` で呼ぶ)も同じ送り先に送る。

- **bot で送る(おすすめ)**: Slack App の bot token を secret `SLACK_BOT_TOKEN` に、送り先の人のメンバー ID(`U` で始まるもの。Slack のプロフィールの「︙」→「メンバー ID をコピー」)をリポジトリ変数 `SLACK_NOTIFY_USER` に入れる。通知は、その人と bot の DM(Slack の左側の「App」の下)に届く。App の bot に要る権限(OAuth & Permissions の Bot Token Scopes)は、`chat:write`(送る)・`reactions:write`(✅ を付ける)・`im:write`(DM を開く)・`im:history`(頼んだ通知を探す)。権限を足したら、App をワークスペースに入れ直す
- **Webhook で送る**: bot が無ければ、secret `SLACK_WEBHOOK_URL`(Incoming Webhook)に送る。「済」は付かない
- どちらも無いときは、送らずに警告だけ出す

| ラベル | 付けるもの | 誰がいつ付けるか | 付けると何が起きるか |
|---|---|---|---|
| `needs-human` | 人の判断が要る PR・issue | エージェント(手元・Actions)が、レビューの指摘で判断に迷うとき・指摘が堂々めぐりになったとき・再レビューの回数(`review_rounds`)を超えたときに付ける。人が付けてもよい | Slack に知らせる。自動でマージしない。PR なら、オーナーがコメントすると `ai-review-loop.yml` の Claude Code がこのラベルを外して `ai-in-actions` を付け、判断どおりに直して再レビューを頼む。issue に付けたときは通知だけで、引き継ぐワークフローは無い |
| `needs-decision` | 着手する前に決めることがある issue | 上の「着手の順番」 | Slack に知らせる(本文の「判断が要ること」を添える) |
| `needs-approval` | マージできる状態になり、人の承認(マージ)を待っている PR | `ai-auto-merge.yml` が、人の手でマージする PR(上の「PR のマージ」)で、レビューの指摘への対応が終わり、CI が緑でコンフリクトが無くなったときに付ける。付ける前に、マージできる状態になったことを PR にコメントする(閉じる issue に `needs-review` があれば、Claude Code が書いた見てほしいところへのリンクを添える) | Slack に知らせる。自動でマージしない。人は証跡を見て、よければマージする(マージが承認)。オーナーが PR にコメントすると、`ai-review-loop.yml` の Claude Code が、直してほしいことがあればこのラベルを外して直し、再レビューを頼む。マージできる状態に戻れば、`ai-auto-merge.yml` がまた付ける。「OK」だけなら、マージで承認するよう返す |

## PR の状態(表示用)

開いている PR には、いま誰の番かを表す `状態:…` のラベルが 1 つだけ付く。`.github/scripts/pr-status.sh` が、下書きか・上のラベル・コンフリクト・必須のチェック・レビュー・返信待ちの指摘から毎回出し直して付け替える。上から見て最初に当てはまったものになる。

| ラベル | いつ | 次に動くもの |
|---|---|---|
| `状態:作業中` | 下書き | PR を作ったエージェントが ready にする(途中で落ちたものは `ai-draft-sweep.yml` が引き継いで仕上げる) |
| `状態:人の判断待ち` | `needs-human`・`needs-decision` が付いている | 人が PR にコメントで答える |
| `状態:人のマージ待ち` | `needs-approval` が付いている | 人が確かめてマージする |
| `状態:コンフリクト` | `develop` とコンフリクトしている | 自動では直らない。人かエージェントが `develop` を取り込んで直す |
| `状態:CI失敗` | ブランチ保護で必須にしたチェックが失敗している | 自動では直らない。人かエージェントが直す |
| `状態:レビュー対応中` | `ai-in-actions` が付いている、か、AI レビュアーの指摘に返信していないものがある | `ai-review-loop.yml` の Claude Code(止まっていれば `ai-review-sweep.yml` が呼び直す) |
| `状態:レビュー待ち` | いまのコミットを AI レビュアーがまだ見ていない | CodeRabbit(上限なら `ai-claude-review.yml`) |
| `状態:マージ待ち` | 上のどれでもない(レビューの対応が済み、CI も緑) | `ai-auto-merge.yml` |

- 付け替えるのは `pr-status.yml`。PR の動き・レビュー・CodeRabbit のコメント・CI と AI のワークフローの終わりで動き、1 時間ごとに開いている PR をすべて出し直す。ジョブが途中で止まって古いラベルが残っても、そこで直る。手で付け替えても、事実どおりに戻る
- 手元から今すぐ揃えるときは `gh workflow run pr-status.yml`(番号を付けなければ、開いている PR すべて)
- 表示のためのラベルで、ワークフローの条件には使わない。止めたい・人に頼みたいときは、上の `no-auto-merge`・`needs-human` などを付ける

## GitHub の既定のラベル

`bug`・`documentation`・`enhancement`・`duplicate`・`invalid`・`question`・`wontfix`・`good first issue`・`help wanted`・`accessibility` は、リポジトリを作ったときに GitHub が用意したもの。issue の種類を表すだけで、付けても自動では何も起きない(next-issue は `task` が無い issue を選ばないので、`bug` だけでは着手の候補にならない)。
