# レビューで繰り返し出た指摘

2 件以上の PR のレビューで出た種類の指摘を、ルールにしたもの。コードを書く前と、PR にする前に読む。
CodeRabbit(`.coderabbit.yaml` の `knowledge_base.code_guidelines`)と代わりの Claude のレビュー(`ai-claude-review.yml`)も、これを基準にレビューする。

- ルールは、週 1 回の振り返り(スキル集の `ai-driven-dev/references/knowledge-loop.md`)が足す。足す PR は人が承認してからマージする
- ID(`RC-<連番 2 桁>`)は変えない。振り返りが、ルールにした後に同じ指摘がまた出た回数を ID で数える
- 実態と合わなくなったルールは、月 1 回の棚卸しで消す。消した ID は使い回さない

## RC-01: コマンドの失敗を見逃さない

追加: 2026-10-27 / 出た PR: [#148](https://github.com/t-moriyama-r/cocktail-db/pull/148)・[#197](https://github.com/t-moriyama-r/cocktail-db/pull/197)・[#198](https://github.com/t-moriyama-r/cocktail-db/pull/198)・[#208](https://github.com/t-moriyama-r/cocktail-db/pull/208)・[#213](https://github.com/t-moriyama-r/cocktail-db/pull/213)・[#222](https://github.com/t-moriyama-r/cocktail-db/pull/222)・[#224](https://github.com/t-moriyama-r/cocktail-db/pull/224)・[#230](https://github.com/t-moriyama-r/cocktail-db/pull/230)・[#233](https://github.com/t-moriyama-r/cocktail-db/pull/233)・[#237](https://github.com/t-moriyama-r/cocktail-db/pull/237)・[#253](https://github.com/t-moriyama-r/cocktail-db/pull/253)

シェル(ワークフローの `run`・`.github/scripts`・`ops/`)で、途中のコマンドが失敗しても先へ進み、空の値や途中までのファイルで後ろの処理が動く。原因の場所ではなく、後ろの処理で分かりにくく落ちる。

- `echo "x=$(cmd)"`・`local x=$(cmd)` は、`cmd` の終了コードを捨てる。代入を別の文にする
- パイプは最後のコマンドの終了コードしか見ない。`set -o pipefail` にする(ワークフローの `run` は `shell:` を書かなければ bash の `-e` だけで、`pipefail` は付かない)
- `|| true`・`|| echo ...` で黙らせるのは、失敗しても本来の仕事に関係ない処理(見た目の印・通知)だけにする。`gh api` の失敗を「無かった」と同じに扱わない(権限・レート制限・通信の失敗を、存在しない issue と区別する)
- 途中で落ちても後片付け(一時ファイル・コンテナ・目印のラベル)が残らないよう、`trap` か `if: always()` で片付ける。最終名のファイルは、全部書けてから `mv` で置く

```bash
# 悪い: draft-candidates.sh が落ちても、ステップは成功し prs は空になる
echo "prs=$(bash .github/scripts/draft-candidates.sh "$REPO")" >> "$GITHUB_OUTPUT"

# 良い: 代入で失敗をステップの失敗にする
prs=$(bash .github/scripts/draft-candidates.sh "$REPO")
echo "prs=$prs" >> "$GITHUB_OUTPUT"
```

## RC-02: Claude Code に渡す権限を、その仕事に要るだけに絞る

追加: 2026-10-27 / 出た PR: [#146](https://github.com/t-moriyama-r/cocktail-db/pull/146)・[#196](https://github.com/t-moriyama-r/cocktail-db/pull/196)・[#215](https://github.com/t-moriyama-r/cocktail-db/pull/215)・[#233](https://github.com/t-moriyama-r/cocktail-db/pull/233)・[#237](https://github.com/t-moriyama-r/cocktail-db/pull/237)

公開リポジトリなので、Claude Code が読む issue・PR のコメントには、外の人が書いた指示(プロンプトインジェクション)が混ざりうる。渡した権限は、その指示でも使える。

- Claude Code を動かすジョブには、ワークフローを起こす権限(`actions: write`)を渡さない。ワークフローを呼ぶのは、Claude Code を動かさない別のジョブにする
- `--allowedTools` の `Bash(cmd:*)` は前方一致なので、引数で絞る。PR の番号で絞るときは、番号の後ろまで書く(`gh pr comment 12:*` は `gh pr comment 123` にも効く)
- `Bash(gh api:*)`・`Bash(jq:*)` のように引数を絞れないものは、環境変数(トークン)を読める・何でも書けると考えて、渡すかを決める

```yaml
# 悪い: 番号の前方一致で、ほかの PR にもコメント・編集できる
--allowedTools "Bash(gh pr comment ${{ env.PR }}:*),Bash(gh pr edit:*)"

# 良い: 番号の後ろのオプションまで書き、使う操作だけを渡す
--allowedTools "Bash(gh pr comment ${{ env.PR }} --body:*),Bash(gh pr edit ${{ env.PR }} --add-label needs-human)"
```

## RC-03: concurrency は、ジョブの if より前に評価される

追加: 2026-10-27 / 出た PR: [#178](https://github.com/t-moriyama-r/cocktail-db/pull/178)・[#217](https://github.com/t-moriyama-r/cocktail-db/pull/217)

ワークフローの `concurrency` は、ジョブの `if` で飛ばされる起動(関係ないコメント・ラベルの付け外し)も同じグループに入れる。`cancel-in-progress` や、待てるのは 1 つだけという決まりで、本来動かすべき実行が取り消される。

- 投稿者(`github.event.comment.user.login`)も条件に入れる。本文だけで絞ると、誰でも同じ文言を書いてグループに入れてしまう
- グループには、動かす起動だけを PR ごとのグループに入れ、ほかは実行ごとのグループ(`github.run_id`)に逃がす
- 番号の無い起動(`schedule`・`workflow_run`)を、PR ごとのグループと同じ名前にまとめない

```yaml
# 良い: レビューする起動だけを PR ごとに並べ、ほかは実行ごとに分ける(ai-claude-review.yml)
concurrency:
  group: >-
    ${{ (github.event_name == 'workflow_dispatch'
      || (github.event.comment.user.login == 'coderabbitai[bot]'
        && (contains(github.event.comment.body, 'Review limit reached') || contains(github.event.comment.body, 'Review rate limited'))))
      && format('ai-claude-review-{0}', github.event.issue.number || inputs.number)
      || format('ai-claude-review-run-{0}', github.run_id) }}
```

## RC-04: checkout に資格情報を残さない

追加: 2026-10-27 / 出た PR: [#86](https://github.com/t-moriyama-r/cocktail-db/pull/86)・[#237](https://github.com/t-moriyama-r/cocktail-db/pull/237)

`actions/checkout` は既定で、ジョブのトークンを `.git/config` に書き残す。その後に動くもの(依存のインストールスクリプト・PR 側のスクリプト・Claude Code)がトークンを読めて、ジョブの書き込みの権限を使える。

- checkout には `persist-credentials: false` を付ける。push が要るジョブだけ外し、外す理由をコメントに書く
- 書き込みの権限を持つジョブ(`pull_request_target`・`pull_request_review` など)で、PR 側のコードを checkout して動かさない。スクリプトは既定のブランチから取る

```yaml
# 良い: 既定のブランチのスクリプトだけを取り、トークンを残さない(ai-auto-merge.yml)
- uses: actions/checkout@v6
  with:
    ref: ${{ github.event.repository.default_branch }}
    path: .ai-dev
    sparse-checkout: .github/scripts
    persist-credentials: false
```

## RC-05: 目印・キーワードで判定するときは、書いた人も確かめる

追加: 2026-10-27 / 出た PR: [#146](https://github.com/t-moriyama-r/cocktail-db/pull/146)・[#178](https://github.com/t-moriyama-r/cocktail-db/pull/178)・[#196](https://github.com/t-moriyama-r/cocktail-db/pull/196)

公開リポジトリでは、誰でも PR・issue にコメントを書ける。コメントの目印(`<!-- ai-dev:... -->`)や本文のキーワード(`Review limit reached` など)だけで判定すると、外の人が同じ文字列を書くだけで、見回りから外す・通知に自分の文を載せる・実行を取り消す、ができてしまう。

- 目印・キーワードで判定するときは、書いた人(`author_association` がオーナー・メンバーか、`user.login` が決まった bot か)も条件に入れる。`.user.type == "Bot"` だと、どの GitHub App の bot でも通る。Bot 全般を信頼するなら、その前提を書き、そうでなければ `user.login` の許可リストにする
- ワークフローの `if`・`concurrency` で本文を見るときも、投稿者を一緒に見る

```jq
# 良い: 信頼できる人か bot が書いたものから、目印のあるコメントを選ぶ(notify-needs-human.yml)
# `.user.type == "Bot"` は Bot 全般を信頼する前提。絞るなら `.user.login` の許可リストにする
["OWNER","MEMBER","COLLABORATOR"] as $trusted
  | $comments | map(select((.author_association as $a | $trusted | index($a)) or .user.type == "Bot"))
  | map(select(.body | contains("ai-dev:\($label)"))) | last
```

## RC-06: GitHub の API は、全部のページを取ってから数える

追加: 2026-10-27 / 出た PR: [#196](https://github.com/t-moriyama-r/cocktail-db/pull/196)・[#202](https://github.com/t-moriyama-r/cocktail-db/pull/202)

GraphQL の `first: 100`、`gh pr view --json commits`(先頭 100 件)などは、それより多いと黙って途中までを返す。「返信待ちが 0 件」「いちばん新しいコミット」を、途中までの結果で判定してしまう。

- 件数が上限を超えうるものは、`--paginate`(GraphQL は `pageInfo { hasNextPage endCursor }` と `$endCursor`)で全部取る。`--paginate` はページごとに結果を出すので、最後に合計する
- いちばん新しいコミットは `headRefOid` で取る。`.commits[-1]` を使わない

```bash
# 良い: 全ページを取り、ページごとの数を足す(unanswered-review-threads.sh)
gh api graphql --paginate -F owner="${repo%%/*}" -F name="${repo#*/}" -F number="$number" -f query='
  query($owner: String!, $name: String!, $number: Int!, $endCursor: String) {
    repository(owner: $owner, name: $name) { pullRequest(number: $number) {
      reviewThreads(first: 100, after: $endCursor) { pageInfo { hasNextPage endCursor } nodes { isResolved } } } } }' \
  --jq '[.data.repository.pullRequest.reviewThreads.nodes[] | select(.isResolved | not)] | length' | jq -s add
```

## RC-07: 判断する直前に、今の状態を取り直す

追加: 2026-10-27 / 出た PR: [#146](https://github.com/t-moriyama-r/cocktail-db/pull/146)・[#196](https://github.com/t-moriyama-r/cocktail-db/pull/196)・[#237](https://github.com/t-moriyama-r/cocktail-db/pull/237)

候補を選ぶジョブと、それを処理するジョブ(または同じジョブの後ろのほう)のあいだには、数十分〜数時間空くことがある。そのあいだに人がラベルを付け外しした・push があった・別の実行が先に処理した、という変化を見ずに、選んだときの状態で処理すると、二重の通知・古いコミットでの判断・人が止めたものの続行が起きる。

- Claude Code を動かす・ラベルを付ける・通知する直前に、PR・issue の今の状態(ラベル・head・開いているか)を取り直し、選んだときと違えば見送る
- 「処理した」という目印は、選んだときのコミット(sha)に結び付けて書く

```yaml
# 良い: イベントのラベルはコメントした時点のもの。動く前に、今も付いているかを取り直す(ai-decision-loop.yml)
- name: いまも needs-decision が付いているか
  id: live
  run: |
    labels=$(gh issue view "$ISSUE" -R "$REPO" --json labels --jq '[.labels[].name] | join(",")')
    if [[ ",$labels," == *",needs-decision,"* ]]; then echo "open=true" >> "$GITHUB_OUTPUT"; fi
```

## RC-08: 画面を変える PR の証跡を、幅の区切りごとにそろえる

追加: 2026-10-28 / 出た PR: [#427](https://github.com/t-moriyama-r/cocktail-db/pull/427)(オーナーの依頼でルールにした。#432)

FE の画面は、幅の区切り(xs 〜599px・sm 600px〜・md 960px〜・lg 1264px〜)で見た目が変わる。証跡を「PC 幅」と「スマホ幅 375px」の 2 つだけで撮ると、その間の幅(sm・md)で崩れていても証跡に出ない。

- `.css` を変えた FE の画面の PR の「## 証跡」は、`docs/design/style.md` の「証跡を撮る幅」の 4 つ(xs 375px・sm 768px・md 1024px・lg 1440px)すべてで、修正前後の静止画をそろえる。`.css` を変えず `.tsx` だけを変えた PR は、xs 375px・lg 1440px の 2 つでよい(#604)。比較画像は幅ごとに 1 枚。動画は操作で変わるものがある幅で撮る(少なくとも lg と xs)
- 区切りの境目(599/600px・959/960px)で出し方を変える CSS を足した・変えたときは、境目の両側の幅もそろえる
- `<!-- ai-dev:refactor-evidence 理由 -->` で修正前後を省いている PR は、差分が本当に振る舞いを変えないか(画面・API の出す値が変わらないか)を確かめる
- 撮った幅は `<数字>px` で書く(`evidence-gate.sh` が数える)。`<!-- ai-dev:no-viewport-evidence 理由 -->` で検査を飛ばしている PR は、理由のとおり画面に出ない変更か(純関数・型・テストだけか)を差分で確かめる

```markdown
<!-- 悪い: sm・md の幅が無い -->
## 証跡
PC 幅(1440px)とスマホ幅(375px)の修正前・修正後

<!-- 良い: 区切りごとに 1 つずつ撮り、幅を書く -->
## 証跡
xs 375px・sm 768px・md 1024px・lg 1440px の修正前・修正後(比較画像は幅ごとに 1 枚)
```
