#!/usr/bin/env bash
# Actions の Claude Code が needs-local kind=evidence を付けた PR で、evidence-capture.yml を workflow_dispatch で起こす(#686。親は #487)
#
# GITHUB_TOKEN で付けたラベルはワークフローを起こさないので、Actions の中の Claude Code が付けた needs-local では、
# evidence-capture.yml の pull_request_target の labeled が起きない。Claude Code を動かした後のジョブ(Claude Code を動かさないジョブ。RC-02)が、
# これで起こす(ai-review-loop.yml の notify-resume・notify-review、ai-draft-sweep.yml の notify)。
#
# 使い方: evidence-kick.sh <owner/repo> <PR の番号> <Claude Code を動かす前のラベル(カンマ区切り。無ければ空)>
#   - 動かす前から needs-local が付いていれば、この回に付いたものではないので起こさない
#   - いまも needs-local が付いていて、信頼する人・bot のいちばん新しい手元に頼むコメントが kind=evidence のときだけ起こす
#     (ラベルで起きた回と同じ決め方。evidence-target.sh の --labeled)。push・other の頼みでは起こさない
#
# 終わりのコード: 0 … 起こした・起こさなかった(理由を標準出力に出す)/ 2 … 引数の誤り / 3 … 読めなかった・起こせなかった
# (呼ぶ側は 3 を警告にする。起こせなくても、手元の見回りが needs-local の PR を拾う)
set -euo pipefail

USAGE="使い方: evidence-kick.sh <owner/repo> <PR の番号> <動かす前のラベル>"
HERE=$(cd "$(dirname "$0")" && pwd)

evidence_kick() {
  local repo=${1:-} number=${2:-} before=${3-} rc=0
  if [ -z "$repo" ] || [[ ! "$number" =~ ^[1-9][0-9]*$ ]] || [ $# -ne 3 ]; then
    echo "$USAGE" >&2
    return 2
  fi
  if [[ ",$before," == *",needs-local,"* ]]; then
    echo "#$number は動かす前から needs-local が付いていたので、evidence-capture.yml を起こさない"
    return 0
  fi
  bash "$HERE/evidence-target.sh" "$repo" "$number" --labeled || rc=$?
  case "$rc" in
    0) ;;
    1) return 0 ;;
    *) return 3 ;;
  esac
  if ! gh workflow run evidence-capture.yml -R "$repo" -f number="$number"; then
    echo "#$number の evidence-capture.yml を起こせなかった" >&2
    return 3
  fi
  echo "#$number の evidence-capture.yml を起こした"
}

evidence_kick "$@"
