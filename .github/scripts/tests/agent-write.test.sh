#!/usr/bin/env bash
#
# agent-write.sh のテスト(TC-498-13〜)。gh を、呼ばれた引数を書き残す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/agent-write.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE
# 対象の PR を書いたファイル(agent-write.target)を置けるよう、スクリプトを一時ディレクトリに写して動かす
SCRIPT="$FAKE/scripts/agent-write.sh"

FAILED=0
PASSED=0

main() {
	setup

	reset
	echo '直した' >"$FAKE/body.md"
	run reply PRRT_abc "$FAKE/body.md"
	expect TC-498-13 'reply は固定の mutation でスレッドに返信し、返信の URL だけを出す' \
		"$RC|$(out)|$(grep -c '^gh api graphql' "$FAKE/calls")|$(grep -c 'addPullRequestReviewThreadReply' "$FAKE/arg-query")|$(arg id)" '0|https://example.test/reply|1|1|PRRT_abc'

	reset
	echo '直した' >"$FAKE/body.md"
	run reply PRRT_abc "$FAKE/body.md"
	expect TC-498-14 '返信の先頭に目印 <!-- ai-dev:agent --> が無ければ足す' "$(arg body)" '<!-- ai-dev:agent --> 直した'

	reset
	echo '<!-- ai-dev:agent --> 直した' >"$FAKE/body.md"
	run reply PRRT_abc "$FAKE/body.md"
	expect TC-498-15 '目印がもう付いていれば、重ねて足さない' "$(arg body)" '<!-- ai-dev:agent --> 直した'

	reset
	run resolve PRRT_abc
	expect TC-498-16 'resolve はスレッドを解決にし、解決した ID だけを出す' \
		"$RC|$(out)|$(grep -c '^gh api graphql' "$FAKE/calls")|$(grep -c 'resolveReviewThread' "$FAKE/arg-query")" '0|resolved PRRT_abc|1|1'

	reset
	echo '新しい本文' >"$FAKE/body.md"
	run body "$FAKE/body.md"
	expect TC-498-17 'body は対象の PR の本文をファイルで書き換え、応答を出さない' \
		"$RC|$(out)|$(grep -c "api -X PATCH repos/o/r/pulls/5 -F body=@$FAKE/body.md --silent" "$FAKE/calls")" '0|updated #5|1'

	reset
	echo x >"$FAKE/body.md"
	run reply 'PRRT_abc) { x } query { repository' "$FAKE/body.md"
	expect TC-498-18 'スレッドの ID が PRRT_ の形でなければ、gh を呼ばずに 2 で終わる' "$RC|$(calls)" '2|0'

	reset
	: >"$FAKE/empty.md"
	run reply PRRT_abc "$FAKE/empty.md"
	expect TC-498-19 '本文のファイルが空なら、gh を呼ばずに 2 で終わる' "$RC|$(calls)" '2|0'

	reset
	run query '{ viewer { login } }'
	expect TC-498-20 '決まった操作のほか(問い合わせを渡すなど)は、gh を呼ばずに 2 で終わる' "$RC|$(calls)" '2|0'

	reset
	echo x >"$FAKE/body.md"
	REPO=evil/repo PR=99 run body "$FAKE/body.md"
	expect TC-498-21 '対象の PR は agent-write.target から取り、環境変数より優先する' "$(grep -c 'repos/o/r/pulls/5' "$FAKE/calls")" '1'

	reset
	rm -f "$FAKE/scripts/agent-write.target"
	echo x >"$FAKE/body.md"
	run body "$FAKE/body.md"
	expect TC-498-22 'agent-write.target も環境変数 REPO・PR も無ければ、gh を呼ばずに 2 で終わる' "$RC|$(calls)" '2|0'

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

setup() {
	mkdir -p "$FAKE/bin" "$FAKE/scripts"
	cp "$HERE/../agent-write.sh" "$SCRIPT"
	cat >"$FAKE/bin/gh" <<'SH'
#!/usr/bin/env bash
echo "gh $*" >>"$FAKE/calls"
# -f の値を 1 つずつ書き残す(名前=値。値は複数行になりうるので、名前ごとのファイルにする)
while [ $# -gt 0 ]; do
	if [ "$1" = -f ]; then printf '%s' "${2#*=}" >"$FAKE/arg-${2%%=*}"; shift; fi
	shift
done
case "$(cat "$FAKE/arg-query" 2>/dev/null)" in
	*addPullRequestReviewThreadReply*) echo https://example.test/reply ;;
	*resolveReviewThread*) echo "resolved $(cat "$FAKE/arg-id")" ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

reset() {
	rm -f "$FAKE"/calls "$FAKE"/arg-* "$FAKE"/out "$FAKE"/err
	: >"$FAKE/calls"
	printf 'repo=o/r\npr=5\n' >"$FAKE/scripts/agent-write.target"
}

run() {
	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" "$@" >"$FAKE/out" 2>"$FAKE/err" || RC=$?
}

out() { tr -d '\r' <"$FAKE/out"; }
arg() { cat "$FAKE/arg-$1" 2>/dev/null; }
calls() { wc -l <"$FAKE/calls" | tr -d ' '; }

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

main "$@"
