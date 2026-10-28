#!/usr/bin/env bash
# PR で人に頼んだ問い(目印 ai-dev:needs-human)に、オーナーが答えたのに、エージェントがまだ何もしていないかを見る
#
# 使い方: owner-answered.sh <owner/repo> <PR の番号>
#
# 当たったら、標準出力に次を出す(当たらなければ何も出さない)。
#   question=<問いのコメントの URL>
#   reply=<オーナーの返信の URL>     … 問いの後のオーナーの返信ごとに 1 行(古い順)
#
# 答えを受けた resume(ai-review-loop.yml)が仕事をしないまま終わると、ai-dev:round が上限に達した PR では、
# 次の review が答えの出た問いをもう一度人に頼んでいた(#426。オーナーが同じ「A」を 2 回答えた)。
# review はこれで当たりを見て、上限に達していても聞き直さずに返信のとおりに対応する(#455)。
#
# - 問い: いちばん新しい、目印 ai-dev:needs-human の付いたコメント。目印を信じるのは、オーナーと GitHub App が書いたものだけ
#   (public のリポジトリでは誰でもコメントでき、目印を書き込めるため。thread-handoff.sh と同じ見分け方)
# - 返信: 問いの後の、オーナーのコメント。エージェントもオーナーのアカウントで書くので、目印 ai-dev:agent の付いたものは数えない。
#   「@coderabbitai review」(request-review.sh がオーナーの PAT で書くレビューの依頼)も、答えではないので数えない
# - エージェントがもう何かした: 最初の返信の後に、目印 ai-dev:agent の付いたコメントがある
# オーナーは問いへの返信をレビューのスレッドにも書けるので、PR のコメントとスレッドのコメントを合わせて時刻の順に見る
set -euo pipefail

USAGE="使い方: owner-answered.sh <owner/repo> <PR の番号>"

owner_answered() {
  local repo=${1:-} n=${2:-} issue review
  if [ -z "$repo" ] || [ -z "$n" ]; then
    echo "$USAGE" >&2
    return 2
  fi
  issue=$(gh api --paginate --slurp "repos/$repo/issues/$n/comments?per_page=100")
  review=$(gh api --paginate --slurp "repos/$repo/pulls/$n/comments?per_page=100")
  jq -r -n --argjson issue "$issue" --argjson review "$review" --arg owner "${repo%%/*}" '
    def agent: .body // "" | contains("ai-dev:agent");
    def trusted: .user.login == $owner or .user.type == "Bot";
    def reply: .user.login == $owner and (agent | not) and (.body // "" | startswith("@coderabbitai") | not);
    ($issue | add // []) + ($review | add // []) | sort_by(.created_at) as $all
    | ($all | map(select(trusted and (.body // "" | contains("ai-dev:needs-human")))) | last) as $q
    | if $q == null then empty else
        ($all | map(select(.created_at > $q.created_at))) as $after
        | ($after | map(select(reply))) as $replies
        | if ($replies | length) == 0 then empty
          elif any($after[]; trusted and agent and .created_at > $replies[0].created_at) then empty
          else "question=\($q.html_url)", ($replies[] | "reply=\(.html_url)")
          end
      end'
}

owner_answered "$@"
