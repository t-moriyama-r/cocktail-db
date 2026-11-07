#!/usr/bin/env bash
# evidence-capture.yml がこの PR の証跡を撮ってよいかを決める(#601。親は #487)
#
# 使い方: evidence-target.sh <owner/repo> <PR の番号> [--labeled]
#   --labeled … needs-local のラベルが付いたことで起きた回(pull_request_target の labeled)。次の 2 つも見る
#     - いまも needs-local が付いているか(イベントの時点から外された・閉じられた回で撮らない。RC-07)
#     - 信頼する人・bot が書いた、いちばん新しい手元に頼むコメント(<!-- ai-dev:needs-local kind=… -->)が kind=evidence か。
#       push・other で needs-local を付けた PR では撮らない(撮るものが無い)
#
# どちらの回でも、開いていない PR・フォークからの PR・昇格の PR(staging・release 向け。head が保護されたブランチで、
# 中身は develop に入る前に PR ごとに撮っている)では撮らない。
#
# 終わりのコード: 0 … 撮ってよい / 1 … 撮らない(理由を標準出力に出す)/ 2 … 引数の誤り / 3 … 読めなかった
# (読めなかったことを「撮らない」と取り違えないよう、コードを分ける。RC-01)
#
# 信頼しない人の書いたものがあるかは、呼ぶ側が untrusted-hold.sh で別に確かめる(#494)。
set -euo pipefail

USAGE="使い方: evidence-target.sh <owner/repo> <PR の番号> [--labeled]"
TRUSTED_BOTS='["github-actions[bot]","claude[bot]"]'

evidence_target() {
  local repo=${1:-} number=${2:-} labeled=${3:-} pr comments state head_repo base labels kind
  if [ -z "$repo" ] || [[ ! "$number" =~ ^[1-9][0-9]*$ ]] || [ $# -gt 3 ] || { [ -n "$labeled" ] && [ "$labeled" != --labeled ]; }; then
    echo "$USAGE" >&2
    return 2
  fi
  if ! pr=$(gh api "repos/$repo/pulls/$number"); then
    echo "PR #$number を読めなかった" >&2
    return 3
  fi
  state=$(jq -r .state <<<"$pr")
  head_repo=$(jq -r '.head.repo.full_name // ""' <<<"$pr")
  base=$(jq -r .base.ref <<<"$pr")
  if [ "$state" != open ]; then
    echo "PR #$number は開いていないので撮らない"
    return 1
  fi
  if [ "$head_repo" != "$repo" ]; then
    echo "PR #$number はフォークからの PR なので撮らない"
    return 1
  fi
  if [ "$base" = staging ] || [ "$base" = release ]; then
    echo "PR #$number は昇格の PR($base 向け)なので撮らない"
    return 1
  fi
  [ "$labeled" = --labeled ] || return 0

  labels=$(jq -r '[.labels[].name] | join(",")' <<<"$pr")
  if [[ ",$labels," != *",needs-local,"* ]]; then
    echo "PR #$number には、いま needs-local が付いていないので撮らない"
    return 1
  fi
  if ! comments=$(gh api --paginate --slurp "repos/$repo/issues/$number/comments?per_page=100"); then
    echo "PR #$number のコメントを読めなかった" >&2
    return 3
  fi
  # 目印だけで選ぶと、外の人が同じ目印を書いたコメントで撮らせられるので、書いた人も見る(RC-05)。
  # コメントの多い PR では JSON が 1 引数の上限を超えるので、jq には標準入力で渡す(#469)
  kind=$(jq -r --argjson bots "$TRUSTED_BOTS" '
    add // []
    | map(select((.author_association | IN("OWNER", "MEMBER", "COLLABORATOR")) or (.user.login as $l | $bots | index($l))))
    | map(select((.body // "") | test("<!--\\s*ai-dev:needs-local\\s+kind=[a-z]+\\s*-->")))
    | sort_by(.created_at) | last
    | if . == null then "" else (.body | capture("<!--\\s*ai-dev:needs-local\\s+kind=(?<k>[a-z]+)\\s*-->").k) end' <<<"$comments")
  if [ "$kind" != evidence ]; then
    echo "PR #$number のいちばん新しい手元に頼むコメントは kind=${kind:-(無い)} で、証跡の撮影ではないので撮らない"
    return 1
  fi
}

evidence_target "$@"
