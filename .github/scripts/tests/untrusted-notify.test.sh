#!/usr/bin/env bash
#
# untrusted-notify.sh のテスト(TC-494-23〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/untrusted-notify.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../untrusted-notify.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

SINCE=2026-10-29T03:00:00Z
HOLD_BODY='<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- ai-dev:untrusted -->\n- https://example.test/c9 (someone・NONE)'

main() {
	setup_fake_gh

	reset
	labels needs-human
	comment github-actions[bot] 2026-10-29T03:01:00Z "$HOLD_BODY"
	run 7
	expect TC-494-23 'この回に頼むコメントを書いて needs-human が付いていれば、Slack に知らせる' "$RC|$(posts)" '0|notify:7:needs-human'

	reset
	labels needs-human
	comment github-actions[bot] 2026-10-29T02:00:00Z "$HOLD_BODY"
	run 7
	expect TC-494-24 '頼むコメントが前の回のもの(付け直しただけ)なら知らせない' "$RC|$(posts)" '0|'

	reset
	comment github-actions[bot] 2026-10-29T03:01:00Z "$HOLD_BODY"
	run 7
	expect TC-494-25 'この回に頼むコメントを書いたが needs-human の付与に失敗していても、Slack に知らせる' "$RC|$(posts)" '0|notify:7:needs-human'

	reset
	labels needs-human
	comment someone 2026-10-29T03:01:00Z "$HOLD_BODY"
	run 7
	expect TC-494-26 '外の人が目印をまねたコメントでは知らせない' "$RC|$(posts)" '0|'

	reset
	labels needs-human
	comment github-actions[bot] 2026-10-29T03:01:00Z "$HOLD_BODY"
	touch "$FAKE/api-fails"
	run 7
	expect TC-494-27 '読めなかったら知らせずに失敗で終わる' "$([ "$RC" -ne 0 ] && echo fail)|$(posts)" 'fail|'

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

setup_fake_gh() {
	mkdir -p "$FAKE/bin"
	cat >"$FAKE/bin/gh" <<'SH'
#!/usr/bin/env bash
echo "gh $*" >>"$FAKE/calls"
if [ -f "$FAKE/api-fails" ] && [[ "$*" == *"/comments?"* ]]; then echo "HTTP 502: api failed" >&2; exit 1; fi
case "$*" in
	"api repos/o/r/issues/"[0-9]*" --jq "*)
		jq -r "${@: -1}" <<<"$(jq -n -c --argjson ls "[$(cat "$FAKE/labels" 2>/dev/null)]" '{labels: [$ls[] | {name: .}]}')" ;;
	"api --paginate repos/o/r/issues/"*"/comments?"*" --jq "*)
		jq -r "${@: -1}" "$FAKE/comments" ;;
	"workflow run notify-needs-human.yml -R o/r -f number="*" -f label="*)
		args="$*"; n=${args#*number=}; echo "notify:${n%% *}:${args##*label=}" >>"$FAKE/posts" ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

reset() {
	rm -f "$FAKE"/calls "$FAKE"/api-fails "$FAKE"/out "$FAKE"/err "$FAKE"/posts "$FAKE"/labels
	echo '[]' >"$FAKE/comments"
	: >"$FAKE/calls"
}

labels() { printf '"%s"' "$1" >"$FAKE/labels"; }

comment() {
	local login=$1 at=$2 body=$3
	jq -c --arg login "$login" --arg at "$at" --arg body "$(printf '%b' "$body")" \
		'. + [{id: (length + 1), user: {login: $login}, created_at: $at, body: $body}]' "$FAKE/comments" >"$FAKE/comments.new"
	mv "$FAKE/comments.new" "$FAKE/comments"
}

# 書いたもの(通知)を、書いた順に , でつなぐ
posts() { paste -sd, "$FAKE/posts" 2>/dev/null | tr -d '\r'; }

run() {
	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r "$@" "$SINCE" >"$FAKE/out" 2>"$FAKE/err" || RC=$?
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
		sed 's/^/     stderr: /' "$FAKE/err" 2>/dev/null | head -5
	fi
}

main
