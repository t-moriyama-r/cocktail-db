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
  local cmd=${1:-} REPO PR
  REPO=$(sed -n 's/^repo=//p' "$HERE/agent-write.target" 2>/dev/null || true)
  PR=$(sed -n 's/^pr=//p' "$HERE/agent-write.target" 2>/dev/null || true)
  if [ -z "$REPO" ] || [ -z "$PR" ]; then
    echo "対象のリポジトリと PR が分からない(agent-write.target が無い)" >&2
    return 2
  fi
  case "$cmd" in
    reply)
      [ $# -eq 3 ] || usage
      thread_id "$2"
      same_pr "$2" "$REPO" "$PR"
      body_file "$3"
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
      gh api -X PATCH "repos/$REPO/pulls/$PR" -F body=@"$2" --silent
      echo "updated #$PR"
      ;;
    *) usage ;;
  esac
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
