#!/usr/bin/env bash
# PR のいまのコミットのレビューが、CodeRabbit への依頼のどの段階にあるかを出す
#
# 使い方: review-request-state.sh <owner/repo> <PR の番号>
# 環境変数: OWNER(依頼を書くアカウント。既定は owner/repo の owner)、WAIT_MINUTES(返事を待つ分。既定 30)
#
# 出すもの(1 語):
#   reviewed   いまのコミットを AI レビュアー(CodeRabbit・代わりの Claude)がもう見た
#   request    いまのコミットの後に、オーナーの依頼がまだ無い
#   limited    いまのコミットの後のオーナーの最後の依頼に、CodeRabbit が上限(Review rate limited / Review limit reached)を返した
#   unanswered いまのコミットの後のオーナーの最後の依頼から WAIT_MINUTES たっても、レビューが付いていない
#   waiting    依頼して WAIT_MINUTES たっていない
#
# CodeRabbit はボット(claude[bot] など)が書いた「@coderabbitai review」には応じない(#227。上限の返事すら付かない)。
# そのためボットの依頼は数えず、オーナーの依頼だけを依頼として数える。オーナーの依頼には、上限でも必ず返事が付く
set -euo pipefail

repo=$1
number=$2
owner=${OWNER:-${repo%%/*}}
wait_minutes=${WAIT_MINUTES:-30}
here=$(cd "$(dirname "$0")" && pwd)

main() {
  if [ "$(bash "$here/head-reviewed.sh" "$repo" "$number")" = true ]; then echo reviewed; return; fi

  local head pushed comments request
  head=$(gh pr view "$number" -R "$repo" --json headRefOid | jq -r .headRefOid)
  # --json commits は先頭 100 件までなので、101 件目からの PR では最後の要素がいまのコミットにならない(RC-06)。いまのコミットの日時を直接取る
  pushed=$(gh api "repos/$repo/commits/$head" --jq .commit.committer.date)
  comments=$(gh api --paginate --slurp "repos/$repo/issues/$number/comments?per_page=100" | jq 'add // []')
  request=$(last_owner_request "$comments" "$pushed")
  if [ -z "$request" ]; then echo request; return; fi

  if [ "$(limited_after "$comments" "$request")" = true ]; then echo limited; return; fi
  if [ "$(minutes_since "$request")" -ge "$wait_minutes" ]; then echo unanswered; return; fi
  echo waiting
}

# いまのコミットより後に、オーナーが書いた「@coderabbitai review」のうち最後のものの時刻(無ければ空)
last_owner_request() {
  jq -r --arg owner "$owner" --arg since "$2" '
    map(select(.user.login == $owner and .created_at > $since and (.body // "" | test("^\\s*@coderabbitai review\\s*$"))))
    | last | .created_at // ""' <<<"$1"
}

# 依頼より後の CodeRabbit のコメントに、上限の文言があるか。
# CodeRabbit は「処理中」のコメントを作ってから上限の文言に書き換えるので、作った時刻で依頼の後かを見て、本文はいまのものを見る
limited_after() {
  jq --arg since "$2" '
    any(.[]; .user.login == "coderabbitai[bot]" and .created_at > $since
      and (.body // "" | test("Review rate limited|Review limit reached")))' <<<"$1"
}

minutes_since() {
  echo $((($(date -u +%s) - $(date -u -d "$1" +%s)) / 60))
}

main
