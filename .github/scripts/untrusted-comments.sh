#!/usr/bin/env bash
# PR・issue に、信頼しない人が書いたものがあるかを見る
#
# 使い方: untrusted-comments.sh <owner/repo> <PR か issue の番号>...
#
# 信頼しないものを 1 件 1 行(<URL> <ログイン名> <author_association>)で標準出力に出す。無ければ何も出さない。
# 読めなかった(API が失敗した)ときは 1 で終わる。呼ぶ側は、終わりのコードが 0 でないときも「信頼しないものがある」として扱う。
#
# public のリポジトリでは誰でもコメントでき、Actions やルーチンの Claude Code はそれを読んで、書き込みのできる権限で動く。
# 外部の人がコメントで Claude Code に指示を渡し、自動マージとデプロイまで届かせられないよう、読ませる前にここで止める。
#
# - 信頼する: author_association が OWNER・MEMBER・COLLABORATOR の人と、このリポジトリに入れた決まった bot(TRUSTED_BOTS)。
#   bot はアカウントの種類ではなくログイン名で決める(どの App を入れるかはオーナーが決めるが、種類だけでは見分けない)
# - bot のコメントでも、先頭がオーナー以外の人への宛名(@<login>)なら信頼しない。CodeRabbit は誰の問いかけにも返事をするので、
#   外部の人が自分の問いかけを消しても、bot の返事を通して指示を残せるため
# - 本文(PR・issue を作った人)も、コメントと同じく見る
set -euo pipefail

USAGE="使い方: untrusted-comments.sh <owner/repo> <PR か issue の番号>..."
TRUSTED_BOTS='["coderabbitai[bot]","github-actions[bot]","claude[bot]","github-advanced-security[bot]"]'

untrusted_comments() {
  local repo=${1:-} n item issue review reviews
  if [ -z "$repo" ] || [ $# -lt 2 ]; then
    echo "$USAGE" >&2
    return 2
  fi
  shift
  for n in "$@"; do
    item=$(gh api "repos/$repo/issues/$n")
    issue=$(gh api --paginate --slurp "repos/$repo/issues/$n/comments?per_page=100")
    review='[]'
    reviews='[]'
    if jq -e 'has("pull_request")' <<<"$item" >/dev/null; then
      review=$(gh api --paginate --slurp "repos/$repo/pulls/$n/comments?per_page=100")
      reviews=$(gh api --paginate --slurp "repos/$repo/pulls/$n/reviews?per_page=100")
    fi
    # コメントの多い PR では JSON が 1 引数の上限(Linux は 128KB)を超えるので、jq には引数ではなく標準入力で渡す(#469)
    printf '%s\n' "$item" "$issue" "$review" "$reviews" | jq -r -n --arg owner "${repo%%/*}" --argjson bots "$TRUSTED_BOTS" '
      def person: .author_association | IN("OWNER", "MEMBER", "COLLABORATOR");
      def bot: .user.login as $l | $bots | index($l);
      def addressed: .body // "" | capture("^\\s*(<!--.*?-->\\s*)*@(?<to>[A-Za-z0-9-]+)")?.to // null
        | . != null and . != $owner;
      def trusted: person or (bot and (addressed | not));
      input as $item | input as $issue | input as $review | input as $reviews
      | [$item] + ($issue | add // []) + ($review | add // []) + ($reviews | add // [])
      | .[] | select(trusted | not)
      | "\(.html_url) \(.user.login) \(.author_association)"'
  done
}

untrusted_comments "$@"
