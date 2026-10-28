#!/usr/bin/env bash
# 実装したエージェントが途中で落ちて draft のまま残った PR のうち、Actions の Claude Code に引き継がせるものを選ぶ
#
# 使い方:
#   draft-candidates.sh <owner/repo>   選んだ PR を [{number, head, branch, round}] の JSON で標準出力に出す。見送った理由は標準エラーに出す
#   draft-candidates.sh decide         事実の JSON(標準入力)から、選ぶなら pick、見送るなら「skip: <理由>」を出す
#
# draft を ready にするのは実装したエージェントの役目で、ここで選ぶのは、その役目を果たさないまま止まった PR。
# 落ちた draft は CI が赤のことも、実装が足りないこともあるので、CI の結果では選り分けない(動いている途中のものだけ待つ)。
# 引き継いだ Claude Code は PR のブランチに push するので、手元のエージェントがまだ作業しているブランチは選ばない。
# 作業しているかは外から分からないので、最後の動き(コミット・コメント)から MIN_IDLE_MINUTES 分たったかで決める
set -euo pipefail

MIN_IDLE_MINUTES=${MIN_IDLE_MINUTES:-180}
# 引き継いでも仕上がらないまま、同じ PR を何度も引き継がない。上限に達したものは、引き継いだ Claude Code が needs-human か needs-local を付けている
MAX_ROUNDS=${MAX_ROUNDS:-2}
MARK="ai-dev:draft-sweep"

main() {
  if [ "${1:-}" = decide ]; then
    decide
    return
  fi
  local repo=$1 default now n facts verdict picked=()
  default=$(gh api "repos/$repo" --jq .default_branch)
  now=${NOW:-$(date -u +%s)}
  for n in $(gh pr list -R "$repo" --state open --draft --limit 100 --json number --jq '.[].number'); do
    facts=$(facts "$repo" "$n" "$default" "$now")
    verdict=$(decide <<<"$facts")
    if [ "$verdict" = pick ]; then
      echo "#$n 候補($(jq -r '.round' <<<"$facts") 回目の引き継ぎ)" >&2
      picked+=("$(jq -c --argjson number "$n" '{number: $number, head, branch, round}' <<<"$facts")")
    else
      echo "#$n 見送り: ${verdict#skip: }" >&2
    fi
  done
  # 1 件も無いと printf は空行を 1 つ出すが、jq の inputs は空行を読まないので [] になる
  printf '%s\n' "${picked[@]}" | jq -nc '[inputs]'
}

# 上から見て最初に当てはまった理由で見送る
decide() {
  jq -r --argjson minIdle "$MIN_IDLE_MINUTES" --argjson maxRounds "$MAX_ROUNDS" '
    (.labels // []) as $l
    | ([$l[] | select(IN("needs-human","needs-local","needs-decision","ai-in-actions","no-auto-merge"))] | join(",")) as $held
    | if .state != "OPEN" then "skip: 開いていない"
      elif .isDraft | not then "skip: draft でない"
      elif .isCrossRepository then "skip: フォークから"
      elif .author != .owner then "skip: オーナーの PR でない"
      elif .baseRefName != .defaultBranch then "skip: 向き先が \(.defaultBranch) でない(積んだ PR は、土台が入って付け替わってから)"
      elif $held != "" then "skip: ラベル \($held)"
      elif .checksPending then "skip: CI が動いている途中"
      elif .idleMinutes < $minIdle then "skip: 最後の動きから \(.idleMinutes) 分(\($minIdle) 分たつまで待つ)"
      elif .handledHead then "skip: このコミットは引き継ぎ済み"
      elif .round > $maxRounds then "skip: 引き継ぎが \($maxRounds) 回に達した"
      else "pick"
      end'
}

# 引き継ぎの目印は Actions(Bot)が付ける。公開リポジトリなので、誰でも書ける人のコメントの目印は数えない(数えると、見回りから外せてしまう)
facts() {
  local repo=$1 n=$2 default=$3 now=$4 pr head committed comments last
  pr=$(gh pr view "$n" -R "$repo" --json state,isDraft,isCrossRepository,author,baseRefName,headRefName,headRefOid,labels,statusCheckRollup)
  head=$(jq -r .headRefOid <<<"$pr")
  # --json commits は先頭 100 件までなので、いまのコミットの日付を直接取る
  committed=$(gh api "repos/$repo/commits/$head" --jq .commit.committer.date)
  comments=$(gh api --paginate --slurp "repos/$repo/issues/$n/comments?per_page=100" | jq 'add // []')
  last=$(jq -r --arg c "$committed" '[$c, (.[].created_at)] | max' <<<"$comments")
  # コメントの多い PR では JSON が 1 引数の上限(Linux は 128KB)を超えるので、jq には引数ではなく標準入力で渡す(#469)
  printf '%s\n' "$pr" "$comments" | jq -c -n --arg owner "${repo%/*}" --arg default "$default" --argjson now "$now" \
    --arg last "$last" --arg mark "$MARK" '
    input as $pr | input as $comments | $pr
    | [$comments[] | select(.user.type == "Bot") | .body | select(contains($mark))] as $marks
    | .headRefOid as $head
    | {
        state, isDraft, isCrossRepository, baseRefName,
        author: .author.login, owner: $owner, defaultBranch: $default,
        labels: [.labels[].name],
        head: .headRefOid, branch: .headRefName,
        # CheckRun は status、StatusContext は state を持つ
        checksPending: any((.statusCheckRollup // [])[]; if .__typename == "CheckRun" then .status != "COMPLETED" else (.state | IN("PENDING","EXPECTED")) end),
        idleMinutes: (($now - ($last | fromdateiso8601)) / 60 | floor),
        handledHead: any($marks[]; contains("\($mark) sha=\($head)")),
        round: (($marks | length) + 1)
      }'
}

main "$@"
