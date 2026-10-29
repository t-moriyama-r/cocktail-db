#!/usr/bin/env bash
# develop とコンフリクトしていて、Actions の Claude Code に develop を取り込ませる PR を選ぶ
#
# 使い方: conflict-candidates.sh <owner/repo> [PR の番号]
#   選んだ PR を [{number, head, branch, base}] の JSON で標準出力に出す(base は取り込む develop のコミット)。
#   見送った理由は標準エラーに出す。番号を渡せばその PR だけを見る
#
# コンフリクトは誰の番でもないまま止まりやすい(レビュアーも自動マージも動かない)。
# 次のものは選ばない
# - draft(実装している途中。ready にするエージェントが取り込む。落ちた draft は ai-draft-sweep.yml が引き継ぐ。#410)
# - 閉じた・フォークから・オーナー以外が作った・向き先が既定のブランチでない(積んでいる途中は土台に合わせる)
# - needs-human / needs-decision(人の判断待ち)・needs-manual(手元の作業待ち)・needs-local(見回り待ち)・ai-in-actions(Actions が対応している途中)
# - 最後のコミットから MIN_IDLE_MINUTES 分たっていない(手元で作業している途中のブランチに push しない)
# - 同じコミットと develop の組で、もう一度試した(目印 <!-- ai-dev:conflict-resolve sha=<コミット> base=<develop> -->)。
#   解消できずに止まった回を、develop が進むまで繰り返さない
# GitHub はマージできるかを後から計算するので、UNKNOWN の間は少し待って取り直す(ai-auto-merge.yml と同じ)
set -euo pipefail

MIN_IDLE_MINUTES=${MIN_IDLE_MINUTES:-60}
UNKNOWN_RETRY_SECONDS=${UNKNOWN_RETRY_SECONDS:-5}

main() {
  local repo=$1 only=${2:-} default base_sha now numbers n json picked=()
  default=$(gh api "repos/$repo" --jq .default_branch)
  base_sha=$(gh api "repos/$repo/branches/$default" --jq .commit.sha)
  now=${NOW:-$(date -u +%s)}
  if [ -n "$only" ]; then
    numbers=$only
  else
    numbers=$(gh pr list -R "$repo" --state open --base "$default" --limit 100 --json number --jq '.[].number')
  fi
  for n in $numbers; do
    if json=$(candidate "$repo" "$n" "$default" "$base_sha" "$now"); then picked+=("$json"); fi
  done
  # 1 件も無いと printf は空行を 1 つ出すが、jq の inputs は空行を読まないので [] になる
  printf '%s\n' "${picked[@]}" | jq -nc '[inputs]'
}

# 選ぶなら {number, head, branch, base} を出す。見送りは skip でサブシェルごと抜けるので、$(...) の中で呼ぶ
candidate() {
  local repo=$1 n=$2 default=$3 base_sha=$4 now=$5 pr label head committed idle comments i
  skip() { echo "#$n 見送り: $*" >&2; exit 1; }
  for i in 1 2 3 4 5 6; do
    pr=$(gh pr view "$n" -R "$repo" --json state,isDraft,isCrossRepository,author,baseRefName,headRefName,headRefOid,labels,mergeable)
    [ "$(jq -r .mergeable <<<"$pr")" != UNKNOWN ] && break
    [ "$i" = 6 ] || sleep "$UNKNOWN_RETRY_SECONDS"
  done
  [ "$(jq -r .state <<<"$pr")" = OPEN ] || skip "開いていない"
  [ "$(jq -r .isDraft <<<"$pr")" = false ] || skip "draft"
  [ "$(jq -r .isCrossRepository <<<"$pr")" = false ] || skip "フォークから"
  [ "$(jq -r .author.login <<<"$pr")" = "${repo%/*}" ] || skip "オーナーの PR でない"
  [ "$(jq -r .baseRefName <<<"$pr")" = "$default" ] || skip "向き先が $default でない"
  [ "$(jq -r .mergeable <<<"$pr")" = CONFLICTING ] || skip "コンフリクトしていない($(jq -r .mergeable <<<"$pr"))"
  label=$(jq -r '[.labels[].name | select(IN("needs-human","needs-local","needs-manual","needs-decision","ai-in-actions"))] | join(",")' <<<"$pr")
  [ -z "$label" ] || skip "ラベル $label"
  head=$(jq -r .headRefOid <<<"$pr")
  # --json commits は先頭 100 件までなので、いまのコミットの日付を直接取る
  committed=$(gh api "repos/$repo/commits/$head" --jq .commit.committer.date) || skip "コミットの日付を取れない"
  idle=$(( (now - $(date -u -d "$committed" +%s)) / 60 ))
  [ "$idle" -ge "$MIN_IDLE_MINUTES" ] || skip "最後のコミットから ${idle} 分(${MIN_IDLE_MINUTES} 分たつまで待つ)"
  # 目印は Actions が付ける。公開リポジトリなので、誰でも書ける人のコメントの目印は数えない(数えると、解消を止められてしまう)。
  # 大きな出力を grep -q に直接つなぐと、gh が SIGPIPE で止まり pipefail で見落とすので、いったん受けてから探す
  comments=$(gh api --paginate "repos/$repo/issues/$n/comments?per_page=100" --jq '.[] | select(.user.type == "Bot") | .body') || skip "コメントを取れない"
  if grep -qF "ai-dev:conflict-resolve sha=$head base=$base_sha" <<<"$comments"; then
    skip "${head:0:7} と ${base_sha:0:7} の組はもう試した"
  fi
  echo "#$n 候補(${head:0:7} に ${base_sha:0:7} を取り込む)" >&2
  jq -c --argjson number "$n" --arg base "$base_sha" '{number: $number, head: .headRefOid, branch: .headRefName, base: $base}' <<<"$pr"
}

main "$@"
