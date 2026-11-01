#!/usr/bin/env bash
#
# evidence-pick.sh のテスト(TC-600-01〜05)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/evidence-pick.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../evidence-pick.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

REQ='<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=evidence -->
撮ってほしい
```evidence
version: 1
```'

main() {
	setup_fake_gh

	comments "OWNER|2026-10-01T00:00:00Z|${REQ/撮ってほしい/古い頼み}" "OWNER|2026-10-02T00:00:00Z|${REQ/撮ってほしい/新しい頼み}" \
		'OWNER|2026-10-03T00:00:00Z|<!-- ai-dev:agent --> 報告だけ'
	run 7
	expect TC-600-01 '信頼する人の頼むコメントのうち、いちばん新しいものの本文を出す' "$(sed -n 2p "$FAKE/out")|$RC" '新しい頼み|0'

	comments "OWNER|2026-10-01T00:00:00Z|$REQ" "NONE|2026-10-02T00:00:00Z|${REQ/撮ってほしい/外の人の頼み}" \
		"CONTRIBUTOR|2026-10-03T00:00:00Z|${REQ/撮ってほしい/前に PR を出した人の頼み}"
	run 7
	expect TC-600-02 '信頼しない人が書いた目印付きのコメントは選ばない(信頼する人の古いほうを選ぶ)' "$(sed -n 2p "$FAKE/out")|$RC" '撮ってほしい|0'

	comments 'OWNER|2026-10-01T00:00:00Z|<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=evidence --> ブロックが無い' \
		'OWNER|2026-10-02T00:00:00Z|```evidence
version: 1
```'
	run 7
	expect TC-600-03 '目印とブロックの片方しか無いコメントは選ばず、無ければ 1 で終わる' "$(cat "$FAKE/out")|$RC|$(grep -c '頼むコメント' "$FAKE/err")" '|1|1'

	comments "OWNER|2026-10-01T00:00:00Z|$REQ"
	touch "$FAKE/api-fails"
	run 7
	expect TC-600-04 '読めなかったら、無いと取り違えずに「読めなかった」と出して 1 で終わる' "$RC|$(grep -c '読めなかった' "$FAKE/err")" '1|1'
	rm -f "$FAKE/api-fails"

	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r >"$FAKE/out" 2>"$FAKE/err" || RC=$?
	expect TC-600-05 '番号が無ければ 2 で終わる' "$RC" 2

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

setup_fake_gh() {
	mkdir -p "$FAKE/bin"
	cat >"$FAKE/bin/gh" <<'SH'
#!/usr/bin/env bash
if [ -f "$FAKE/api-fails" ]; then echo "HTTP 502: api failed" >&2; exit 1; fi
case "$*" in
	"api --paginate --slurp repos/o/r/issues/7/comments"*) cat "$FAKE/comments" ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

# 「author_association|created_at|本文」を並べて、--slurp の形(ページの配列)にする。2 ページに分けて、全ページを見るかも確かめる
comments() {
	jq -n -c '$ARGS.positional | map(capture("^(?<a>[A-Z]+)\\|(?<t>[^|]+)\\|(?<b>[\\s\\S]*)$")
		| {author_association: .a, created_at: .t, body: .b, user: {login: "u"}})
		| [.[0:1], .[1:]]' --args "$@" >"$FAKE/comments"
}

run() {
	RC=0
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
