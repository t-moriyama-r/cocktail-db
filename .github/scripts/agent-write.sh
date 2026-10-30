#!/usr/bin/env bash
# Actions の Claude Code が、PR のレビューのスレッドへの返信・スレッドの解決・PR の本文の書き換えをする(書き込むだけで、何も読まない)
#
# 使い方:
#   agent-write.sh reply <スレッドの ID(PRRT_…)> <返信の本文のファイル>   … 書いた返信の URL を出す
#   agent-write.sh resolve <スレッドの ID(PRRT_…)>                      … 「resolved <ID>」を出す
#   agent-write.sh body <PR の本文のファイル>                            … 「updated #<番号>」を出す
# 対象のリポジトリと PR は、このスクリプトと同じディレクトリの agent-write.target(repo=<owner/repo> と pr=<番号> の行)で渡す。
# 無ければ環境変数 REPO・PR を使う。どちらも引数では受け取らない(Claude Code に別の PR を選ばせない)
#
# Claude Code には、PR・issue のコメントをその場で読める gh(gh pr view・gh api graphql など)を渡さない。
# 読むのは動かす前に取ったもの(trusted-context.sh)だけにして、確かめた後に書かれたものを読ませないため(#498)。
# そのため GraphQL の問い合わせはここに固定で書き、返すものは書いたものの URL・ID だけにする
# (問い合わせを渡せると、返信と一緒にスレッドのほかのコメントを返させて読めてしまう)
set -euo pipefail

USAGE="使い方: agent-write.sh reply <スレッドの ID> <本文のファイル> | resolve <スレッドの ID> | body <本文のファイル>"
MARK='<!-- ai-dev:agent -->'
HERE=$(cd "$(dirname "$0")" && pwd)

agent_write() {
  local cmd=${1:-}
  if [ -f "$HERE/agent-write.target" ]; then
    REPO=$(sed -n 's/^repo=//p' "$HERE/agent-write.target")
    PR=$(sed -n 's/^pr=//p' "$HERE/agent-write.target")
  fi
  if [ -z "${REPO:-}" ] || [ -z "${PR:-}" ]; then
    echo "対象のリポジトリと PR が分からない(agent-write.target も、環境変数 REPO・PR も無い)" >&2
    return 2
  fi
  case "$cmd" in
    reply)
      [ $# -eq 3 ] || usage
      thread_id "$2"
      body_file "$3"
      reply "$2" "$3"
      ;;
    resolve)
      [ $# -eq 2 ] || usage
      thread_id "$2"
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
