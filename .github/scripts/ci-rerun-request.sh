#!/usr/bin/env bash
# いまのコミットで CI が動いていない PR を、CI の流し直し(ci-rerun.yml。#228)に見させる
#
# 使い方: ci-rerun-request.sh <owner/repo> <PR の番号> <PR の向き先> <既定のブランチ>
#
# ai-auto-merge.yml が、必須のチェックがそろっていない・CI の pull_request の回が成功していないで見送るときに呼ぶ(#268)。
# 見送っただけでは、Actions が push したコミット(CI が承認待ち・回が無い)は誰も CI を流し直さず、そのまま止まる。
# 流し直すかどうか(承認待ちの回の承認・PR の開き直し・走っている途中なら何もしない)は ci-rerun.sh が決めるので、ここでは頼むだけにする。
# 流し直した CI が緑で終わると、ai-auto-merge.yml が workflow_run で呼び直される。
#
# - 向き先が既定のブランチでない(積んでいる途中・昇格の PR): ci-rerun.sh も見ないので頼まない
# - ci-rerun.yml が無い・止めてある: 流し直しの仕組みが無いリポジトリでは、見送りのログだけにする
# - ci-rerun.yml があるかを読めない(権限・通信の失敗)・呼べない: 失敗を返す(無いと取り違えない)
set -euo pipefail

repo=$1
number=$2
base=$3
default=$4
WORKFLOW=ci-rerun.yml

if [ "$base" != "$default" ]; then
  echo "#$number の向き先が $default ではないので、CI の流し直しは頼まない"
  exit 0
fi

if ! state=$(gh api "repos/$repo/actions/workflows/$WORKFLOW" --jq .state 2>&1); then
  if grep -q 'HTTP 404' <<<"$state"; then
    echo "$WORKFLOW が無いので、CI の流し直しは頼まない(見送りのログだけ)"
    exit 0
  fi
  echo "$WORKFLOW があるかを読めない: $state" >&2
  exit 1
fi
if [ "$state" != active ]; then
  echo "$WORKFLOW が動かない状態($state)なので、CI の流し直しは頼まない(見送りのログだけ)"
  exit 0
fi

gh workflow run "$WORKFLOW" -R "$repo" -f number="$number"
echo "$WORKFLOW に #$number の CI の流し直しを頼んだ"
