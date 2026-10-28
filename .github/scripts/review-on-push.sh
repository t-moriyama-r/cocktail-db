#!/usr/bin/env bash
# CI(test.yml)が終わったコミットを head にした開いている PR に、そのコミットのレビューを頼む(review-on-push.yml から呼ぶ)
#
# 使い方: review-on-push.sh <owner/repo> <コミットの sha> [CI の回の conclusion]
# 環境変数:
#   GH_TOKEN                  PR を読み、ワークフローを呼ぶトークン(actions: write が要る)
#   CODERABBIT_REQUEST_TOKEN  request-review.sh に渡す(オーナーの PAT)
#   OWNER                     PR を作った人として認めるアカウント(既定は owner/repo の owner)。review-request-state.sh にも渡る
#
# 人・手元のエージェントが PR に push したコミットは、これまで 2 時間ごとの ai-review-sweep.yml しか依頼を出さず、
# GitHub の schedule が遅れる・落とされると、誰も依頼しないまま止まった(#353・#366)。push の後に必ず起きる CI の終わりで呼ぶ。
#
# 依頼するかどうか・誰に頼むか(まず CodeRabbit、上限・返事なし・依頼を書けないときだけ代わりの Claude)は request-review.sh が決める。
# draft・needs-human・needs-approval の PR は、そちらが見送る。オーナーの依頼がいまのコミットの後にもうあれば(ai-review-loop.yml が
# 直して push した後に出したものなど)、review-request-state.sh が waiting を返すので、同じコミットに 2 回依頼しない。
#
# ここで見送るもの:
# - CI の回が承認待ち(action_required): claude[bot] の push で、まだ走っていない。ai-review-loop.yml がその場で依頼を出しており、
#   承認されて走った回が終わったときにもう一度呼ばれる(承認待ちの回は push の直後に終わるので、ai-review-loop.yml の依頼と重なりうる)
# - いまの head が CI の回のコミットでない: 後から push があった。その push の CI の終わりで頼む
# - オーナーが作っていない・フォークから: ai-review-sweep.yml と同じく、オーナーの PAT で頼まない
set -euo pipefail

repo=$1
sha=$2
conclusion=${3:-}
owner=${OWNER:-${repo%%/*}}
here=$(cd "$(dirname "$0")" && pwd)

main() {
  if [ "$conclusion" = action_required ]; then
    echo "${sha:0:7} 見送り: CI の回が承認待ち(走った回が終わったときに頼む)"
    return
  fi

  local pulls numbers n failed=0
  # 読めなかったときは「PR が無い」と同じに扱わず、失敗で終える(取りこぼしは ai-review-sweep.yml が拾う)
  pulls=$(gh api "repos/$repo/commits/$sha/pulls?per_page=100")
  numbers=$(jq -r --arg sha "$sha" '.[] | select(.state == "open" and .head.sha == $sha) | .number' <<<"$pulls")
  if [ -z "$numbers" ]; then
    echo "${sha:0:7} 見送り: このコミットを head にした開いている PR が無い"
    return
  fi
  for n in $numbers; do
    request_one "$n" || failed=1
  done
  return "$failed"
}

request_one() {
  local n=$1 pr head
  # 一覧は CI の回が終わった時点のものではないが、頼む直前の状態で決める(後から push があれば、その CI の終わりで頼む)
  pr=$(gh pr view "$n" -R "$repo" --json headRefOid,author,isCrossRepository) || {
    echo "::warning::#$n を読めなかった"
    return 1
  }
  head=$(jq -r .headRefOid <<<"$pr")
  if [ "$head" != "$sha" ]; then echo "#$n 見送り: head が ${head:0:7} に進んだ(その CI の終わりで頼む)"; return; fi
  if [ "$(jq -r .isCrossRepository <<<"$pr")" = true ]; then echo "#$n 見送り: フォークから"; return; fi
  if [ "$(jq -r .author.login <<<"$pr")" != "$owner" ]; then echo "#$n 見送り: 作ったのがオーナーではない"; return; fi
  echo "#$n ${sha:0:7} の CI が終わった。レビューを頼む"
  bash "$here/request-review.sh" "$repo" "$n"
}

main
