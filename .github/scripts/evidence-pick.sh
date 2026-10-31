#!/usr/bin/env bash
# PR のコメントから、証跡を頼むコメント(<!-- ai-dev:needs-local kind=evidence --> と ```evidence のブロックがあるもの)の
# いちばん新しいものを選び、本文を出す(#600。evidence-capture.yml が撮るものを決めるのに使う)
#
# 使い方: evidence-pick.sh <owner/repo> <PR の番号>
#
# 出力: 選んだコメントの本文を標準出力に出して 0 で終わる。頼むコメントが無ければ、無いと標準エラーに出して 1 で終わる。
# 読めなかった(API が失敗した)ときも 1(無いことと取り違えないよう、出す文を変える)。引数の誤りは 2。
#
# 目印だけで選ぶと、外の人が同じ目印を書いたコメントを撮らせられる(撮る側はブロックの run をシェルで流す)ので、
# 書いた人も見る(RC-05)。選ぶのは author_association が OWNER・MEMBER・COLLABORATOR のコメントだけ。
# PR に信頼しない人が書いたものがあるかは、呼ぶ側が untrusted-comments.sh で別に確かめる(#494)。
set -euo pipefail

USAGE="使い方: evidence-pick.sh <owner/repo> <PR の番号>"

evidence_pick() {
  local repo=${1:-} number=${2:-} comments body
  if [ -z "$repo" ] || [ -z "$number" ] || [ $# -ne 2 ]; then
    echo "$USAGE" >&2
    return 2
  fi
  if ! comments=$(gh api --paginate --slurp "repos/$repo/issues/$number/comments?per_page=100"); then
    echo "PR #$number のコメントを読めなかった" >&2
    return 1
  fi
  # コメントの多い PR では JSON が 1 引数の上限を超えるので、jq には標準入力で渡す(#469)
  body=$(jq -r '
    add // []
    | map(select(.author_association | IN("OWNER", "MEMBER", "COLLABORATOR")))
    | map(select((.body // "") | test("<!--\\s*ai-dev:needs-local\\s+kind=evidence\\s*-->") and test("(?m)^```evidence\\s*$")))
    | sort_by(.created_at) | last | .body // empty' <<<"$comments")
  if [ -z "$body" ]; then
    echo "PR #$number に、信頼する人が書いた証跡を頼むコメント(<!-- ai-dev:needs-local kind=evidence --> と \`\`\`evidence のブロック)が無い" >&2
    return 1
  fi
  # 画面から書いたコメントは改行が CRLF になるので、ブロックの区切りを読めるよう CR を落とす
  printf '%s\n' "$body" | tr -d '\r'
}

evidence_pick "$@"
