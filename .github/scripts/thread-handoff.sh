#!/usr/bin/env bash
# エージェントが返信したのに解決(Resolve)されていないレビュースレッドだけで自動マージが止まった PR を、人の番にし、
# 人がスレッドをすべて解決したら、自動マージの判定に戻す
#
# 使い方:
#   thread-handoff.sh check <owner/repo> <PR の番号>    「<未解決のスレッドの数> <そのうち返信待ちの数>」を出す
#   thread-handoff.sh ask <owner/repo> <PR の番号>      残っているスレッドへのリンクと決めてほしいことをコメントし、needs-human を付けて Slack に知らせる
#   thread-handoff.sh release <owner/repo> <PR の番号>  ask で付けた needs-human を、スレッドがすべて解決されていたら外し、ai-auto-merge.yml を呼ぶ
#
# Actions の Claude Code が「直さない」と返したスレッドは、人が判断できるよう解決にしない。自動マージは未解決のスレッドが
# 残る PR をマージしない(develop のブランチ保護も会話の解決を求める)ので、誰かが解決するまで止まる。返信待ちのスレッドが
# 無いので見回りも「対応済み」とみなし、人に何も届かないまま止まっていた(#388)。人が動くのは Slack に知らせが来たときだけ。
#
# 返信待ちは、ai-review-loop.yml が対応する AI レビュアーのスレッドのうち、エージェントがまだ返していないもの
# (unanswered-review-threads.sh。見回りと同じ数え方)。それ以外の未解決のスレッド(エージェントが返したもの・人が立てたもの)は、
# 人が解決するか返信するまで誰も動かないので、人の番として頼む。
#
# ただし、手元に頼んだと返したスレッド(目印 ai-dev:local-pending kind=<種類>)は、人が決めることが無いので頼まない。
# 手元が片付けていれば(返信より後に同じ種類の ai-dev:local-done)解決にし、まだなら needs-local で見回りの番にする。
# 手元はスレッドに返さずに local-done だけを書くことがあり、「直さない」と同じ扱いで人に頼んでしまっていた(#682)。
# 返信の文面や時刻の差では「直さない」と見分けられないので、目印で見分ける。
#
# 頼むのはコミットごとに 1 回(目印 ai-dev:thread-handoff sha=<コミット>)。頼むコメントは、Slack の通知が引用するので
# ラベルより先に書く。コメントだけ書けて止まった回の後は、コメントを書き直さずにラベルと通知だけを足す。
# スレッドを解決してもワークフローは起きない(pull_request_review_thread は Actions のトリガーに無い)ので、
# 外すのは見回り(ai-review-sweep.yml)。人がほかの理由で付けた needs-human を外さないよう、いちばん新しい
# 頼むコメント(目印 ai-dev:needs-human)がこの理由のもので、ラベルを最後に付けたのが Actions のときだけ外す
set -euo pipefail

MARKER=ai-dev:thread-handoff
LOCAL_MARKER=ai-dev:thread-handoff-local
HERE=$(cd "$(dirname "$0")" && pwd)
USAGE="使い方: thread-handoff.sh check|ask|release <owner/repo> <PR の番号>"

thread_handoff() {
  local mode=${1:-} repo=${2:-} n=${3:-}
  if [ -z "$repo" ] || [ -z "$n" ]; then
    echo "$USAGE" >&2
    return 2
  fi
  case "$mode" in
    check) check "$repo" "$n" ;;
    ask) ask "$repo" "$n" ;;
    release) release "$repo" "$n" ;;
    *)
      echo "$USAGE" >&2
      return 2
      ;;
  esac
}

check() {
  local repo=$1 n=$2 threads waiting
  threads=$(unresolved_threads "$repo" "$n")
  waiting=$(bash "$HERE/unanswered-review-threads.sh" "$repo" "$n")
  echo "$(jq length <<<"$threads") $waiting"
}

ask() {
  local repo=$1 n=$2 pr sha marker threads count waiting links comments labeled
  pr=$(gh pr view "$n" -R "$repo" --json headRefOid,labels)
  sha=$(jq -r .headRefOid <<<"$pr")
  marker="$MARKER sha=$sha"
  threads=$(unresolved_threads "$repo" "$n")
  count=$(jq length <<<"$threads")
  if [ "$count" = 0 ]; then
    echo "#$n 見送り: 未解決のスレッドが無い"
    return
  fi
  waiting=$(bash "$HERE/unanswered-review-threads.sh" "$repo" "$n")
  if [ "$waiting" != 0 ]; then
    echo "#$n 見送り: 返信待ちのスレッドがある(ai-review-loop.yml が対応する)"
    return
  fi

  comments=$(issue_comments "$repo" "$n")
  threads=$(classify_local_pending "$threads" "$comments" "$LOCAL_MARKER sha=$sha")
  threads=$(settle_local_done "$repo" "$n" "$threads")
  count=$(jq length <<<"$threads")
  if [ "$count" = 0 ]; then
    # GITHUB_TOKEN の解決はワークフローを起こさないので、止めるものが無くなった判定を直接呼び直す
    gh workflow run ai-auto-merge.yml -R "$repo" -f number="$n" || echo "::warning::#$n の ai-auto-merge.yml を呼べなかった" >&2
    echo "#$n 手元が片付けたスレッドを解決したので、ai-auto-merge.yml を呼んだ"
    return
  fi
  if jq -e 'any(.local == "open")' <<<"$threads" >/dev/null; then
    ask_local "$repo" "$n" "$sha" "$threads" "$comments"
    return
  fi

  if jq -e --arg m "$marker" 'any(.body // "" | contains($m))' <<<"$comments" >/dev/null; then
    echo "#$n このコミット(${sha:0:7})ではもう頼むコメントを書いてある"
  else
    links=$(jq -r '.[] | "- \(.url)\(if .path then " (`\(.path)`)" else "" end)"' <<<"$threads")
    gh pr comment "$n" -R "$repo" --body "<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- $marker -->
解決(Resolve)されていないスレッドが $count 件残っていて、自動マージが止まっている(AI レビュアーの指摘にはエージェントが返信済み・CI は緑・コンフリクトなし)。スレッドごとに決めてほしい。

- 返信の内容で良ければ、そのスレッドを Resolve する
- 直してほしければ、そのスレッドに返信する(Actions の Claude Code が直す)

すべてのスレッドが解決されると、見回り(\`ai-review-sweep.yml\`)が needs-human を外し、自動マージの判定に戻す。

残っているスレッド:
$links"
  fi

  labeled=$(jq -r 'any(.labels[]?; .name == "needs-human")' <<<"$pr")
  if [ "$labeled" = true ]; then
    echo "#$n にはもう needs-human が付いている"
    return
  fi
  gh pr edit "$n" -R "$repo" --add-label needs-human
  # GITHUB_TOKEN で付けたラベルは通知のワークフローを起こさないので、直接呼ぶ
  gh workflow run notify-needs-human.yml -R "$repo" -f number="$n" -f label=needs-human
  echo "#$n 返信済みで未解決のスレッドが $count 件。needs-human を付けて人に頼んだ"
}

# スレッドごとに、手元に頼んだものかどうかと、その作業の様子を .local に足す。
# 目印を信じるのは、書き込み権限のある人と GitHub App の返信だけ(public のリポジトリでは誰でも返信できる)。
# 時刻の比較が要るのは、前の回の local-done を今の頼みごとの完了と取り違えないため。
# 同じコミットで手元に頼み直した後も片付いていないものは、手元では進まないとみなして "stuck" にする
classify_local_pending() {
  local threads=$1 comments=$2 asked=$3
  # コメントは長いことがあり、引数の長さの上限を超えるので、標準入力で渡す
  jq -s --arg asked "$asked" '
    .[1] as $comments
    | (any($comments[]; .body // "" | contains($asked))) as $already
    | .[0]
    | map(
        (.lastBody // "") as $body
        | (.lastTrusted and ($body | test("ai-dev:local-pending kind=[a-z]+"))) as $pending
        | (if $pending then ($body | capture("ai-dev:local-pending kind=(?<k>[a-z]+)").k) else "" end) as $kind
        | .lastAt as $at
        | ([$comments[] | select(.created_at > $at and (.body // "" | test("ai-dev:local-done kind=\($kind)\\b")))] | last | .html_url // "") as $done
        | . + {kind: $kind, doneUrl: $done, local: (
            if ($pending | not) then "none"
            elif $done != "" then "done"
            elif $already then "stuck"
            else "open" end)})
  ' <<<"$threads
$comments"
}

# 手元が片付けたスレッドは、人が決めることが無いので、返信して解決にし、残りのスレッドを出す。
# 解決できなかったものは、誰の番でもないまま止めないよう、残して人に頼む一覧に入れる
settle_local_done() {
  local repo=$1 n=$2 threads=$3 id url
  while IFS=$'\t' read -r id url; do
    [ -n "$id" ] || continue
    if resolve_with_reply "$id" "$url"; then
      threads=$(jq --arg id "$id" 'map(select(.id != $id))' <<<"$threads")
    else
      echo "::warning::#$n のスレッド $id を解決できなかったので、人に頼む" >&2
    fi
  done < <(jq -r '.[] | select(.local == "done") | [.id, .doneUrl] | @tsv' <<<"$threads" | tr -d '\r')
  printf '%s\n' "$threads"
}

resolve_with_reply() {
  local id=$1 url=$2
  gh api graphql -f threadId="$id" -f body="<!-- ai-dev:agent --> 手元で片付いたので解決にする: $url " -f query='
    mutation($threadId: ID!, $body: String!) {
      addPullRequestReviewThreadReply(input: {pullRequestReviewThreadId: $threadId, body: $body}) { comment { id } }
    }' >/dev/null || return 1
  gh api graphql -f threadId="$id" -f query='
    mutation($threadId: ID!) {
      resolveReviewThread(input: {threadId: $threadId}) { thread { isResolved } }
    }' >/dev/null
}

# 手元に頼んだのに needs-local が付いていないスレッドを、見回りの番に戻す。
# 人に頼むスレッドが一緒にあっても先にこちらをする。手元の作業で中身が変わりうるうえ、needs-local が外れると判定が呼び直される。
# 種類はスレッドの目印に合わせる。違う種類の local-done では、片付いたと判定できないため。
# 見回りが読む目印は 1 コメントに 1 つなので、種類が混ざるときは重いほうを書く。
# 見回りには Slack で知らせない(人がすることが無いため)
ask_local() {
  local repo=$1 n=$2 sha=$3 threads=$4 comments=$5 open links kind request
  open=$(jq '[.[] | select(.local == "open")]' <<<"$threads")
  links=$(jq -r '.[] | "- \(.url)\(if .path then " (`\(.path)`)" else "" end)"' <<<"$open" | tr -d '\r')
  kind=$(jq -r '[.[].kind] as $k | if ($k | index("evidence")) then "evidence" elif ($k | index("push")) then "push" else "other" end' <<<"$open" | tr -d '\r')
  # 差分や撮るものは前の頼むコメントにあるので、そこへのリンクを添える
  request=$(jq -r --arg k "$kind" '[.[] | select(.body // "" | contains("ai-dev:needs-local kind=\($k)"))] | last | .html_url // "(見つからなかった)"' <<<"$comments" | tr -d '\r')
  gh pr comment "$n" -R "$repo" --body "<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=$kind --> <!-- $LOCAL_MARKER sha=$sha -->
手元に頼んだと返したスレッドが $(jq length <<<"$open") 件、片付かないまま残っている(needs-local も local-done も無い)。人の判断は要らない。

スレッドと、前に PR に書いた頼むコメントのとおりに作業し、スレッドに何をしたかを返して解決にしてほしい。

前の頼むコメント: $request

$links"
  gh pr edit "$n" -R "$repo" --add-label needs-local
  echo "#$n 手元に頼んだスレッドが片付いていないので、needs-local を付けた"
}

release() {
  local repo=$1 n=$2 pr comments events latest actor threads count
  pr=$(gh pr view "$n" -R "$repo" --json state,labels)
  if [ "$(jq -r .state <<<"$pr")" != OPEN ]; then
    echo "#$n 見送り: 開いていない"
    return
  fi
  if ! jq -e 'any(.labels[]?; .name == "needs-human")' <<<"$pr" >/dev/null; then
    echo "#$n 見送り: needs-human が付いていない"
    return
  fi
  comments=$(issue_comments "$repo" "$n")
  latest=$(jq -r 'map(select(.body // "" | contains("ai-dev:needs-human"))) | last | .body // ""' <<<"$comments")
  if ! grep -qF "$MARKER" <<<"$latest"; then
    echo "#$n 見送り: needs-human はほかの理由で頼んでいる"
    return
  fi
  events=$(gh api --paginate --slurp "repos/$repo/issues/$n/events?per_page=100")
  actor=$(jq -r 'add // [] | map(select(.event == "labeled" and .label.name == "needs-human")) | last | .actor.login // ""' <<<"$events")
  if [ "$actor" != "github-actions[bot]" ]; then
    echo "#$n 見送り: needs-human を最後に付けたのは ${actor:-(不明)}(人が付け直した)"
    return
  fi
  threads=$(unresolved_threads "$repo" "$n")
  count=$(jq length <<<"$threads")
  if [ "$count" != 0 ]; then
    echo "#$n 見送り: 未解決のスレッドがまだ $count 件ある"
    return
  fi

  gh pr edit "$n" -R "$repo" --remove-label needs-human
  # GITHUB_TOKEN で外したラベルは unlabeled のイベントを起こさないので、Slack の「済」と自動マージの判定を直接呼ぶ。
  # 「済」は見た目の印なので、呼べなくても自動マージの判定まで進める
  gh workflow run notify-needs-human.yml -R "$repo" -f label=done || echo "::warning::#$n の Slack の通知に「済」を付けられなかった"
  gh workflow run ai-auto-merge.yml -R "$repo" -f number="$n"
  echo "#$n スレッドがすべて解決されたので、needs-human を外して ai-auto-merge.yml を呼んだ"
}

# 未解決のスレッドを、リンクとファイルの配列で出す
unresolved_threads() {
  local repo=$1 n=$2
  gh api graphql --paginate -F owner="${repo%%/*}" -F name="${repo#*/}" -F number="$n" -f query='
    query($owner: String!, $name: String!, $number: Int!, $endCursor: String) {
      repository(owner: $owner, name: $name) {
        pullRequest(number: $number) {
          reviewThreads(first: 100, after: $endCursor) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id
              isResolved
              path
              first: comments(first: 1) { nodes { url } }
              last: comments(last: 1) { nodes { body createdAt authorAssociation author { __typename } } }
            }
          }
        }
      }
    }' | jq -s '[.[].data.repository.pullRequest.reviewThreads.nodes[]
      | select(.isResolved | not)
      | .last.nodes[0] as $last
      | {id, url: .first.nodes[0].url, path, lastBody: $last.body, lastAt: $last.createdAt,
         lastTrusted: (($last.authorAssociation | IN("OWNER", "MEMBER", "COLLABORATOR")) or $last.author.__typename == "Bot")}]'
}

# PR のコメントのうち、目印を信じてよいもの(書き込み権限のある人と GitHub App)だけを出す。
# public のリポジトリでは誰でもコメントでき、目印を書き込めるため(notify-needs-human.yml と同じ見分け方)
issue_comments() {
  local repo=$1 n=$2
  gh api --paginate --slurp "repos/$repo/issues/$n/comments?per_page=100" \
    | jq 'add // [] | map(select((.author_association | IN("OWNER", "MEMBER", "COLLABORATOR")) or .user.type == "Bot"))'
}

thread_handoff "$@"
