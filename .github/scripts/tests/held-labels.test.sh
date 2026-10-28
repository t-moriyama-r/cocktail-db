#!/usr/bin/env bash
#
# held-labels.sh のテスト(TC-421-xx)。PR の JSON から、見回り・自動マージが待つ理由のラベルを出すかを確かめる。
# 呼ぶ側ごとの引数は、そのワークフロー・スクリプトが渡しているものと同じにする。GitHub には触らない。
#
# 使い方: .github/scripts/tests/held-labels.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../held-labels.sh"

FAILED=0
PASSED=0

main() {
	# ai-auto-merge.yml は needs-approval・ai-in-actions を足して呼ぶ
	check TC-421-01 'ai-auto-merge.yml: needs-local の付いた PR はマージしない' '[{"name":"needs-local"}]' 'needs-local' needs-approval ai-in-actions
	check TC-421-02 'ai-auto-merge.yml: これまでの needs-human・needs-approval・ai-in-actions も待つ' \
		'[{"name":"needs-human"},{"name":"needs-approval"},{"name":"ai-in-actions"}]' 'needs-human,needs-approval,ai-in-actions' needs-approval ai-in-actions
	check TC-421-03 'ai-auto-merge.yml: 関係の無いラベル(状態・no-auto-merge・task)だけなら待たない' \
		'[{"name":"状態:マージ待ち"},{"name":"no-auto-merge"},{"name":"task"}]' '' needs-approval ai-in-actions
	check TC-421-04 '引数なし(ai-conflict-resolve.yml の after): needs-local・needs-human・needs-decision を待つ' \
		'[{"name":"needs-decision"},{"name":"needs-local"},{"name":"needs-human"}]' 'needs-decision,needs-local,needs-human'
	check TC-421-05 '引数なしでは needs-approval・ai-in-actions を待たない' '[{"name":"needs-approval"},{"name":"ai-in-actions"}]' ''
	check TC-421-06 'ラベル名の配列(draft-candidates.sh の事実の JSON)でも読む' '["needs-local","no-auto-merge"]' 'needs-local,no-auto-merge' ai-in-actions no-auto-merge
	check TC-421-07 'ラベルが無い・labels が無い' 'null' ''
	check TC-421-08 '似た名前(needs-local-x・needs-human を含む名前)は数えない' '[{"name":"needs-local-x"},{"name":"not-needs-human"}]' ''

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

check() {
	local id=$1 title=$2 labels=$3 expected=$4 actual
	shift 4
	actual=$(jq -nc --argjson l "$labels" '{number: 1, labels: $l}' | bash "$SCRIPT" "$@")
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$actual'"
	fi
}

main "$@"
