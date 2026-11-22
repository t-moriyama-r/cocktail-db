#!/usr/bin/env bash
#
# ci-failed-logs.sh のテスト(TC-591-01〜)。gh を、決まった JSON・ログを返す偽物に差し替えて流す。GitHub には触らない。
# 書き出したファイルに、どの回のログが入ったか(入らなかったか)を確かめる。
#
# 使い方: .github/scripts/tests/ci-failed-logs.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../ci-failed-logs.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

main() {
	setup_fake_gh

	prepare "[$(wf 11 1 test.yml pull_request failure 2026-11-01T00:00:00Z),$(wf 12 2 evidence-gate.yml pull_request success 2026-11-01T00:00:00Z)]"
	contains TC-591-01 '失敗した回の、失敗したジョブのログを書き出す' 'LOG-11' 'test.yml(回 11、failure)'
	absent TC-591-02 '成功した回のログは書き出さない' 'LOG-12'

	prepare "[$(wf 21 1 test.yml pull_request failure 2026-11-01T00:00:00Z),$(wf 22 1 test.yml pull_request success 2026-11-01T01:00:00Z)]"
	absent TC-591-03 '同じワークフローで後の回が成功していれば、前の失敗は書き出さない' 'LOG-21'
	contains TC-591-04 '失敗した回が無ければ、無いと書く' '失敗した CI の回は無い'

	prepare "[$(wf 31 3 pr-status.yml pull_request_target failure 2026-11-01T00:00:00Z),$(wf 32 4 ai-review-loop.yml pull_request_review failure 2026-11-01T00:00:00Z)]"
	absent TC-591-05 '開発の仕組みのワークフロー(pull_request_target など)の失敗は書き出さない' 'LOG-31'
	absent TC-591-06 '開発の仕組みのワークフロー(pull_request_review)の失敗は書き出さない' 'LOG-32'

	prepare "[$(wf 41 1 test.yml pull_request timed_out 2026-11-01T00:00:00Z),$(wf 42 5 branch-flow.yml pull_request cancelled 2026-11-01T00:00:00Z)]"
	contains TC-591-07 'timed_out も失敗として書き出す' 'LOG-41'
	absent TC-591-08 'cancelled は失敗に数えない' 'LOG-42'

	prepare "[$(wf 51 1 test.yml pull_request failure 2026-11-01T00:00:00Z),$(wf 52 6 e2e.yml pull_request failure 2026-11-01T00:00:00Z)]"
	touch "$FAKE/fail-log-51"
	contains TC-591-09 '1 つの回のログを読めなくても、読めないと書いてほかの回を続ける' 'この回のログを読めなかった'
	contains TC-591-10 '読めない回の後ろの回も書き出す' 'LOG-52'

	prepare "[$(wf 61 1 test.yml pull_request failure 2026-11-01T00:00:00Z)]"
	echo 5 >"$FAKE/long-61"
	MAX_LINES=3 contains TC-591-11 '長いログは末尾の MAX_LINES 行だけにする' '末尾の 3 行だけ'
	MAX_LINES=3 absent TC-591-12 '末尾より前の行は入れない' 'LINE-1'

	prepare "[$(wf 71 1 test.yml pull_request failure 2026-11-01T00:00:00Z)]"
	touch "$FAKE/fail-runs"
	fails TC-591-13 '回の一覧を読めないときは失敗にする(「失敗した回は無い」と取り違えない)'

	check_head TC-591-14 '回の一覧は、渡したコミットで絞って読む' 'head_sha=abc1234'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# gh api actions/runs には $FAKE/runs.json を返し(--slurp ならページの配列にそろえる)、呼んだパスを $FAKE/paths に残す。
# gh run view <回> --log-failed には LOG-<回> を返す。$FAKE/long-<回> があれば、その行数の LINE-<n> を足す。
# $FAKE/fail-runs・$FAKE/fail-log-<回> があれば、それを失敗させる
setup_fake_gh() {
	cat >"$FAKE/gh" <<-'EOF'
		#!/usr/bin/env bash
		if [ "$1" = run ] && [ "$2" = view ]; then
		  id=$3
		  if [ -e "$FAKE_DIR/fail-log-$id" ]; then echo "HTTP 404: Not Found" >&2; exit 1; fi
		  if [ -e "$FAKE_DIR/long-$id" ]; then
		    for i in $(seq 1 "$(cat "$FAKE_DIR/long-$id")"); do echo "LINE-$i"; done
		  fi
		  echo "LOG-$id"
		  exit 0
		fi
		slurp=false path=
		while [ $# -gt 0 ]; do
		  case "$1" in
		    --slurp) slurp=true ;;
		    api | --paginate) ;;
		    *) path=$1 ;;
		  esac
		  shift
		done
		echo "$path" >>"$FAKE_DIR/paths"
		case "$path" in
		  */actions/runs*) ;;
		  *) echo "unexpected gh $path" >&2; exit 1 ;;
		esac
		if [ -e "$FAKE_DIR/fail-runs" ]; then echo "HTTP 502" >&2; exit 1; fi
		if [ "$slurp" = true ]; then echo "[$(cat "$FAKE_DIR/runs.json")]"; else cat "$FAKE_DIR/runs.json"; fi
	EOF
	chmod +x "$FAKE/gh"
}

# 回: id・workflow_id・ファイル名・イベント・conclusion・作った時刻
wf() {
	jq -nc --argjson id "$1" --argjson w "$2" --arg n "$3" --arg e "$4" --arg c "$5" --arg t "$6" \
		'{id: $id, workflow_id: $w, name: $n, event: $e, status: "completed", conclusion: $c, created_at: $t, html_url: ("https://github.com/o/r/actions/runs/" + ($id | tostring))}'
}

prepare() {
	rm -f "$FAKE"/fail-* "$FAKE"/long-* "$FAKE/paths"
	jq -n --argjson r "$1" '{workflow_runs: $r}' >"$FAKE/runs.json"
}

# スクリプトを流し、書き出したファイルの中身を出す。失敗したら「exit <コード>」を出す
produce() {
	local out="$FAKE/out.md"
	rm -f "$out"
	if PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" bash "$SCRIPT" o/r abc1234 "$out" >/dev/null 2>&1; then
		cat "$out"
	else
		echo "exit $?"
	fi
}

contains() {
	local id=$1 title=$2 out
	shift 2
	out=$(produce)
	for want in "$@"; do
		if ! grep -qF -- "$want" <<<"$out"; then
			result "$id" "$title" "含む: $want" "含まない"
			return
		fi
	done
	result "$id" "$title" ok ok
}

absent() {
	local id=$1 title=$2 want=$3 out
	out=$(produce)
	if [[ "$out" == exit* ]]; then
		result "$id" "$title" ok "$out"
	elif grep -qF -- "$want" <<<"$out"; then
		result "$id" "$title" "含まない: $want" "含む"
	else
		result "$id" "$title" ok ok
	fi
}

fails() {
	local id=$1 title=$2 out
	out=$(produce)
	if [[ "$out" == exit* ]]; then result "$id" "$title" failed failed; else result "$id" "$title" failed ok; fi
}

check_head() {
	local id=$1 title=$2 want=$3
	prepare "[]"
	produce >/dev/null
	if grep -qF -- "$want" "$FAKE/paths" 2>/dev/null; then result "$id" "$title" ok ok; else result "$id" "$title" "$want" "$(cat "$FAKE/paths" 2>/dev/null)"; fi
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
