#!/usr/bin/env bash
#
# permission-denials.sh のテスト(TC-455-18〜)。claude-code-action の execution_file を模したファイルを渡して流す。
#
# 使い方: .github/scripts/tests/permission-denials.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../permission-denials.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0

main() {
	execution "$(denial Bash '{"command":"gh pr edit 426 --body \"表に行を足す\""}')" "$(denial Write '{"file_path":"/tmp/body.md"}')"
	run
	expect TC-455-18 'Bash はツールの名前とコマンドを、ほかのツールは名前だけを ::warning:: で出す' \
		"$(warnings)" "$(printf '%s\n%s' \
			'::warning title=Claude Code が拒否されたツール::Bash: gh pr edit 426 --body "表に行を足す"' \
			'::warning title=Claude Code が拒否されたツール::Write')"

	execution "$(denial Bash "{\"command\":\"echo $(printf 'a%.0s' $(seq 1 100))\"}")"
	run
	expect TC-455-19 'Bash のコマンドは先頭 80 文字に切る' \
		"$(warnings | sed 's/.*::Bash: //' | awk '{ print length($0) }')" 80

	execution "$(denial Bash '{"command":"gh pr comment 1 --body \"x\n::error::偽のエラー\r\n100%\""}')"
	run
	expect TC-455-20 'コマンドの改行は空白にし、% は置き換える(ログのワークフローのコマンドとして読まれない)' \
		"$(warnings)" '::warning title=Claude Code が拒否されたツール::Bash: gh pr comment 1 --body "x ::error::偽のエラー 100%25"'

	execution
	run
	expect TC-455-21 '拒否されたツールが無ければ、警告を出さずに成功で終わる' "$(warnings)|$RC" '|0'

	printf '%s\n' '{"type":"system","subtype":"init"}' "$(result_json "$(denial Read '{"file_path":"a"}')")" >"$WORK/execution.json"
	run
	expect TC-455-22 '1 行に 1 つのメッセージを並べた記録でも読む' "$(warnings)" '::warning title=Claude Code が拒否されたツール::Read'

	RC=0
	bash "$SCRIPT" "$WORK/missing.json" >"$WORK/out" 2>&1 || RC=$?
	expect TC-455-23 '記録のファイルが無くても、失敗にしない(後ろの手順を止めない)' "$(warnings)|$RC" '|0'

	RC=0
	bash "$SCRIPT" "" >"$WORK/out" 2>&1 || RC=$?
	expect TC-455-24 'Claude Code が動かず execution_file が空でも、失敗にしない' "$(warnings)|$RC" '|0'

	printf 'not json' >"$WORK/execution.json"
	run
	expect TC-455-25 '記録を JSON として読めなければ、そう警告して成功で終わる' "$(grep -c '^::warning::' "$WORK/out")|$RC" '1|0'

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# claude-code-action の execution_file(SDK のメッセージの配列。最後が結果)。引数は拒否 1 件ずつの JSON
execution() {
	jq -n --argjson r "$(result_json "$@")" '[{type: "system", subtype: "init"}, {type: "assistant"}, $r]' >"$WORK/execution.json"
}

result_json() {
	printf '%s\n' "$@" | jq -s -c '{type: "result", subtype: "success", permission_denials_count: length, permission_denials: map(select(. != null))}'
}

denial() {
	jq -n -c --arg name "$1" --argjson input "$2" '{tool_name: $name, tool_use_id: "toolu_1", tool_input: $input}'
}

# Windows の jq・bash は CRLF を混ぜることがあるので、手元でも CI と同じに比べられるよう CR を落とす
warnings() { grep '^::warning' "$WORK/out" | tr -d '\r'; }

run() {
	RC=0
	bash "$SCRIPT" "$WORK/execution.json" >"$WORK/out" 2>&1 || RC=$?
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
		sed 's/^/     | /' "$WORK/out"
	fi
}

main
