#!/usr/bin/env bash
#
# ci-check-runs.sh のテスト(TC-367-01〜)。gh を、決まった JSON を返す偽物に差し替えて流す。GitHub には触らない。
# ai-auto-merge.yml と同じく、出た配列から「終わっていない」「失敗している」チェックの名前を出して確かめる。
#
# 使い方: .github/scripts/tests/ci-check-runs.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../ci-check-runs.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

main() {
	setup_fake_gh
	# check suite の id: 1 = test.yml(pull_request)、2 = pr-status.yml(pull_request_target)、3 = notify-needs-human.yml(pull_request_target)、
	# 4 = ai-review-loop.yml(pull_request_review)、5 = ai-review-loop.yml(pull_request_review_comment)、6 = evidence-gate.yml(pull_request)、
	# 7 = ci-rerun.yml(pull_request_target)、8 = test.yml(workflow_dispatch)、9 = CodeRabbit(Actions の実行なし)
	RUNS="[$(wf test.yml pull_request 1),$(wf pr-status.yml pull_request_target 2),$(wf notify-needs-human.yml pull_request_target 3),\
		$(wf ai-review-loop.yml pull_request_review 4),$(wf ai-review-loop.yml pull_request_review_comment 5),\
		$(wf evidence-gate.yml pull_request 6),$(wf ci-rerun.yml pull_request_target 7),$(wf test.yml workflow_dispatch 8)]"

	check TC-367-01 'label だけが実行中なら、それを理由に見送らない(#353)' \
		"[$(run backend 1 completed success),$(run frontend 1 completed success),$(run label 2 in_progress null)]" \
		'pending=|failed=|names=backend,frontend'
	check TC-367-02 'notify・digest・mark-done・rerun・review・resume が実行中・失敗でも数えない' \
		"[$(run backend 1 completed success),$(run notify 3 queued null),$(run digest 3 in_progress null),$(run mark-done 3 completed failure),\
$(run rerun 7 in_progress null),$(run review 4 in_progress null),$(run resume 5 completed failure)]" \
		'pending=|failed=|names=backend'
	check TC-367-03 'CI のジョブが終わっていなければ、これまでどおり出す' \
		"[$(run backend 1 in_progress null),$(run e2e 1 queued null),$(run label 2 completed success)]" \
		'pending=backend,e2e|failed=backend,e2e|names=backend,e2e'
	check TC-367-04 'CI のジョブが失敗していれば、これまでどおり出す' \
		"[$(run backend 1 completed success),$(run backend-lint 1 completed failure),$(run label 2 in_progress null)]" \
		'pending=|failed=backend-lint|names=backend,backend-lint'
	check TC-367-05 'evidence-gate(pull_request)は CI として残す' \
		"[$(run evidence-gate 6 completed failure),$(run label 2 in_progress null)]" \
		'pending=|failed=evidence-gate|names=evidence-gate'
	check TC-367-06 'workflow_dispatch で流した CI は CI として残す' \
		"[$(run frontend 8 completed failure)]" \
		'pending=|failed=frontend|names=frontend'
	check TC-367-07 'Actions の実行に結び付かないチェック(CodeRabbit)は残す' \
		"[$(run CodeRabbit 9 in_progress null)]" \
		'pending=CodeRabbit|failed=CodeRabbit|names=CodeRabbit'
	check TC-367-08 '自分のジョブ(auto-merge)は、どのイベントの回でも除く' \
		"[$(run auto-merge 8 in_progress null),$(run backend 1 completed success)]" \
		'pending=|failed=|names=backend'
	check TC-367-09 '開発の仕組みのジョブしか無ければ、空の配列になる(「チェックが 1 つも無い」で見送る)' \
		"[$(run label 2 completed success),$(run notify 3 completed skipped)]" \
		'pending=|failed=|names='
	check_fails TC-367-10 'チェックを読めないときは失敗にする(「CI のチェックなし」と取り違えない)' check-runs
	check_fails TC-367-11 'ワークフローの実行を読めないときは失敗にする(開発の仕組みのジョブを CI と取り違えない)' runs

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# gh api(check-runs / actions/runs)に、$FAKE に置いた JSON を返す。--slurp ならページの配列にそろえる。
# $FAKE/fail-<check-runs|runs> があれば、その API を失敗させる
setup_fake_gh() {
	cat >"$FAKE/gh" <<-'EOF'
		#!/usr/bin/env bash
		slurp=false path=
		while [ $# -gt 0 ]; do
		  case "$1" in
		    --slurp) slurp=true ;;
		    api | --paginate) ;;
		    *) path=$1 ;;
		  esac
		  shift
		done
		case "$path" in
		  */check-runs*) file=check-runs ;;
		  */actions/runs*) file=runs ;;
		  *) echo "unexpected gh $path" >&2; exit 1 ;;
		esac
		if [ -e "$FAKE_DIR/fail-$file" ]; then echo "HTTP 502" >&2; exit 1; fi
		if [ "$slurp" = true ]; then echo "[$(cat "$FAKE_DIR/$file.json")]"; else cat "$FAKE_DIR/$file.json"; fi
	EOF
	chmod +x "$FAKE/gh"
}

run() { jq -nc --arg n "$1" --argjson id "$2" --arg s "$3" --argjson c "$(conclusion "$4")" '{name: $n, status: $s, conclusion: $c, check_suite: {id: $id}}'; }
wf() { jq -nc --arg p ".github/workflows/$1" --arg e "$2" --argjson id "$3" '{path: $p, event: $e, check_suite_id: $id}'; }
conclusion() { if [ "$1" = null ]; then echo null; else jq -nc --arg c "$1" '$c'; fi; }

prepare() {
	rm -f "$FAKE"/fail-*
	jq -n --argjson r "$1" '{check_runs: $r}' >"$FAKE/check-runs.json"
	jq -n --argjson r "$RUNS" '{workflow_runs: $r}' >"$FAKE/runs.json"
}

# ai-auto-merge.yml の判定と同じ jq で、終わっていない・失敗しているチェックと、残ったチェックの名前を出す
check() {
	local id=$1 title=$2 checks=$3 expected=$4 out actual
	prepare "$checks"
	if out=$(PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" bash "$SCRIPT" o/r abc1234 2>/dev/null); then
		actual="pending=$(jq -r '[.[] | select(.status != "completed") | .name] | join(",")' <<<"$out")"
		actual+="|failed=$(jq -r '[.[] | select(.conclusion | IN("success","skipped","neutral") | not) | .name] | join(",")' <<<"$out")"
		actual+="|names=$(jq -r 'map(.name) | join(",")' <<<"$out")"
	else
		actual="exit $?"
	fi
	result "$id" "$title" "$expected" "$actual"
}

check_fails() {
	local id=$1 title=$2 api=$3 actual
	prepare "[$(run backend 1 completed success)]"
	touch "$FAKE/fail-$api"
	if PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" bash "$SCRIPT" o/r abc1234 >/dev/null 2>&1; then actual=ok; else actual=failed; fi
	result "$id" "$title" failed "$actual"
}

result() {
	if [ "$4" = "$3" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $1 $2"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $1 $2: expected '$3', got '$4'"
	fi
}

main
