#!/usr/bin/env bash
#
# release-version.sh のテスト(TC-334-01〜)。GitHub には触らない(タグは標準入力で渡す)。
#
# 使い方: .github/scripts/tests/release-version.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../release-version.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
OUT=$WORK/err

FAILED=0
PASSED=0

main() {
	check '【運用】release v1.2.0' 'v1.0.0 v1.1.0'
	expect TC-334-01 '形が合い、前のタグより大きい版なら通り、版を出す' "$(result)" '0|v1.2.0'

	check '【運用】develop を release に入れる' 'v1.0.0'
	expect TC-334-02 'タイトルに vX.Y.Z が無ければ落ち、決まった形をエラーに出す' \
		"$(result)|$(grep -c '::error::release 向けの PR のタイトルは「【運用】release v<MAJOR>.<MINOR>.<PATCH>」' "$OUT")" '1||1'

	check '【運用】release 1.2.0' ''
	expect TC-334-03 '版の先頭に v が無ければ落ちる' "$(status)" 1

	check '【運用】release v1.2' ''
	expect TC-334-04 '版の数が 3 つでなければ落ちる' "$(status)" 1

	check '【運用】release v1.02.0' ''
	expect TC-334-05 '数の先頭に 0 を付けた版は落ちる(SemVer のとおり)' "$(status)" 1

	check 'release v1.2.0' ''
	expect TC-334-06 '接頭辞【運用】が無ければ落ちる' "$(status)" 1

	check '【運用】release v1.2.0(hotfix)' ''
	expect TC-334-07 '版の後ろに文字を足したタイトルは落ちる(release 向けは補足を付けない)' "$(status)" 1

	check '【運用】release v1.1.0' 'v1.0.0 v1.1.0'
	expect TC-334-08 '前のタグと同じ版は落ち、前のタグをエラーに出す(境界)' \
		"$(result)|$(grep -c '::error::版 v1.1.0 は、前のタグ v1.1.0 より大きくする' "$OUT")" '1||1'

	check '【運用】release v1.0.9' 'v1.1.0'
	expect TC-334-09 '前のタグより小さい版は落ちる' "$(status)" 1

	check '【運用】release v1.1.1' 'v1.1.0'
	expect TC-334-10 '前のタグより PATCH が 1 つ大きい版は通る(境界)' "$(result)" '0|v1.1.1'

	check '【運用】release v1.10.0' 'v1.9.0'
	expect TC-334-11 '数として比べる(v1.10.0 は v1.9.0 より大きい)' "$(result)" '0|v1.10.0'

	check '【運用】release v2.0.0' 'v1.9.9'
	expect TC-334-12 'MAJOR を上げた版は、MINOR・PATCH が小さくても通る' "$(result)" '0|v2.0.0'

	check '【運用】release v1.0.0' ''
	expect TC-334-13 'タグが 1 つも無ければ、形が合えば通る(最初の版)' "$(result)" '0|v1.0.0'

	check '【運用】release v1.1.5' 'v1.0.0 v1.2.0 v1.1.0'
	expect TC-334-14 'タグの並びによらず、いちばん大きい版と比べる' "$(status)" 1

	check '【運用】release v1.0.1' 'v9 legacy-2024 v10.0 v1.0.0 v01.0.0'
	expect TC-334-15 'vX.Y.Z の形でないタグは比べる相手にしない' "$(result)" '0|v1.0.1'

	bash "$SCRIPT" parse '【運用】release v3.4.5' >"$OUT" 2>&1
	expect TC-334-16 'parse はタグを見ずに、タイトルの版だけを出す' "$?|$(cat "$OUT")" '0|v3.4.5'

	bash "$SCRIPT" >"$OUT" 2>&1
	expect TC-334-17 'サブコマンドが無ければ使い方を出して失敗する' "$?|$(grep -c '使い方' "$OUT")" '2|1'

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# タイトルと、いまあるタグ(空白区切り)で check を流す
check() {
	local title=$1 tags=$2
	# shellcheck disable=SC2086
	printf '%s\n' $tags | bash "$SCRIPT" check "$title" >"$WORK/stdout" 2>"$OUT"
	echo $? >"$WORK/status"
}

status() { cat "$WORK/status"; }
result() { echo "$(status)|$(cat "$WORK/stdout")"; }

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
		sed 's/^/     | /' "$OUT"
	fi
}

main
