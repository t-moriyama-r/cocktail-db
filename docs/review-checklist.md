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
