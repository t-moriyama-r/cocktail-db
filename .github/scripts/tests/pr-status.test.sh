#!/usr/bin/env bash
#
# pr-status.sh decide のテスト(TC-216-xx)。事実の JSON から、どの状態のラベルになるかを確かめる。
# GitHub には触らない。
#
# 使い方: .github/scripts/tests/pr-status.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../pr-status.sh"

FAILED=0
PASSED=0

# レビューが済み、指摘も CI の失敗も無い、マージを待つだけの PR。各ケースはここから 1 つだけ変える
BASE='{"state":"OPEN","isDraft":false,"mergeable":"MERGEABLE","labels":[],"failedChecks":0,"unansweredThreads":0,"headReviewed":true}'

main() {
	check TC-216-01 '閉じた PR はラベルなし' '.state = "MERGED"' ''
	check TC-216-02 '下書き' '.isDraft = true' '状態:作業中'
	check TC-216-03 '下書きは、ほかの条件より先' '.isDraft = true | .labels = ["needs-human"] | .failedChecks = 1' '状態:作業中'
	check TC-216-04 'needs-human' '.labels = ["needs-human"]' '状態:人の判断待ち'
	check TC-216-05 'needs-decision' '.labels = ["task", "needs-decision"]' '状態:人の判断待ち'
	check TC-216-06 'needs-approval' '.labels = ["needs-approval"]' '状態:人のマージ待ち'
	check TC-216-07 '人の判断待ちは、人のマージ待ちより先' '.labels = ["needs-approval", "needs-human"]' '状態:人の判断待ち'
	check TC-216-08 'コンフリクト' '.mergeable = "CONFLICTING"' '状態:コンフリクト'
	check TC-216-09 'mergeable が UNKNOWN ならコンフリクトとみなさない' '.mergeable = "UNKNOWN"' '状態:マージ待ち'
	check TC-216-10 'CI の失敗' '.failedChecks = 2' '状態:CI失敗'
	check TC-216-11 'コンフリクトは CI の失敗より先' '.mergeable = "CONFLICTING" | .failedChecks = 1' '状態:コンフリクト'
	check TC-216-12 'Actions が対応している' '.labels = ["ai-in-actions"]' '状態:レビュー対応中'
	check TC-216-13 '返信待ちの指摘がある' '.unansweredThreads = 1' '状態:レビュー対応中'
	check TC-216-14 'レビュー対応中は、いまのコミットが未レビューでも先' '.unansweredThreads = 1 | .headReviewed = false' '状態:レビュー対応中'
	check TC-216-15 'いまのコミットがまだレビューされていない' '.headReviewed = false' '状態:レビュー待ち'
	check TC-216-16 'それ以外はマージ待ち' '.' '状態:マージ待ち'
	check TC-216-17 '関係の無いラベルは見ない' '.labels = ["task", "area:infra", "no-auto-merge", "状態:レビュー待ち"]' '状態:マージ待ち'

	# 昇格の PR(向き先が staging・release)。指摘が残ってもマージを止めず、Actions の Claude Code の対応も待たない
	check TC-332-01 'develop 向けで返信待ちの指摘がある(従来どおり)' '.baseRefName = "develop" | .unansweredThreads = 2' '状態:レビュー対応中'
	check TC-332-02 'staging 向けは、返信待ちの指摘があっても人のマージ待ち' '.baseRefName = "staging" | .unansweredThreads = 2' '状態:人のマージ待ち'
	check TC-332-03 'release 向けは、返信待ちの指摘があっても人のマージ待ち' '.baseRefName = "release" | .unansweredThreads = 1' '状態:人のマージ待ち'
	check TC-332-04 'staging 向けで ai-in-actions が残っていても、レビュー対応中にしない' '.baseRefName = "staging" | .labels = ["ai-in-actions"]' '状態:人のマージ待ち'
	check TC-332-05 'staging 向けで、いまのコミットが未レビューならレビュー待ち' '.baseRefName = "staging" | .headReviewed = false | .unansweredThreads = 1' '状態:レビュー待ち'
	check TC-332-06 'staging 向けでも CI の失敗が先' '.baseRefName = "staging" | .failedChecks = 1' '状態:CI失敗'
	check TC-332-07 'staging 向けでもコンフリクトが先' '.baseRefName = "staging" | .mergeable = "CONFLICTING"' '状態:コンフリクト'
	check TC-332-08 'staging 向けで needs-human は人の判断待ち' '.baseRefName = "staging" | .labels = ["needs-human"]' '状態:人の判断待ち'
	check TC-332-09 'staging 向けで needs-approval は人のマージ待ち' '.baseRefName = "staging" | .labels = ["needs-approval"] | .headReviewed = false' '状態:人のマージ待ち'
	check TC-332-10 'staging 向けの下書きは作業中' '.baseRefName = "staging" | .isDraft = true' '状態:作業中'
	check TC-332-11 'develop・main など、昇格でない向き先は従来どおり' '.baseRefName = "issue-1-base" | .unansweredThreads = 1' '状態:レビュー対応中'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

check() {
	local id=$1 title=$2 change=$3 expected=$4 actual
	actual=$(jq -c "$change" <<<"$BASE" | bash "$SCRIPT" decide)
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$actual'"
	fi
}

main
