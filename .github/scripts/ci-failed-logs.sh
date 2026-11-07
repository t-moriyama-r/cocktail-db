#!/usr/bin/env bash
# コミットに付いた CI の回のうち、失敗した回の、失敗したジョブのログ(gh run view <回> --log-failed)を 1 つのファイルに書き出す
#
# 使い方: ci-failed-logs.sh <owner/repo> <コミット> <書き出すファイル>
#
# ai-draft-sweep.yml の Claude Code が CI の失敗の原因を読むためのもの。Claude Code に `gh run view` を渡すと回の番号を選べて、
# ほかの PR の回のログも読めてしまうので、動かす前にこのスクリプトでその PR の head の回の分だけを書き出し、それを読ませる(RC-02。#591)。
#
# - 回は、そのコミット(head_sha)で動いた Actions の実行。ワークフローごとにいちばん新しい回だけを見る(流し直す前の古い失敗を読ませない)
# - PR の上で動く開発の仕組みのワークフロー(pull_request_target・pull_request_review などで動くもの)は CI ではないので見ない
#   (除くイベントは ci-check-runs.sh と同じ)
# - 失敗とみなす conclusion は failure・timed_out・startup_failure
# - 1 つの回のログは、末尾の MAX_LINES 行だけにする(長すぎると読み切れない。失敗の原因はたいてい末尾にある)
# - 回の一覧を読めないときは失敗で終わる(「失敗した回は無い」と取り違えない)。1 つの回のログを読めないときは、その旨を書いて続ける
set -euo pipefail

MAX_LINES=${MAX_LINES:-3000}

repo=$1
head=$2
out=$3

runs=$(gh api --paginate --slurp "repos/$repo/actions/runs?head_sha=$head&per_page=100")
failed=$(jq -c '[.[].workflow_runs[]
    | select(.event | IN("pull_request_target", "pull_request_review", "pull_request_review_comment", "issue_comment", "workflow_run") | not)]
  | group_by(.workflow_id)
  | map(max_by(.created_at))
  | map(select(.conclusion | IN("failure", "timed_out", "startup_failure")))
  | sort_by(.name)
  | map({id, name, conclusion, url: .html_url})' <<<"$runs")

{
  echo "# コミット ${head:0:7} の CI で失敗した回のログ"
  echo
  if jq -e 'length == 0' <<<"$failed" >/dev/null; then
    echo "失敗した CI の回は無い(この時点で、終わっていない回は含めていない)"
  fi
} >"$out"

while IFS=$'\t' read -r id name conclusion url; do
  [ -n "$id" ] || continue
  {
    echo "## $name(回 $id、$conclusion)"
    echo
    echo "$url"
    echo
    echo '```text'
  } >>"$out"
  if log=$(gh run view "$id" -R "$repo" --log-failed 2>&1); then
    lines=$(printf '%s\n' "$log" | wc -l)
    if [ "$lines" -gt "$MAX_LINES" ]; then
      echo "(長いので末尾の $MAX_LINES 行だけ。全部で $lines 行)" >>"$out"
    fi
    printf '%s\n' "$log" | tail -n "$MAX_LINES" >>"$out"
  else
    echo "(この回のログを読めなかった: $(printf '%s' "$log" | head -n 1))" >>"$out"
  fi
  {
    echo '```'
    echo
  } >>"$out"
done < <(jq -r '.[] | [.id, .name, .conclusion, .url] | @tsv' <<<"$failed")
