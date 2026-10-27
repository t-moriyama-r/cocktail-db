#!/usr/bin/env bash
# 見回りのワークフローを、前の見回りから決まった時間がたっていれば workflow_dispatch で起こす(patrol-kick.yml から呼ぶ)
#
# 使い方:
#   patrol-kick.sh <owner/repo>
#
# GitHub の schedule は、混んでいると遅れたり落とされたりする(2026-10-26 に ai-review-sweep.yml が 5 時間以上動かなかった。#269)。
# そのため、よく動くワークフロー(CI)の終わりにこのスクリプトを呼び、見回りごとに「前の見回り」の時刻を見て、
# 間隔(下の PATROLS の分)を過ぎていれば起こす。過ぎていなければ何もせず、そのことをログに出す。
# 見回りはどれも何度動いても同じ結果になる作り(同じコミットで同じことを二度しない)なので、schedule の回と重なっても害は無い。
#
# 「前の見回り」は、そのワークフローの、PATROLS に書いたイベントで起きた回のうち、いちばん新しいものの作られた時刻
# (待っている・動いている回も数える。起こした直後に重ねて起こさない)。PR 1 件だけを見る回(pull_request_target など)は数えない。
# workflow_dispatch は番号付き(PR 1 件だけ)の回も数える(実行の一覧からは入力が分からないため)。
#
# 一覧を読めなかったときは起こさない(読めないことを「前の見回りが無い」と同じに扱わない)。ほかの見回りは続けて見て、最後に失敗で終える。
set -euo pipefail

# ワークフローのファイル 間隔(分) 見回りとして数えるイベント(| 区切り)
PATROLS=${PATROLS:-"ai-review-sweep.yml 60 schedule|workflow_dispatch
ci-rerun.yml 60 schedule|workflow_dispatch
ai-conflict-resolve.yml 60 schedule|workflow_dispatch|push
ai-draft-sweep.yml 1440 schedule|workflow_dispatch"}

patrol_kick() {
  local repo=$1 now failed=0 wf interval events
  now=$(date -u +%s)
  while read -r wf interval events; do
    [ -n "$wf" ] || continue
    kick_one "$repo" "$now" "$wf" "$interval" "$events" || failed=1
  done <<<"$PATROLS"
  return "$failed"
}

kick_one() {
  local repo=$1 now=$2 wf=$3 interval=$4 events=$5 latest="" e at
  for e in ${events//|/ }; do
    # || で呼ばれると set -e が効かないので、読めなかったら起こさずに戻す
    at=$(gh api "repos/$repo/actions/workflows/$wf/runs?event=$e&per_page=1" --jq '.workflow_runs[0].created_at // ""') || {
      echo "::warning::$wf の実行の一覧($e)を読めなかった。起こさない"
      return 1
    }
    # ISO 8601(UTC・Z 付き)は文字列の大小で比べられる
    if [[ "$at" > "$latest" ]]; then latest=$at; fi
  done

  if [ -n "$latest" ]; then
    local elapsed
    elapsed=$(((now - $(date -u -d "$latest" +%s)) / 60))
    if [ "$elapsed" -lt "$interval" ]; then
      echo "$wf 見送り: 前の見回り($latest)から $elapsed 分($interval 分たつまで起こさない)"
      return 0
    fi
    echo "$wf 前の見回り($latest)から $elapsed 分たった。起こす"
  else
    echo "$wf 前の見回りが無い。起こす"
  fi
  gh workflow run "$wf" -R "$repo" || {
    echo "::warning::$wf を起こせなかった"
    return 1
  }
}

patrol_kick "$@"
