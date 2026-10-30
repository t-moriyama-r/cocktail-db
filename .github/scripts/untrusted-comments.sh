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
# 信頼するかの決め方は trust.jq(Claude Code に読ませるものを書き出す trusted-context.sh と同じ)。
# 本文(PR・issue を作った人)も、コメントと同じく見る
set -euo pipefail

USAGE="使い方: untrusted-comments.sh <owner/repo> <PR か issue の番号>..."
HERE=$(cd "$(dirname "$0")" && pwd)

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
    printf '%s\n' "$item" "$issue" "$review" "$reviews" | jq -r -n -L "$HERE" --arg owner "${repo%%/*}" '
      include "trust";
      input as $item | input as $issue | input as $review | input as $reviews
      | [$item] + ($issue | add // []) + ($review | add // []) + ($reviews | add // [])
      | .[] | select(trusted($owner) | not)
      | "\(.html_url) \(.user.login) \(.author_association)"'
  done
}

untrusted_comments "$@"
