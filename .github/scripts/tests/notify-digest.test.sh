#!/usr/bin/env bash
#
# notify-digest.jq のテスト(TC-421-xx)。毎朝のまとめに何を載せるか、送るものが無ければ何も出さないかを確かめる。
# GitHub にも Slack にも触らない。
#
# 使い方: .github/scripts/tests/notify-digest.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
FILTER="$HERE/../notify-digest.jq"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

# 毎朝の見回り(schedule)と label=all で渡るラベル
ALL='needs-human needs-decision needs-approval needs-local'

main() {
	run "$ALL" "$(item 10 needs-local 2)"
	expect TC-421-20 '境界: needs-local が付いて 2 日のものは載る' "$(has 'PR #10 ')|$(has 'needs-local')|$(has '1 件')" 'yes|yes|yes'

	run "$ALL" "$(item 11 needs-local 1)"
	expect TC-421-21 '境界: needs-local が付いて 1 日のものだけなら、何も出さない(送らない)' "$OUT" ''

	run "$ALL" "$(item 12 needs-local 0)" "$(item 13 needs-human 0)"
	expect TC-421-22 '2 日たっていない needs-local は載せず、ほかのラベルはこれまでどおり載せる' \
		"$(has 'PR #12 ')|$(has 'PR #13 ')|$(has '1 件')" 'no|yes|yes'

	run "$ALL" "$(item 14 needs-local null)"
	expect TC-421-23 'needs-local の付いた日が分からないものは載せない' "$OUT" ''

	run "$ALL" "$(item 15 needs-human 0)" "$(item 16 needs-local 3)"
	expect TC-421-24 'needs-local の見出しと案内は、ほかのラベルの後に出る' \
		"$(jq -r .text <<<"$OUT" | grep -nE 'needs-human|needs-local' | cut -d: -f1 | paste -sd, -)|$(has '手元で Claude Code を開くと')" '3,7|yes'

	run 'needs-decision' "$(item 17 needs-decision 0)"
	expect TC-421-25 'issue を作り終えたときのまとめ(needs-decision だけ)はこれまでどおり' "$(has '着手する前に決めてほしいことが 1 件')" 'yes'

	run "$ALL"
	expect TC-421-26 '1 件も無ければ何も出さない' "$OUT" ''

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# notify-needs-human.yml の item.jq が作る 1 件。days は null(付いた日が分からない)も渡せる
item() {
	jq -nc --argjson n "$1" --arg label "$2" --argjson days "$3" \
		'{number: $n, title: "t\($n)", url: "https://github.com/o/r/pull/\($n)", kind: "PR", label: $label,
		  ask: {text: "", url: "https://github.com/o/r/pull/\($n)"}, days: $days}'
}

run() {
	local labels=$1
	shift
	if [ $# -gt 0 ]; then printf '%s\n' "$@" >"$FAKE/items.jsonl"; else : >"$FAKE/items.jsonl"; fi
	OUT=$(jq -n -f "$FILTER" --arg repo o/r --arg labels "$labels" --slurpfile items "$FAKE/items.jsonl")
}

has() { if jq -r .text <<<"$OUT" | grep -qF -- "$1"; then echo yes; else echo no; fi; }

expect() {
	local id=$1 title=$2 actual=$3 expected=$4
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$actual'"
	fi
}

main "$@"
