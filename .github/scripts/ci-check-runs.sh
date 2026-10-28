#!/usr/bin/env bash
# コミットに付いたチェックのうち、CI のもの(マージを止める理由になるもの)を JSON の配列で出す
#
# 使い方: ci-check-runs.sh <owner/repo> <コミット>
#
# PR の head には、CI(test.yml・evidence-gate.yml・branch-flow.yml・CodeRabbit など)のほかに、PR の上で動く開発の仕組みの
# ジョブ(pr-status.yml の label・notify-needs-human.yml の notify・digest・mark-done・ci-rerun.yml の rerun・ai-review-loop.yml など)の
# チェックも付く。これらは pull_request_target・pull_request_review・pull_request_review_comment で動き、終わった後に
# ai-auto-merge.yml を呼び直さないので、実行中を「チェックが終わっていない」と数えると、マージできる PR が止まる(#367)。
# そのため、こうしたイベントで動いたワークフローのチェックを除く。CI はこれらのイベントでは動かない。
# ワークフローの実行に結び付かないチェック(GitHub Actions 以外のアプリ)と、実行が見つからないチェックは、CI として残す(止める側に倒す)。
# ai-auto-merge.yml 自身のジョブ(auto-merge)は、workflow_dispatch などで動いた回も含めて除く
set -euo pipefail

repo=$1
head=$2

checks=$(gh api --paginate --slurp "repos/$repo/commits/$head/check-runs?per_page=100" | jq '[.[].check_runs[]]')
# 除くワークフローの実行の check suite の id
automation=$(gh api --paginate --slurp "repos/$repo/actions/runs?head_sha=$head&per_page=100" \
  | jq '[.[].workflow_runs[]
      | select(.event | IN("pull_request_target", "pull_request_review", "pull_request_review_comment", "issue_comment", "workflow_run"))
      | .check_suite_id]')
jq -c --argjson automation "$automation" \
  '[.[] | select(.name != "auto-merge" and ((.check_suite.id // null) as $id | $automation | index($id) | not))]' <<<"$checks"
