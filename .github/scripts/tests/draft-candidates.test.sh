#!/usr/bin/env bash
#
# draft-candidates.sh decide のテスト(TC-236-xx)。事実の JSON から、引き継がせるか・なぜ見送るかを確かめる。
# GitHub には触らない。
#
# 使い方: .github/scripts/tests/draft-candidates.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../draft-candidates.sh"

FAILED=0
PASSED=0

# 実装したエージェントが落ちて 4 時間たった、引き継ぐ draft。各ケースはここから 1 つだけ変える
BASE='{"state":"OPEN","isDraft":true,"isCrossRepository":false,"author":"owner","owner":"owner","baseRefName":"develop","defaultBranch":"develop","labels":[],"checksPending":false,"idleMinutes":240,"handledHead":false,"round":1}'

main() {
	check TC-236-01 '落ちた draft を引き継ぐ' '.' 'pick'
	check TC-236-02 'CI が赤でも引き継ぐ(状態のラベルは見ない)' '.labels = ["状態:CI失敗"]' 'pick'
	check TC-236-03 '閉じた PR' '.state = "MERGED"' 'skip: 開いていない'
	check TC-236-04 'ready の PR は、実装したエージェントが役目を果たしている' '.isDraft = false' 'skip: draft でない'
	check TC-236-05 'フォークから' '.isCrossRepository = true' 'skip: フォークから'
	check TC-236-06 'オーナー以外の PR' '.author = "someone"' 'skip: オーナーの PR でない'
	check TC-236-07 '積んだ PR(base が develop でない)' '.baseRefName = "issue-1-base"' 'skip: 向き先が develop でない(積んだ PR は、土台が入って付け替わってから)'
	check TC-236-08 'needs-human' '.labels = ["needs-human"]' 'skip: ラベル needs-human'
	check TC-236-09 'needs-decision' '.labels = ["needs-decision"]' 'skip: ラベル needs-decision'
	check TC-236-10 'ai-in-actions(Actions が対応している途中)' '.labels = ["ai-in-actions"]' 'skip: ラベル ai-in-actions'
	check TC-236-11 'no-auto-merge' '.labels = ["no-auto-merge"]' 'skip: ラベル no-auto-merge'
	check TC-236-12 'CI が動いている途中' '.checksPending = true' 'skip: CI が動いている途中'
	check TC-236-13 '境界: 最後の動きから 179 分は待つ' '.idleMinutes = 179' 'skip: 最後の動きから 179 分(180 分たつまで待つ)'
	check TC-236-14 '境界: 最後の動きから 180 分で引き継ぐ' '.idleMinutes = 180' 'pick'
	check TC-236-15 '同じコミットで引き継ぎ済み' '.handledHead = true' 'skip: このコミットは引き継ぎ済み'
	check TC-236-16 '境界: 2 回目の引き継ぎはする' '.round = 2' 'pick'
	check TC-236-17 '境界: 3 回目の引き継ぎはしない' '.round = 3' 'skip: 引き継ぎが 2 回に達した'
	check TC-236-18 '人の判断待ちは、CI の途中より先に理由に出る' '.labels = ["needs-human"] | .checksPending = true' 'skip: ラベル needs-human'

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

main "$@"
