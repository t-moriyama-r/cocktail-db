#!/usr/bin/env bash
# Claude Code を動かす前に、PR・issue に信頼しない人が書いたものが無いかを確かめ、あれば止めて人に頼む
#
# 使い方: untrusted-hold.sh <owner/repo> <PR か issue の番号>
#
# 終わりのコード: 0 … 動かしてよい / 1 … 動かさない(信頼しないものがある・読めなかった)
#
# Claude Code は PR・issue のコメントを読み、書き込みのできる権限で動く。public のリポジトリでは誰でもコメントできるので、
# 外部の人のコメントで指示を渡されないよう、信頼しないもの(決め方は trust.jq)があれば動かさない。
# PR なら、Claude Code が一緒に読む閉じる issue(このリポジトリのもの)も見る。
#
# 信頼しないものがあれば、その URL を並べたコメントを 1 回だけ書き(同じ中身のコメントがもうあれば書かない)、needs-human を付ける。
# needs-human の付いた PR・issue は、AI のワークフローが拾わず、ai-auto-merge.yml もマージしない。
# 読めなかったときは、たまたまの失敗のこともあるので、コメントもラベルも付けずに止めるだけにする(次の回で確かめ直す)
set -euo pipefail

USAGE="使い方: untrusted-hold.sh <owner/repo> <PR か issue の番号>"
HERE=$(cd "$(dirname "$0")" && pwd)
MARK='<!-- ai-dev:untrusted -->'

untrusted_hold() {
  local repo=${1:-} n=${2:-} numbers is_pr closing found body marked
  if [ -z "$repo" ] || [ -z "$n" ]; then
    echo "$USAGE" >&2
    return 2
  fi
  numbers=$n
  # PR かどうか・閉じる issue を読めなかったときに、閉じる issue を見ないまま「動かしてよい」にしない
  if ! is_pr=$(gh api "repos/$repo/issues/$n" --jq 'has("pull_request")'); then
    echo "#$n: PR か issue かを確かめられなかったので、Claude Code を動かさない(次の回で確かめ直す)"
    return 1
  fi
  if [ "$is_pr" = true ]; then
    if ! closing=$(closing_issues "$repo" "$n"); then
      echo "#$n: 閉じる issue を確かめられなかったので、Claude Code を動かさない(次の回で確かめ直す)"
      return 1
    fi
    numbers="$n $closing"
  fi
  # shellcheck disable=SC2086 # 番号を 1 つずつの引数にする
  if ! found=$(bash "$HERE/untrusted-comments.sh" "$repo" $numbers); then
    echo "#$n: 書いた人を確かめられなかったので、Claude Code を動かさない(次の回で確かめ直す)"
    return 1
  fi
  [ -n "$found" ] || return 0

  echo "#$n: オーナー・コラボレーター・決まった bot 以外の人が書いたものがあるので、Claude Code を動かさない"
  echo "$found"
  body=$(hold_body "$found")
  # 同じものを前の回でもう頼んでいれば書かない(信頼しないものの URL が、すべて目印の付いたコメントにあるか)。
  # untrusted-comments.sh の出力は書いた順ではないので、どれか 1 件だけでは新しく書かれたものを見落とす
  # 頼むコメントは github-actions[bot] が書く。外の人が目印をまねたコメントは頼み済みに数えない(untrusted-notify.sh と同じ)
  # (パイプに直接つなぐと、grep -q が先に終わったときに gh が SIGPIPE で落ち、pipefail で「無い」と読まれるので、変数に受ける)
  marked=$(gh api --paginate "repos/$repo/issues/$n/comments?per_page=100" --jq ".[] | select(.user.login == \"github-actions[bot]\" and (.body | contains(\"$MARK\"))) | .body")
  if ! all_asked "$found" "$marked"; then
    gh api "repos/$repo/issues/$n/comments" -f body="$body" >/dev/null
  fi
  gh api -X POST "repos/$repo/issues/$n/labels" -f "labels[]=needs-human" >/dev/null
  return 1
}

# 信頼しないもの(<URL> <ログイン名> <author_association> の行)の URL が、すべて前に頼んだコメントにあるか。
# 頼むコメントは URL の後ろを空白にしているので、URL に空白を付けて探し、前方が同じ別の URL と取り違えない
all_asked() {
  local found=$1 marked=$2 url
  while read -r url _; do
    grep -qF "$url " <<<"$marked" || return 1
  done <<<"$found"
}

# PR を閉じる issue のうち、このリポジトリのもの
closing_issues() {
  local repo=$1 n=$2
  gh pr view "$n" -R "$repo" --json closingIssuesReferences \
    | jq -r -L "$HERE" --arg repo "$repo" 'include "closing-issues"; own_closing_issues($repo)' \
    | tr '\n' ' '
}

# 信頼しないもの(<URL> <ログイン名> <author_association> の行)を並べた、人に頼むコメント。URL の後ろは空白にする(CLAUDE.md)
hold_body() {
  local found=$1
  printf '%s\n' "<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> $MARK"
  printf '%s\n\n' 'オーナー・コラボレーター・決まった bot 以外の人が書いたものがあるので、AI はこの PR・issue では動かず、自動でもマージしません。中身を確かめてください。'
  awk '{ printf "- %s (%s・%s)\n", $1, $2, $3 }' <<<"$found"
  printf '\n%s\n' '問題が無ければ、そのコメントを消してから `needs-human` を外すと、見回りが続きを拾います。消さずに残すなら、この PR・issue は手で進めてください。'
}

untrusted_hold "$@"
