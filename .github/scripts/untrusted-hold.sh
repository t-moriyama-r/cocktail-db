#!/usr/bin/env bash
# Claude Code を動かす前に、PR・issue に信頼しない人が書いたものが無いかを確かめ、あれば止めて人に頼む
#
# 使い方: untrusted-hold.sh <owner/repo> <PR か issue の番号>
#
# 終わりのコード: 0 … 動かしてよい / 1 … 動かさない(信頼しないものがある・読めなかった)
#
# Claude Code は PR・issue のコメントを読み、書き込みのできる権限で動く。public のリポジトリでは誰でもコメントできるので、
# 外部の人のコメントで指示を渡されないよう、信頼しないもの(決め方は untrusted-comments.sh)があれば動かさない。
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
  local repo=${1:-} n=${2:-} numbers found body marked
  if [ -z "$repo" ] || [ -z "$n" ]; then
    echo "$USAGE" >&2
    return 2
  fi
  numbers=$n
  if gh api "repos/$repo/issues/$n" --jq 'has("pull_request")' | grep -qx true; then
    numbers="$n $(closing_issues "$repo" "$n")"
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
  # 同じものを前の回でもう頼んでいれば書かない(目印の付いたコメントに、いちばん新しい信頼しないものの URL があるか)
  # (パイプに直接つなぐと、grep -q が先に終わったときに gh が SIGPIPE で落ち、pipefail で「無い」と読まれるので、変数に受ける)
  marked=$(gh api --paginate "repos/$repo/issues/$n/comments?per_page=100" --jq ".[].body | select(contains(\"$MARK\"))")
  if ! grep -qF "$(tail -1 <<<"$found" | awk '{print $1}') " <<<"$marked"; then
    gh api "repos/$repo/issues/$n/comments" -f body="$body" >/dev/null
  fi
  gh api -X POST "repos/$repo/issues/$n/labels" -f "labels[]=needs-human" >/dev/null
  return 1
}

# PR を閉じる issue のうち、このリポジトリのもの
closing_issues() {
  local repo=$1 n=$2
  gh pr view "$n" -R "$repo" --json closingIssuesReferences \
    | jq -r --arg repo "$repo" '.closingIssuesReferences[] | select(.repository.owner.login + "/" + .repository.name == $repo) | .number' \
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
