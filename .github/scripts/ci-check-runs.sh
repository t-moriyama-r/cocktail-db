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
#
# 残したチェックのうち、終わると ai-auto-merge.yml を呼び直すもの(workflow_run の workflows: に並べた test.yml・evidence-gate.yml の
# ジョブと、レビューのイベントで呼び直す CodeRabbit)は、実行中ならそのまま出す(見送っても、終わったときにまた呼ばれる)。
# それ以外(CodeQL の既定の設定が動かす dynamic の Analyze・GitHub Actions 以外のアプリなど)は、終わっても呼び直すものが無いので、
# 見送ると次の見回り(ai-review-sweep.yml。2 時間ごと)まで、マージできる PR が残る(#387)。そのため、実行中のチェックが
# これだけのときは、終わるまでここで待ってから出す(待つ上限は CI_CHECK_WAIT_TRIES 回 × CI_CHECK_WAIT_INTERVAL 秒。既定で 10 分)。
# 待っても終わらなければ、実行中のまま出す(止める側に倒す。見回りが拾う)。終わって失敗していれば、これまでどおりマージを止める。
# 呼び直すものが実行中・どれかが失敗しているときは、この回は見送りになるので待たない
set -euo pipefail

repo=$1
head=$2
tries=${CI_CHECK_WAIT_TRIES:-30}
interval=${CI_CHECK_WAIT_INTERVAL:-20}
# 終わると ai-auto-merge.yml を呼び直すワークフロー(ai-auto-merge.yml の workflow_run の workflows: と合わせる)とアプリ
retrigger_paths=${CI_RETRIGGER_PATHS:-.github/workflows/test.yml .github/workflows/evidence-gate.yml}
retrigger_apps=${CI_RETRIGGER_APPS:-coderabbitai}

# チェックを読み(読めなければ失敗にする。コマンド置換の中では set -e が効かないので return で返す)、{checks: 残したチェック, waiting: 待つチェックの名前, stop: この回は見送りになるか} を出す
collect() {
	local checks runs
	checks=$(gh api --paginate --slurp "repos/$repo/commits/$head/check-runs?per_page=100" | jq '[.[].check_runs[]]') || return 1
	runs=$(gh api --paginate --slurp "repos/$repo/actions/runs?head_sha=$head&per_page=100" | jq '[.[].workflow_runs[]]') || return 1
	# 実行 1 件は 13 KB ほどあり、コメント・ラベルのたびに増えるので、--argjson(引数。1 つ 128 KiB が上限)ではなく標準入力で渡す(#776)
	printf '%s\n' "$checks" "$runs" | jq -c -n --arg paths "$retrigger_paths" --arg apps "$retrigger_apps" '
		input as $checks | input as $runs
		# 除くワークフローの実行の check suite の id
		| ([$runs[] | select(.event | IN("pull_request_target", "pull_request_review", "pull_request_review_comment", "issue_comment", "workflow_run"))
			| .check_suite_id]) as $automation
		| ($paths | split(" ") | map(select(. != ""))) as $paths
		| ($apps | split(" ") | map(select(. != ""))) as $apps
		| ([$runs[] | select(.path as $p | $paths | index($p)) | .check_suite_id]) as $retrigger
		| [$checks[] | select(.name != "auto-merge" and ((.check_suite.id // null) as $id | $automation | index($id) | not))] as $kept
		| def pending: .status != "completed";
		  def retrigger: ((.check_suite.id // null) as $id | $retrigger | index($id)) or ((.app.slug // null) as $a | $apps | index($a));
		{checks: $kept,
			waiting: [$kept[] | select(pending and (retrigger | not)) | .name],
			stop: ([$kept[] | select((pending and retrigger) or ((pending | not) and (.conclusion | IN("success", "skipped", "neutral") | not)))]
				| length > 0)}'
}

i=0
while :; do
	result=$(collect)
	waiting=$(jq -r '.waiting | join(",")' <<<"$result")
	if [ -z "$waiting" ] || [ "$(jq .stop <<<"$result")" = true ]; then break; fi
	if [ "$i" -ge "$tries" ]; then
		echo "::warning::終わると呼び直すものが無いチェックが、待っても終わらなかった: $waiting(見回りの ai-review-sweep.yml が呼び直す)" >&2
		break
	fi
	i=$((i + 1))
	echo "終わると呼び直すものが無いチェックの終わりを待つ($i/$tries): $waiting" >&2
	sleep "$interval"
done
jq -c .checks <<<"$result"
