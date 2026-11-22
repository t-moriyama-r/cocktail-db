#!/usr/bin/env bash
#
# evidence-kick.sh のテスト(TC-686-01〜06)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
# evidence-kick.sh は同じディレクトリの evidence-target.sh を呼ぶので、偽物の gh はその読み取りにも答える。
#
# 使い方: .github/scripts/tests/evidence-kick.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../evidence-kick.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

EVIDENCE='<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=evidence --> 撮ってほしい'
PUSH='<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=push --> 当ててほしい'

main() {
	setup_fake_gh

	pr needs-local
	comments "OWNER|2026-11-01T00:00:00Z|$PUSH" "BOT:claude[bot]|2026-11-02T00:00:00Z|$EVIDENCE"
	run 7 'ai-in-actions'
	expect TC-686-01 'この回に needs-local が付き、いちばん新しい頼むコメントが kind=evidence なら evidence-capture.yml を起こす' \
		"$RC|$(cat "$FAKE/dispatched" 2>/dev/null)" '0|workflow run evidence-capture.yml -R o/r -f number=7'

	comments "OWNER|2026-11-01T00:00:00Z|$EVIDENCE" "BOT:claude[bot]|2026-11-02T00:00:00Z|$PUSH"
	run 7 ''
	expect TC-686-02 'いちばん新しい頼むコメントが kind=push なら起こさない' "$RC|$(test -f "$FAKE/dispatched" && echo dispatched)" '0|'

	comments "OWNER|2026-11-02T00:00:00Z|$EVIDENCE"
	run 7 'needs-local,ai-in-actions'
	expect TC-686-03 '動かす前から needs-local が付いていたら起こさない(この回に付いたものではない)' \
		"$RC|$(test -f "$FAKE/dispatched" && echo dispatched)|$(grep -c '動かす前から' "$FAKE/out")" '0||1'

	pr ai-in-actions
	run 7 ''
	expect TC-686-04 'いま needs-local が付いていなければ起こさない(needs-human だけを付けた回など)' "$RC|$(test -f "$FAKE/dispatched" && echo dispatched)" '0|'

	pr needs-local
	touch "$FAKE/comments-fail"
	run 7 ''
	local read_fail=$RC
	rm -f "$FAKE/comments-fail"
	touch "$FAKE/dispatch-fail"
	run 7 ''
	expect TC-686-05 'コメントを読めなかった・起こせなかったら、起こさなかったと取り違えずに 3' "$read_fail|$RC" '3|3'
	rm -f "$FAKE/dispatch-fail"

	run abc ''
	local bad_number=$RC
	run 7
	expect TC-686-06 '引数の誤り(番号が数字でない・動かす前のラベルを渡していない)は 2' "$bad_number|$RC" '2|2'

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

setup_fake_gh() {
	mkdir -p "$FAKE/bin"
	cat >"$FAKE/bin/gh" <<'SH'
#!/usr/bin/env bash
case "$*" in
	"api repos/o/r/pulls/7") cat "$FAKE/pr" ;;
	"api --paginate --slurp repos/o/r/issues/7/comments?per_page=100")
		if [ -f "$FAKE/comments-fail" ]; then echo "HTTP 502" >&2; exit 1; fi
		cat "$FAKE/comments"
		;;
	"workflow run evidence-capture.yml -R o/r -f number=7")
		if [ -f "$FAKE/dispatch-fail" ]; then echo "HTTP 403" >&2; exit 1; fi
		echo "$*" >"$FAKE/dispatched"
		;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

# 開いている develop 向けの PR を作る: pr [ラベル...]
pr() {
	jq -n -c '{state: "open", head: {repo: {full_name: "o/r"}}, base: {ref: "develop"}, labels: ($ARGS.positional | map({name: .}))}' --args "$@" >"$FAKE/pr"
}

# 「author_association|created_at|本文」を並べて、--slurp の形(ページの配列)にする。BOT:<login> は bot の書いたもの
comments() {
	jq -n -c '$ARGS.positional | map(capture("^(?<a>[^|]+)\\|(?<t>[^|]+)\\|(?<b>[\\s\\S]*)$")
		| if (.a | startswith("BOT:")) then {author_association: "NONE", user: {login: (.a | ltrimstr("BOT:"))}} else {author_association: .a, user: {login: "u"}} end
		+ {created_at: .t, body: .b})
		| [.[0:1], .[1:]]' --args "$@" >"$FAKE/comments"
}

run() {
	RC=0
	rm -f "$FAKE/dispatched"
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r "$@" >"$FAKE/out" 2>"$FAKE/err" || RC=$?
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
