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
# 頼むのはコミットごとに 1 回(目印 ai-dev:thread-handoff sha=<コミット>)。頼むコメントは、Slack の通知が引用するので
# ラベルより先に書く。コメントだけ書けて止まった回の後は、コメントを書き直さずにラベルと通知だけを足す。
# スレッドを解決してもワークフローは起きない(pull_request_review_thread は Actions のトリガーに無い)ので、
# 外すのは見回り(ai-review-sweep.yml)。人がほかの理由で付けた needs-human を外さないよう、いちばん新しい
# 頼むコメント(目印 ai-dev:needs-human)がこの理由のもので、ラベルを最後に付けたのが Actions のときだけ外す
set -euo pipefail

MARKER=ai-dev:thread-handoff
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
              isResolved
              path
              first: comments(first: 1) { nodes { url } }
            }
          }
        }
      }
    }' | jq -s '[.[].data.repository.pullRequest.reviewThreads.nodes[]
      | select(.isResolved | not)
      | {url: .first.nodes[0].url, path}]'
}

# PR のコメントのうち、目印を信じてよいもの(書き込み権限のある人と GitHub App)だけを出す。
# public のリポジトリでは誰でもコメントでき、目印を書き込めるため(notify-needs-human.yml と同じ見分け方)
issue_comments() {
  local repo=$1 n=$2
  gh api --paginate --slurp "repos/$repo/issues/$n/comments?per_page=100" \
    | jq 'add // [] | map(select((.author_association | IN("OWNER", "MEMBER", "COLLABORATOR")) or .user.type == "Bot"))'
}

thread_handoff "$@"
