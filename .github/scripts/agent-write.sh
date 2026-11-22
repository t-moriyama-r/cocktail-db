#!/usr/bin/env bash
# Actions の Claude Code が、PR のレビューのスレッドへの返信・スレッドの解決・PR の本文の書き換えをする(書き込むだけで、何も読まない)
#
# 使い方:
#   agent-write.sh reply <スレッドの ID(PRRT_…)> <返信の本文のファイル>   … 書いた返信の URL を出す
#   agent-write.sh resolve <スレッドの ID(PRRT_…)>                      … 「resolved <ID>」を出す
#   agent-write.sh body <PR の本文のファイル>                            … 「updated #<番号>」を出す
# 対象のリポジトリと PR は、このスクリプトと同じディレクトリの agent-write.target(repo=<owner/repo> と pr=<番号> の行)で受け取り、
# 引数では受け取らない(動かす人に、別の PR を選ばせない)
#
# 書くだけで読まないことが、このスクリプトの役目(#498)。そのため GraphQL の問い合わせはここに固定で書き、
# 返すものは書いたものの URL・ID だけにする(問い合わせを渡せると、返信と一緒にスレッドのほかのコメントを返させて読めてしまう)
set -euo pipefail

USAGE="使い方: agent-write.sh reply <スレッドの ID> <本文のファイル> | resolve <スレッドの ID> | body <本文のファイル>"
MARK='<!-- ai-dev:agent -->'
HERE=$(cd "$(dirname "$0")" && pwd)

agent_write() {
  local cmd=${1:-} REPO PR BODY_SHA now
  REPO=$(sed -n 's/^repo=//p' "$HERE/agent-write.target" 2>/dev/null || true)
  PR=$(sed -n 's/^pr=//p' "$HERE/agent-write.target" 2>/dev/null || true)
  BODY_SHA=$(sed -n 's/^body_sha=//p' "$HERE/agent-write.target" 2>/dev/null || true)
  if [ -z "$REPO" ] || [ -z "$PR" ]; then
    echo "対象のリポジトリと PR が分からない(agent-write.target が無い)" >&2
    return 2
  fi
  case "$cmd" in
    reply)
      [ $# -eq 3 ] || usage
      thread_id "$2"
      body_file "$3"
      same_pr "$2" "$REPO" "$PR"
      reply "$2" "$3"
      ;;
    resolve)
      [ $# -eq 2 ] || usage
      thread_id "$2"
      same_pr "$2" "$REPO" "$PR"
      gh api graphql -f id="$2" -f query='
        mutation($id: ID!) { resolveReviewThread(input: {threadId: $id}) { thread { id } } }' \
        --jq '"resolved \(.data.resolveReviewThread.thread.id)"'
      ;;
    body)
      [ $# -eq 2 ] || usage
      body_file "$2"
      # 実行の開始時の本文の sha256(agent-write.target の body_sha=)があれば、いまの本文と比べ、実行中に人が編集していたら書かない
      if [ -n "$BODY_SHA" ]; then
        [ -s "$(body_sha_state "$REPO" "$PR")" ] && BODY_SHA=$(cat "$(body_sha_state "$REPO" "$PR")")
        now=$(gh api "repos/$REPO/pulls/$PR" --jq '.body // ""' | sha256sum | cut -d' ' -f1)
        if [ "$now" != "$BODY_SHA" ]; then
          echo "PR の本文が実行中に編集されたので書かない(人の編集を消さないため)" >&2
          return 3
        fi
      fi
      # 同じ実行内で本文を続けて書けるよう、書いた後の本文の sha256 を次の比較の基準にする(その後の人の編集は引き続き見つける)。
      # 基準は PATCH の応答の本文から作る(PATCH と取り直しの間の人の編集を、基準に取り込まないため)。
      # agent-write.target は読み取り専用の置き場にあって書き換えられないので、基準は別のファイルに残す
      if [ -n "$BODY_SHA" ]; then
        gh api -X PATCH "repos/$REPO/pulls/$PR" -F body=@"$2" --jq '.body // ""' | sha256sum | cut -d' ' -f1 >"$(body_sha_state "$REPO" "$PR")"
      else
        gh api -X PATCH "repos/$REPO/pulls/$PR" -F body=@"$2" --silent
      fi
      echo "updated #$PR"
      ;;
    *) usage ;;
  esac
}

# 本文を書いた後の sha256 を残すファイル(実行ごとの一時の置き場。PR ごとに分ける)
body_sha_state() {
  echo "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/agent-write-body-sha-${1//\//_}-$2"
}

usage() {
  echo "$USAGE" >&2
  exit 2
}

# スレッドの ID は GraphQL の node ID(PRRT_…)だけを受け取る
thread_id() {
  if ! [[ $1 =~ ^PRRT_[A-Za-z0-9_-]+$ ]]; then
    echo "スレッドの ID は PRRT_ で始まる ID を渡す: $1" >&2
    exit 2
  fi
}

# スレッドが対象の PR のものかを確かめる(node ID はリポジトリをまたいで効くので、別の PR のスレッドを書かせない)。返すのは真偽だけ
same_pr() {
  local id=$1 repo=$2 pr=$3 got
  got=$(gh api graphql -f id="$id" -f query='
    query($id: ID!) { node(id: $id) { ... on PullRequestReviewThread { pullRequest { number repository { nameWithOwner } } } } }' \
    --jq '.data.node.pullRequest | "\(.repository.nameWithOwner)#\(.number)"' 2>/dev/null || true)
  if [ "$got" != "$repo#$pr" ]; then
    echo "そのスレッドは対象の PR のものではない" >&2
    exit 2
  fi
}

body_file() {
  if ! [ -s "$1" ]; then
    echo "本文のファイルが無いか空です: $1" >&2
    exit 2
  fi
}

# 返信の先頭にはエージェントの目印を付ける(付いていなければ足す)。人の返信と取り違えて、ワークフローが起きないようにするため
reply() {
  local id=$1 file=$2 body
  body=$(cat "$file")
  [[ $body == "$MARK"* ]] || body="$MARK $body"
  gh api graphql -f id="$id" -f body="$body" -f query='
    mutation($id: ID!, $body: String!) {
      addPullRequestReviewThreadReply(input: {pullRequestReviewThreadId: $id, body: $body}) { comment { url } }
    }' --jq '.data.addPullRequestReviewThreadReply.comment.url'
}

agent_write "$@"
