#!/usr/bin/env bash
# PR の AI レビュアー(CodeRabbit・代わりの Claude)のスレッドのうち、返信を待っているものの数を出す
#
# 使い方: unanswered-review-threads.sh <owner/repo> <PR の番号>
#
# エージェントの返信は人と同じアカウントで投稿されるため、作者ではなく目印 <!-- ai-dev:agent --> で見分ける。
# 代わりの Claude のレビュー(<!-- ai-dev:claude-review -->)も同じ目印を付けるが、それは指摘であって返信ではない。
# 直さない理由を返したスレッドは解決にしないので、未解決かどうかだけでは返したかどうかが分からず、最後のコメントを見る
set -euo pipefail

repo=$1
number=$2

gh api graphql -F owner="${repo%%/*}" -F name="${repo#*/}" -F number="$number" -f query='
  query($owner: String!, $name: String!, $number: Int!) {
    repository(owner: $owner, name: $name) {
      pullRequest(number: $number) {
        reviewThreads(first: 100) {
          nodes {
            isResolved
            first: comments(first: 1) { nodes { author { login } body } }
            last: comments(last: 1) { nodes { body } }
          }
        }
      }
    }
  }' --jq '[.data.repository.pullRequest.reviewThreads.nodes[]
    | select(.isResolved | not)
    | select(.first.nodes[0] as $c
        | $c.author.login == "coderabbitai"
          or ($c.author.login == "github-actions" and ($c.body | contains("ai-dev:claude-review"))))
    | select(.last.nodes[0].body | (contains("ai-dev:agent") and (contains("ai-dev:claude-review") | not)) | not)
  ] | length'
