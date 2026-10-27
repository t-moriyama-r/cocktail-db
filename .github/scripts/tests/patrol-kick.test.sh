#!/usr/bin/env bash
#
# patrol-kick.sh のテスト(TC-269-01〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/patrol-kick.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../patrol-kick.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

ago() { date -u -d "-$1 minutes" +%Y-%m-%dT%H:%M:%SZ; }

main() {
	setup_fake_gh

	reset
	ran ai-review-sweep.yml schedule "$(ago 61)"
	run
	expect TC-269-01 '前の見回りから 1 時間を過ぎていれば起こす' "$(dispatched)" 'ai-review-sweep.yml -R o/r'

	reset
	ran ai-review-sweep.yml schedule "$(ago 59)"
	run
	expect TC-269-02 '前の見回りから 1 時間たっていなければ起こさず、そのことをログに出す' \
		"$(dispatched)|$(grep -c 'ai-review-sweep.yml 見送り: 前の見回り.*から 59 分(60 分たつまで起こさない)' "$FAKE/out")" '|1'

	reset
	ran ai-review-sweep.yml schedule "$(ago 60)"
	run
	expect TC-269-03 'ちょうど 1 時間たっていれば起こす(境界)' "$(dispatched)" 'ai-review-sweep.yml -R o/r'

	reset
	ran ai-review-sweep.yml schedule "$(ago 180)"
	ran ai-review-sweep.yml workflow_dispatch "$(ago 10)"
	run
	expect TC-269-04 'schedule と workflow_dispatch のうち新しい回から測る(起こした回・手で動かした回も見回りに数える)' "$(dispatched)" ''

	reset
	rm -f "$FAKE/runs/ai-review-sweep.yml/schedule"
	run
	expect TC-269-05 '前の見回りが一度も無ければ起こす' "$(dispatched)|$(grep -c 'ai-review-sweep.yml 前の見回りが無い' "$FAKE/out")" 'ai-review-sweep.yml -R o/r|1'

	reset
	ran ci-rerun.yml schedule "$(ago 120)"
	ran ci-rerun.yml pull_request_target "$(ago 1)"
	run
	expect TC-269-06 'PR 1 件だけを見る回(pull_request_target)は見回りに数えない' "$(dispatched)" 'ci-rerun.yml -R o/r'

	reset
	ran ai-conflict-resolve.yml schedule "$(ago 120)"
	ran ai-conflict-resolve.yml push "$(ago 5)"
	run
	expect TC-269-07 'develop への push で動いたコンフリクトの見回りは、見回りに数える' "$(dispatched)" ''

	reset
	ran ai-draft-sweep.yml schedule "$(ago $((23 * 60)))"
	run
	expect TC-269-08 'draft の見回りは、前の回から 24 時間たっていなければ起こさない' "$(dispatched)" ''

	reset
	ran ai-draft-sweep.yml schedule "$(ago $((25 * 60)))"
	run
	expect TC-269-09 'draft の見回りは、前の回から 24 時間を過ぎていれば起こす' "$(dispatched)" 'ai-draft-sweep.yml -R o/r'

	reset
	ran ai-review-sweep.yml schedule "$(ago 120)"
	ran ci-rerun.yml schedule "$(ago 120)"
	touch "$FAKE/list-fails-ai-review-sweep.yml"
	run
	expect TC-269-10 '一覧を読めなかった見回りは起こさず、ほかの見回りは続けて見て、失敗で終える' \
		"$(dispatched)|$(cat "$FAKE/status")" 'ci-rerun.yml -R o/r|1'

	reset
	ran ai-review-sweep.yml schedule "$(ago 120)"
	ran ci-rerun.yml schedule "$(ago 120)"
	touch "$FAKE/dispatch-fails-ai-review-sweep.yml"
	run
	expect TC-269-11 '起こせなかったら、ほかの見回りは続けて起こし、失敗で終える' \
		"$(dispatched)|$(cat "$FAKE/status")" 'ci-rerun.yml -R o/r|1'

	reset
	ran ci-rerun.yml schedule "$(ago 120)"
	ran ci-rerun.yml workflow_dispatch "$(ago 5)" 'CI の流し直し #12'
	run
	expect TC-269-16 'PR の番号を付けて起こした回(PR 1 件だけを見る)は見回りに数えない' "$(dispatched)" 'ci-rerun.yml -R o/r'

	reset
	ran ai-conflict-resolve.yml schedule "$(ago 120)"
	ran ai-conflict-resolve.yml workflow_dispatch "$(ago 10)"
	ran_more ai-conflict-resolve.yml workflow_dispatch "$(ago 1)" 'コンフリクトを解消する #3'
	run
	expect TC-269-17 '番号付きの回が新しくても、その前の番号の無い回から測る' "$(dispatched)" ''

	reset
	run
	expect TC-269-12 'どの見回りも間隔の内なら、何も起こさずに成功で終える' "$(dispatched)|$(cat "$FAKE/status")" '|0'

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

setup_fake_gh() {
	mkdir -p "$FAKE/bin"
	cat >"$FAKE/bin/gh" <<'SH'
#!/usr/bin/env bash
echo "gh $*" >>"$FAKE/calls"
case "$1 $2" in
	"api repos/o/r/actions/workflows/"*)
		# repos/o/r/actions/workflows/<wf>/runs?event=<event>&per_page=<n> --jq <filter>
		path=${2#repos/o/r/actions/workflows/}
		wf=${path%%/*}
		event=${path#*event=}
		event=${event%%&*}
		[ "$3" = --jq ] || { echo "unexpected: gh $*" >&2; exit 2; }
		if [ -f "$FAKE/list-fails-$wf" ]; then echo "HTTP 502: Bad Gateway" >&2; exit 1; fi
		# 一覧は新しい回から並ぶ
		cat "$FAKE/runs/$wf/$event" 2>/dev/null |
			jq -s '{workflow_runs: (sort_by(.created_at) | reverse)}' | jq -r "$4"
		;;
	"workflow run")
		if [ -f "$FAKE/dispatch-fails-$3" ]; then echo "HTTP 500: dispatch failed" >&2; exit 1; fi
		shift 2; echo "$*" >>"$FAKE/dispatched" ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

# どの見回りも 5 分前に schedule で動いた(間隔の内)状態にする。テストごとに、見たい見回りだけを書き換える
reset() {
	rm -rf "$FAKE/runs" "$FAKE"/dispatched "$FAKE"/list-fails-* "$FAKE"/dispatch-fails-*
	: >"$FAKE/calls"
	local wf
	for wf in ai-review-sweep.yml ci-rerun.yml ai-conflict-resolve.yml ai-draft-sweep.yml; do
		ran "$wf" schedule "$(ago 5)"
	done
}

# ワークフロー・イベント・その回が作られた時刻・その回の名前(省くと番号の無い名前)。そのイベントの回をこの 1 件にする
ran() {
	mkdir -p "$FAKE/runs/$1"
	: >"$FAKE/runs/$1/$2"
	ran_more "$@"
}

# ran と同じ。そのイベントの回に 1 件足す
ran_more() {
	mkdir -p "$FAKE/runs/$1"
	jq -nc --arg at "$3" --arg title "${4:-$1}" '{created_at: $at, display_title: $title}' >>"$FAKE/runs/$1/$2"
}

dispatched() { cat "$FAKE/dispatched" 2>/dev/null; }

run() {
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r >"$FAKE/out" 2>&1
	echo $? >"$FAKE/status"
}

expect() {
	local id=$1 name=$2 actual=$3 expected=$4
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id: $name"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id: $name"
		echo "     expected: $expected"
		echo "     actual:   $actual"
		sed 's/^/     | /' "$FAKE/out"
	fi
}

main
