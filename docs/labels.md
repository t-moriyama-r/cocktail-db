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

## 着手の順番(next-issue が読む)

| ラベル | 付けるもの | 誰がいつ付けるか | 付けると何が起きるか |
|---|---|---|---|
| `needs-decision` | 着手する前に、人に決めてもらうことがある issue | issue を作るときに、本文の「判断が要ること」を書いて付ける(issue-breakdown・人)。判断になっていない返信のときは、Actions の Claude Code が付けたままにする | next-issue が選ばない。付けたときに Slack に知らせる(下の「人に頼む」)。リポジトリのオーナーが issue にコメントすると、`ai-decision-loop.yml` の Claude Code が本文に「決まったこと」を書き、このラベルを外す。外れると next-issue が選ぶようになる |
| `later` | 今回の完了に入れない issue | issue を分けるとき・人が後回しにすると決めたとき | next-issue が選ばない。外すと候補に戻る |
| `priority:high` | 依存の数に関係なく先にやる issue(規約の文書・自動化の仕組みなど) | issue を分けるとき・人 | next-issue が、ほかの並び(待っている issue の数・Epic の順・番号)より先に並べる |
| `needs-review` | 出来上がりを人が見て承認してからマージする issue(設計書・データの扱いなど) | issue を分けるとき・人 | next-issue は選ぶ(`[要レビュー]` と表示する)。この issue を `Closes #N` で閉じる PR は自動でマージしない。エージェントは、レビューの指摘への対応が終わったら PR に `needs-approval` を付けて、人の承認(マージ)を待つ |

## PR のマージ(ai-auto-merge.yml が読む)

`.github/ai-dev.yml` が `merge: auto` のとき、CodeRabbit の指摘への対応が終わり(最新のコミットを見ていて、未解決のスレッドが 0 件)、CI が緑の PR を、`ai-auto-merge.yml` が自動でマージする。
次のラベルのどれかが PR に付いていると、自動ではマージしない。閉じる issue に `needs-review` が付いているときも同じ。

| ラベル | 付けるもの | 誰がいつ付けるか | 付けると何が起きるか |
|---|---|---|---|
| `no-auto-merge` | 自動でマージしたくない PR | 人が、いつでも | 自動でマージしない。ほかには何も起きない。外すと、自動マージの条件をもう一度確かめる |
| `ai-in-actions` | 人の判断の後、GitHub Actions の Claude Code が対応を続けている PR | `ai-review-loop.yml` が、`needs-human` か `needs-approval` の PR にオーナーがコメントしたときに付ける。リポジトリにまだ無ければ、そのときに作る | 自動でマージしない。CodeRabbit のレビューが付くと、Actions の Claude Code が指摘に対応する。対応が終わると Claude Code が外す(閉じる issue に `needs-review` があれば、代わりに `needs-approval` を付ける) |

`needs-human`・`needs-decision`・`needs-approval` も、PR に付いていれば自動でマージしない(下の「人に頼む」)。

## 人に頼む(Slack に知らせる)

次のラベルが付くと、`notify-needs-human.yml` が Slack(secret の `SLACK_WEBHOOK_URL`)に知らせる。通知には、PR・issue と、PR が閉じる issue へのリンク、頼むコメント(目印 `<!-- ai-dev:<ラベル> -->` の付いたいちばん新しいもの)の抜粋が入る。そのため、ラベルより先に頼むコメントを書く。

- bot(GitHub Actions の中の Claude Code など)が付けたラベルでは送らない。Actions の中で付けたときは、ワークフローが `gh workflow run notify-needs-human.yml` で送る
- 本文に目印 `ai-dev:pending-body` がある issue(issue-breakdown が作っている途中のもの)では送らない。作り終えたときに、開いている `needs-decision` の issue を 1 通にまとめて送る
- `SLACK_WEBHOOK_URL` が無いときは、送らずに警告だけ出す

| ラベル | 付けるもの | 誰がいつ付けるか | 付けると何が起きるか |
|---|---|---|---|
| `needs-human` | 人の判断が要る PR・issue | エージェント(手元・Actions)が、レビューの指摘で判断に迷うとき・指摘が堂々めぐりになったとき・再レビューの回数(`review_rounds`)を超えたときに付ける。人が付けてもよい | Slack に知らせる。自動でマージしない。PR なら、オーナーがコメントすると `ai-review-loop.yml` の Claude Code がこのラベルを外して `ai-in-actions` を付け、判断どおりに直して再レビューを頼む。issue に付けたときは通知だけで、引き継ぐワークフローは無い |
| `needs-decision` | 着手する前に決めることがある issue | 上の「着手の順番」 | Slack に知らせる(本文の「判断が要ること」を添える) |
| `needs-approval` | 人の承認(マージ)を待っている PR | エージェントが、閉じる issue に `needs-review` が付いた PR で、レビューの指摘への対応が終わったときに付ける | Slack に知らせる。自動でマージしない。人は証跡を見て、よければマージする(マージが承認)。オーナーが PR にコメントすると、`ai-review-loop.yml` の Claude Code が、直してほしいことがあればこのラベルを外して直し、再レビューの後でまた付ける。「OK」だけなら、マージで承認するよう返す |

## GitHub の既定のラベル

`bug`・`documentation`・`enhancement`・`duplicate`・`invalid`・`question`・`wontfix`・`good first issue`・`help wanted`・`accessibility` は、リポジトリを作ったときに GitHub が用意したもの。issue の種類を表すだけで、付けても自動では何も起きない(next-issue は `task` が無い issue を選ばないので、`bug` だけでは着手の候補にならない)。
