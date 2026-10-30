#!/usr/bin/env bash
# Claude Code に読ませる PR・issue の中身を、信頼する人が書いたものだけにしてファイルに書き出す
#
# 使い方: trusted-context.sh <owner/repo> <PR か issue の番号> <書き出すファイル>
#
# 書き出すもの(JSON。書き手を信頼しないものは入れない。決め方は trust.jq):
#   item           … 番号・タイトル・URL・ラベル・本文(書いた人を信頼しなければ null)。PR なら head・base・draft も
#   comments       … PR・issue のコメント(古い順)
#   reviews        … PR のレビュー(本文のあるもの。古い順)
#   threads        … PR のレビューのスレッド。id は返信・解決に使うスレッドの ID(PRRT_…)。
#                    最初のコメントの書き手を信頼しないスレッドは、まるごと入れない
#   closing_issues … PR が閉じる issue(このリポジトリのもの)の item と comments
# コメントは id・url・author・association・created_at・body を持つ。
#
# untrusted-hold.sh は動かす前に確かめるが、Claude Code がその場で gh で読むと、確かめた後に書かれたものまで読んでしまう(#498)。
# 動かす前にここで一度だけ取り、取ったものから信頼しないものを除いて渡す。除くのは取ったものと同じ中身からにする
# (確かめるために取り直すと、そのあいだに書かれたものが確かめずに混ざる)。
# 読めなかったときは 1 で終わり、ファイルを書かない(途中までの中身を渡さない)
set -euo pipefail

USAGE="使い方: trusted-context.sh <owner/repo> <PR か issue の番号> <書き出すファイル>"
HERE=$(cd "$(dirname "$0")" && pwd)

trusted_context() {
  local repo=${1:-} n=${2:-} out=${3:-} pr closing c
  if [ -z "$repo" ] || [ -z "$n" ] || [ -z "$out" ]; then
    echo "$USAGE" >&2
    return 2
  fi
  tmp=$(mktemp -d)
  trap 'rm -rf "$tmp"' EXIT

  fetch_item "$repo" "$n" "$tmp/item"
  if jq -e 'has("pull_request")' "$tmp/item" >/dev/null; then
    pr=$(gh pr view "$n" -R "$repo" --json headRefName,baseRefName,headRefOid,isDraft,closingIssuesReferences)
    jq '{head: .headRefName, base: .baseRefName, head_sha: .headRefOid, draft: .isDraft}' <<<"$pr" >"$tmp/pr"
    gh api --paginate --slurp "repos/$repo/pulls/$n/comments?per_page=100" >"$tmp/review_comments"
    gh api --paginate --slurp "repos/$repo/pulls/$n/reviews?per_page=100" >"$tmp/reviews"
    review_threads "$repo" "$n" >"$tmp/threads"
    closing=$(jq -r --arg repo "$repo" \
      '.closingIssuesReferences[] | select(.repository.owner.login + "/" + .repository.name == $repo) | .number' <<<"$pr")
  else
    echo null >"$tmp/pr"
    echo '[[]]' >"$tmp/review_comments"
    echo '[[]]' >"$tmp/reviews"
    echo '[]' >"$tmp/threads"
    closing=
  fi
  : >"$tmp/closing"
  for c in $closing; do
    fetch_item "$repo" "$c" "$tmp/closing-item"
    jq -c . "$tmp/closing-item" "$tmp/closing-item.comments" >>"$tmp/closing"
  done

  # コメントの多い PR では JSON が 1 引数の上限(Linux は 128KB)を超えるので、jq には引数ではなく標準入力で渡す(#469)
  cat "$tmp/item" "$tmp/item.comments" "$tmp/pr" "$tmp/review_comments" "$tmp/reviews" "$tmp/threads" "$tmp/closing" \
    | jq -n -L "$HERE" --arg owner "${repo%%/*}" '
      include "trust";
      def entry: {id, url: .html_url, author: .user.login, association: .author_association, created_at: (.created_at // .submitted_at), body}
        + (if .state then {state, commit_id} else {} end);
      def keep: map(select(trusted($owner))) | sort_by(.created_at // .submitted_at) | map(entry);
      def item_of: {number, title, url: .html_url, author: .user.login, labels: [.labels[].name],
        body: (if trusted($owner) then .body else null end)};
      input as $item | input as $comments | input as $pr | input as $rc | input as $reviews | input as $threads | [inputs] as $closing
      | ($rc | add // []) as $rc
      | {
          note: "Claude Code を動かす前に取った PR・issue の中身。書いた人を信頼しないものは除いてある",
          item: (($item | item_of) + ($pr // {})),
          comments: ($comments | add // [] | keep),
          reviews: ($reviews | add // [] | map(select((.body // "") != "")) | keep),
          threads: [$threads[] | .root as $root
            | select(any($rc[]; .id == $root and trusted($owner)))
            | {id, resolved, outdated, path, line,
               comments: ($rc | map(select(.id == $root or .in_reply_to_id == $root)) | keep)}],
          closing_issues: [range(0; $closing | length; 2) as $i
            | {item: ($closing[$i] | item_of), comments: ($closing[$i + 1] | add // [] | keep)}]
        }' >"$tmp/out"
  mv "$tmp/out" "$out"
  jq -r --arg n "$n" '"#\($n): コメント \(.comments | length) 件・レビュー \(.reviews | length) 件・スレッド \(.threads | length) 件・閉じる issue \(.closing_issues | length) 件を書き出した"' "$out"
}

# 本文(<file>)とコメント(<file>.comments。ページの配列)
fetch_item() {
  local repo=$1 n=$2 file=$3
  gh api "repos/$repo/issues/$n" >"$file"
  gh api --paginate --slurp "repos/$repo/issues/$n/comments?per_page=100" >"$file.comments"
}

# スレッドの ID・解決したか・最初のコメントの ID。中のコメントは REST で取ったものを、最初のコメントへの返信(in_reply_to_id)で寄せる。
# GraphQL のコメントは bot のログイン名に [bot] が付かず、trust.jq で見分けられないので、ここでは書き手も本文も取らない
review_threads() {
  local repo=$1 n=$2
  gh api graphql --paginate -F owner="${repo%%/*}" -F name="${repo#*/}" -F number="$n" -f query='
    query($owner: String!, $name: String!, $number: Int!, $endCursor: String) {
      repository(owner: $owner, name: $name) {
        pullRequest(number: $number) {
          reviewThreads(first: 100, after: $endCursor) {
            pageInfo { hasNextPage endCursor }
            nodes { id isResolved isOutdated path line comments(first: 1) { nodes { databaseId } } }
          }
        }
      }
    }' --jq '.data.repository.pullRequest.reviewThreads.nodes[]
      | {id, resolved: .isResolved, outdated: .isOutdated, path, line, root: .comments.nodes[0].databaseId}' | jq -s -c .
}

trusted_context "$@"
