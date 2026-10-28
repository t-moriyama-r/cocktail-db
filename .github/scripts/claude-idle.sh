#!/usr/bin/env bash
# ai-review-loop.yml で、Claude Code の手順が成功で終わった後に、仕事をしたかを確かめ、
# 仕事をしないまま対応中の印(ai-in-actions)が残っていれば片付ける(#240)
#
# 使い方:
#   claude-idle.sh review <owner/repo> <PR の番号> <ran>
#   claude-idle.sh resume <owner/repo> <PR の番号> <ran> <動く前のコミット> <動き始めた時刻(ISO 8601、UTC)>
#
#   <ran> は、Claude Code が実際に動いたか(true / false)。claude-code-action の出力 execution_file が空なら false
#   (ワークフローのファイルの検査で飛ばされた回は、Claude を動かさずに成功で終わり、execution_file を出さない)。
#
#   標準出力に、したことを 1 語で出す(ログは標準エラーに出す)。
#     ok      … 仕事をして、印も外れている。何もしない
#     cleared … 印だけ残っていた(することが無くて外し忘れた・人に頼んだ)ので外した
#     retry   … 仕事をしていないので印を外した。呼び出し側が、既定のブランチで workflow_dispatch を 1 回起こす
#     asked   … 仕事をしていないので印を外し、何が起きたかをコメントして needs-human を付けた(Slack への通知は呼び出し側)
#
# claude-code-action は、Claude を動かさずに終わっても(ワークフローのファイルの検査で飛ばされた・#237)、
# Claude が権限で止められて指摘に返信しないまま終わっても(#209・#244)、手順は成功になる。そのため
# 「途中で止まったら印を外す」手順(failure() のとき)が動かず、ai-in-actions が残って、自動マージも見回りも
# 対応中とみなして 1〜3 時間止まっていた。
#
# review(CodeRabbit・代わりの Claude の指摘への対応):
#   - 動いて、印を外して終わった → ok
#   - 動いて、返信待ちのスレッドが無い・needs-human を付けた・PR が閉じた → 印を外す(cleared)
#   - 動かなかった・動いたのに返信待ちのスレッドが残って印も残った → 印を外し、いまのコミットで起こし直していなければ
#     起こし直す(retry)。もう起こし直していれば、PR に何が起きたかを書いて needs-human を付ける(asked)
#   起こし直しは、いまのコミットごとに 1 回まで(目印 ai-dev:claude-idle-retry sha=<コミット>)。目印のコメントには
#   ai-dev:agent を付けない。付けると ai-review-loop.yml の見送りの判定(最後のレビューの後にエージェントがコメントしたか)が、
#   起こし直した回を「もう対応した」と取り違えて見送る。Actions(github-actions[bot])が書くので、人の返信とは取り違えない
#
# resume(needs-human・needs-approval へのオーナーの返信の続き):
#   起こし直しの workflow_dispatch が無い(オーナーの返信のコメントで動く)ので、仕事をしていなければ、印を外し、
#   もう一度返信してほしいことをコメントして needs-human を付ける(asked)。直して push した回は、次のレビューで
#   review が印を外すので残す(ok)。needs-human を付けて終わった回は、人の番なので印を外す(cleared)
#   仕事をしたかは、コミットが進んだか・動き始めてからエージェントの目印の付いたコメント・返信があるかで見る
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
LABEL=ai-in-actions
RETRY=ai-dev:claude-idle-retry
ASKED=ai-dev:claude-idle
RESUME=ai-dev:claude-idle-resume
USAGE="使い方: claude-idle.sh review <owner/repo> <PR の番号> <ran> | resume <owner/repo> <PR の番号> <ran> <動く前のコミット> <動き始めた時刻>"

claude_idle() {
  local mode=${1:-} repo=${2:-} n=${3:-} ran=${4:-}
  if [ -z "$repo" ] || [ -z "$n" ] || [[ "$ran" != true && "$ran" != false ]]; then
    echo "$USAGE" >&2
    return 2
  fi
  case "$mode" in
    review) review "$repo" "$n" "$ran" ;;
    resume)
      if [ -z "${5:-}" ] || [ -z "${6:-}" ]; then
        echo "$USAGE" >&2
        return 2
      fi
      resume "$repo" "$n" "$ran" "$5" "$6"
      ;;
    *)
      echo "$USAGE" >&2
      return 2
      ;;
  esac
}

review() {
  local repo=$1 n=$2 ran=$3 pr sha unanswered comments
  pr=$(gh pr view "$n" -R "$repo" --json state,headRefOid,labels)
  sha=$(jq -r .headRefOid <<<"$pr")

  if [ "$ran" = true ]; then
    if ! has_label "$pr" "$LABEL"; then
      log "#$n Claude Code が仕事をして、$LABEL も外れている"
      echo ok
      return
    fi
    if [ "$(jq -r .state <<<"$pr")" != OPEN ]; then
      clear "$repo" "$n" "PR が開いていない"
      return
    fi
    if has_label "$pr" needs-human; then
      clear "$repo" "$n" "needs-human を付けて人に頼んでいる"
      return
    fi
    unanswered=$(bash "$HERE/unanswered-review-threads.sh" "$repo" "$n")
    if [ "$unanswered" = 0 ]; then
      clear "$repo" "$n" "返信待ちのスレッドが無い(することが無くて外し忘れた)"
      return
    fi
    log "#$n Claude Code は動いたが、返信待ちのスレッドが $unanswered 件残り、$LABEL も残っている(権限で止められた など)"
  else
    log "#$n Claude Code が動かなかった(ワークフローのファイルの検査で飛ばされた など)"
    if [ "$(jq -r .state <<<"$pr")" != OPEN ]; then
      clear "$repo" "$n" "PR が開いていない"
      return
    fi
  fi

  # 仕事をしていない。印を外してから、起こし直すか人に頼むかを決める
  remove_label "$repo" "$n"
  comments=$(trusted_comments "$repo" "$n")
  if ! has_marker "$comments" "$RETRY sha=$sha"; then
    # 目印は、印を外せた後に書く(外せずに失敗したら、次の回でもう一度起こし直せるように)
    gh pr comment "$n" -R "$repo" --body "<!-- $RETRY sha=$sha -->
Actions の Claude Code が、レビューの指摘に対応しないまま終わった(${RUN_URL:-実行の記録は Actions の画面})。対応中の印(\`$LABEL\`)を外し、既定のブランチのワークフローで 1 回だけ起こし直す。" >/dev/null
    log "#$n ${sha:0:7} で起こし直す"
    echo retry
    return
  fi
  if has_marker "$comments" "$ASKED sha=$sha"; then
    log "#$n ${sha:0:7} ではもう人に頼むコメントを書いてある。needs-human だけ付ける"
  else
    gh pr comment "$n" -R "$repo" --body "<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- $ASKED sha=$sha -->
Actions の Claude Code が、起こし直してもレビューの指摘に対応しないまま終わった。原因を見て、どう進めるかを決めてほしい。

| | A: Actions にもう一度任せる | B: 手元で対応する |
|---|---|---|
| こう選ぶと | このコメントに「指摘に対応して」と返信すると、Actions の Claude Code が返信を受けて、返されていない指摘に対応し直す | 手元のエージェントが指摘に対応して push し、\`needs-human\` を外す |
| その代わり | 原因(権限で止められた など)が残っていると、また止まる | 手元で作業する手間がかかる |
| おすすめ | 原因が一時的なもの(直前にワークフローが変わった など)なら A | 権限で止められていたなら B |

答え方: A なら「指摘に対応して」と返信する。B なら手元のエージェントに頼む

<details><summary>詳しく</summary>

- コミット: ${sha:0:7}
- この回の実行の記録: ${RUN_URL:-(不明)}
- 1 回目の起こし直しは、目印 \`$RETRY sha=${sha:0:7}…\` のコメントのところ
- Claude Code が動かなかった回は、claude-code-action の手順のログに \`Action skipped due to workflow validation error\` が出る。動いたのに止まった回は、ログの \`permission_denials\` に止められた操作が出る

</details>" >/dev/null
  fi
  add_needs_human "$repo" "$n" "$pr"
  echo asked
}

resume() {
  local repo=$1 n=$2 ran=$3 before=$4 since=$5 pr sha worked=false
  pr=$(gh pr view "$n" -R "$repo" --json state,headRefOid,labels)
  sha=$(jq -r .headRefOid <<<"$pr")

  if [ "$ran" = true ]; then
    if [ "$sha" != "$before" ]; then
      worked=true
    else
      local rc=0
      agent_wrote_since "$repo" "$n" "$since" || rc=$?
      case $rc in
        0) worked=true ;;
        1) ;;
        *) log "#$n コメントを読めなかった。何も変えずに失敗で終わる"; return 1 ;;
      esac
    fi
  fi
  if [ "$worked" = true ]; then
    if has_label "$pr" "$LABEL" && has_label "$pr" needs-human; then
      clear "$repo" "$n" "needs-human を付けて人に頼んでいる"
      return
    fi
    # 直して push した回の印は、次のレビューで review が外す
    log "#$n Claude Code がオーナーの返信を受けて仕事をした"
    echo ok
    return
  fi

  if [ "$(jq -r .state <<<"$pr")" != OPEN ]; then
    if has_label "$pr" "$LABEL"; then
      clear "$repo" "$n" "PR が開いていない"
    else
      echo ok
    fi
    return
  fi
  log "#$n Claude Code がオーナーの返信を受けて何もしないまま終わった(動いた: $ran)"
  if has_label "$pr" "$LABEL"; then remove_label "$repo" "$n"; fi
  gh pr comment "$n" -R "$repo" --body "<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- $RESUME sha=$sha -->
返信を受けて Actions の Claude Code が動いたが、何もしないまま終わった。お手数だが、同じ返信をもう一度書いてほしい。

| | A: もう一度返信する | B: 手元で対応する |
|---|---|---|
| こう選ぶと | この PR にもう一度返信すると、Actions の Claude Code が返信どおりに続きをする | 手元のエージェントが返信どおりに直して push し、\`needs-human\` を外す |
| その代わり | 原因(権限で止められた など)が残っていると、また止まる | 手元で作業する手間がかかる |
| おすすめ | まず A。2 回続けて止まったら B | |

<details><summary>詳しく</summary>

- コミット: ${sha:0:7}
- この回の実行の記録: ${RUN_URL:-(不明)}
- Claude Code が動かなかった回は、claude-code-action の手順のログに \`Action skipped due to workflow validation error\` が出る。動いたのに止まった回は、ログの \`permission_denials\` に止められた操作が出る

</details>" >/dev/null
  add_needs_human "$repo" "$n" "$pr"
  echo asked
}

clear() {
  remove_label "$1" "$2"
  log "#$2 $LABEL を外した: $3"
  echo cleared
}

remove_label() {
  gh pr edit "$2" -R "$1" --remove-label "$LABEL" >/dev/null
}

# GITHUB_TOKEN で付けたラベルは通知のワークフローを起こさないので、Slack への通知は呼び出し側のジョブ(actions: write を持つ)が出す
add_needs_human() {
  if has_label "$3" needs-human; then return; fi
  gh pr edit "$2" -R "$1" --add-label needs-human >/dev/null
}

has_label() {
  jq -e --arg l "$2" 'any(.labels[]?; .name == $l)' <<<"$1" >/dev/null
}

has_marker() {
  jq -e --arg m "$2" 'any(.[]; .body // "" | contains($m))' <<<"$1" >/dev/null
}

# 動き始めてから、エージェント(目印 ai-dev:agent)が PR へのコメントかスレッドへの返信を書いたか。
# 終了コード: 0 書いた、1 書いていない、2 コメントを読めなかった(「書いていない」と取り違えない)
agent_wrote_since() {
  local repo=$1 n=$2 since=$3 issue review rc=0
  issue=$(trusted_comments "$repo" "$n") || return 2
  review=$(gh api --paginate --slurp "repos/$repo/pulls/$n/comments?per_page=100" | jq 'add // [] | map(select((.author_association | IN("OWNER", "MEMBER", "COLLABORATOR")) or .user.type == "Bot"))') || return 2
  jq -e -n --argjson c "$issue" --argjson r "$review" --arg since "$since" \
    'any(($c + $r)[]; (.body // "" | contains("ai-dev:agent")) and (.created_at >= $since))' >/dev/null || rc=$?
  if [ "$rc" -eq 0 ] || [ "$rc" -eq 1 ]; then return "$rc"; fi
  return 2
}

# PR のコメントのうち、目印を信じてよいもの(書き込み権限のある人と GitHub App)だけを出す。
# public のリポジトリでは誰でもコメントでき、目印を書き込めるため(thread-handoff.sh と同じ見分け方)
trusted_comments() {
  gh api --paginate --slurp "repos/$1/issues/$2/comments?per_page=100" \
    | jq 'add // [] | map(select((.author_association | IN("OWNER", "MEMBER", "COLLABORATOR")) or .user.type == "Bot"))'
}

log() { echo "$*" >&2; }

claude_idle "$@"
