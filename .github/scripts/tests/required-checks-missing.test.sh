#!/usr/bin/env bash
#
# required-checks-missing.sh のテスト(TC-360-01〜)。gh を、決まった JSON を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/required-checks-missing.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../required-checks-missing.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

REQUIRED='["backend","frontend","backend-lint"]'

main() {
	setup_fake_gh

	check TC-360-01 '必須のチェックがすべて成功している' "$REQUIRED" \
		"[$(run backend completed success),$(run frontend completed success),$(run backend-lint completed success),$(run label completed success)]" \
		'[]' ''
	check TC-360-02 '必須のチェックが 1 つ付いていない' "$REQUIRED" \
		"[$(run frontend completed success),$(run backend-lint completed success)]" \
		'[]' 'backend'
	check TC-360-03 'CI が承認待ちで、必須のチェックが 1 つも付いていない(#348)' "$REQUIRED" \
		"[$(run auto-merge completed success),$(run label completed success)]" \
		"[$(wf test action_required),$(wf 'PR の状態のラベル' success)]" \
		'backend,frontend,backend-lint(承認待ちの実行: test)'
	check TC-360-04 '承認待ちの実行が同じ名前で複数あっても 1 つだけ書く' "$REQUIRED" \
		"[$(run frontend completed success),$(run backend-lint completed success)]" \
		"[$(wf test action_required),$(wf test action_required)]" \
		'backend(承認待ちの実行: test)'
	check TC-360-05 'skipped・neutral は成功として数える' "$REQUIRED" \
		"[$(run backend completed skipped),$(run frontend completed neutral),$(run backend-lint completed success)]" \
		'[]' ''
	check TC-360-06 '失敗した必須のチェックは足りないものとして出す' "$REQUIRED" \
		"[$(run backend completed failure),$(run frontend completed success),$(run backend-lint completed success)]" \
		'[]' 'backend'
	check TC-360-07 '終わっていない必須のチェックは足りないものとして出す' "$REQUIRED" \
		"[$(run backend in_progress null),$(run frontend completed success),$(run backend-lint completed success)]" \
		'[]' 'backend'
	check TC-360-08 '同じ名前で流し直して成功したものがあれば足りている' "$REQUIRED" \
		"[$(run backend completed failure),$(run backend completed success),$(run frontend completed success),$(run backend-lint completed success)]" \
		'[]' ''
	check TC-360-09 '必須のチェックが無いブランチ(保護なし)' 'null' \
		"[$(run label completed success)]" \
		"[$(wf test action_required)]" ''
	check TC-360-10 '保護されているのに中身が読めないときは失敗にする' 'unreadable' \
		"[$(run label completed success)]" '[]' 'exit 1'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# gh api(branches / check-runs / actions/runs)に、$FAKE に置いた JSON を返す。--jq があれば当て、--slurp ならページの配列にそろえる
setup_fake_gh() {
	cat >"$FAKE/gh" <<-'EOF'
		#!/usr/bin/env bash
		filter=. slurp=false path=
		while [ $# -gt 0 ]; do
		  case "$1" in
		    --jq) filter=$2; shift ;;
		    --slurp) slurp=true ;;
		    api | --paginate) ;;
		    *) path=$1 ;;
		  esac
		  shift
		done
		case "$path" in
		  */branches/*) file=branch.json ;;
		  */check-runs*) file=check-runs.json ;;
		  */actions/runs*) file=runs.json ;;
		  *) echo "unexpected gh $path" >&2; exit 1 ;;
		esac
		if [ "$slurp" = true ]; then echo "[$(cat "$FAKE_DIR/$file")]"; else jq -r "$filter" "$FAKE_DIR/$file"; fi
	EOF
	chmod +x "$FAKE/gh"
}

run() { jq -nc --arg n "$1" --arg s "$2" --argjson c "$(conclusion "$3")" '{name: $n, status: $s, conclusion: $c}'; }
wf() { jq -nc --arg n "$1" --arg c "$2" '{name: $n, status: "completed", conclusion: $c}'; }
conclusion() { if [ "$1" = null ]; then echo null; else jq -nc --arg c "$1" '$c'; fi; }

check() {
	local id=$1 title=$2 required=$3 checks=$4 runs=$5 expected=$6 actual
	if [ "$required" = null ]; then
		echo '{"protected": false}' >"$FAKE/branch.json"
	elif [ "$required" = unreadable ]; then
		echo '{"protected": true}' >"$FAKE/branch.json"
	else
		jq -n --argjson c "$required" '{protection: {required_status_checks: {contexts: $c}}}' >"$FAKE/branch.json"
	fi
	jq -n --argjson r "$checks" '{check_runs: $r}' >"$FAKE/check-runs.json"
	jq -n --argjson r "$runs" '{workflow_runs: $r}' >"$FAKE/runs.json"
	actual=$(PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" bash "$SCRIPT" o/r develop abc1234 2>/dev/null) || actual="exit $?"
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$actual'"
	fi
}

main
