#!/usr/bin/env bash
#
# evidence-leak-check.sh のテスト(TC-600-06〜09)。一時ディレクトリに文字のファイルを置いて流す。
#
# 使い方: .github/scripts/tests/evidence-leak-check.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../evidence-leak-check.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0

main() {
	reset
	printf '%s\n' 'ok' '/home/runner/work/cocktail-db/cocktail-db/backend' >"$WORK/d/01-command-after.txt"
	run "$WORK/d"
	expect TC-600-06 'ランナーのパス(/home/<名前>)が出ていれば、ファイルと行を出して 1 で終わる(値は出さない)' \
		"$RC|$(grep -c '01-command-after.txt:2: ランナーのパス' "$WORK/err")|$(grep -c 'runner/work' "$WORK/err")" '1|1|0'

	reset
	printf '%s\n' 'Server: FV-AZ123-456 から返した' >"$WORK/d/01-api-before.txt"
	EVIDENCE_LEAK_WORDS=$'fv-az123-456\n_temp' run "$WORK/d"
	expect TC-600-07 'EVIDENCE_LEAK_WORDS の語(ホスト名)は大文字・小文字を区別せずに探し、値は出さない' \
		"$RC|$(grep -c 'EVIDENCE_LEAK_WORDS の語' "$WORK/err")|$(grep -ci 'fv-az' "$WORK/err")" '1|1|0'

	reset
	printf '%s\n' 'カクテル 3 件' '/api/cocktails?q=gin' 'パスは /home/ の下に置かない(説明)' >"$WORK/d/a.txt"
	printf '\x89PNG\r\n\x1a\n\x00\x00/home/runner/' >"$WORK/d/b.png"
	EVIDENCE_LEAK_WORDS=$'ab\n' run "$WORK/d"
	expect TC-600-08 '出ていなければ 0。画像(バイナリ)と 2 文字以下の語は見ない' "$RC|$(wc -c <"$WORK/err" | tr -d ' ')" '0|0'

	reset
	run "$WORK/none"
	expect TC-600-09 'ディレクトリが無ければ 2 で終わる(見ていないのに通さない)' "$RC" 2

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

reset() {
	rm -rf "$WORK/d"
	mkdir -p "$WORK/d"
}

run() {
	RC=0
	bash "$SCRIPT" "$@" >"$WORK/out" 2>"$WORK/err" || RC=$?
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
		sed 's/^/     stderr: /' "$WORK/err" 2>/dev/null | head -5
	fi
}

main
