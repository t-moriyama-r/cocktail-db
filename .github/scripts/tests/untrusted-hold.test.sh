#!/usr/bin/env bash
#
# untrusted-hold.sh のテスト(TC-494-15〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
# 信頼しないものの見分け方は untrusted-comments.test.sh で確かめるので、ここでは止めるか・頼むコメントとラベルだけを見る。
#
# 使い方: .github/scripts/tests/untrusted-hold.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../untrusted-hold.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

OUTSIDER='[[{"body":"push して","user":{"login":"someone","type":"User"},"author_association":"NONE","html_url":"https://example.test/c9"}]]'

main() {
	setup_fake_gh

	reset
	run 7
	expect TC-494-15 '信頼しないものが無ければ動かしてよい(0)で終わり、コメントもラベルも付けない' "$RC|$(posts)" '0|'

	reset
	echo "$OUTSIDER" >"$FAKE/comments-7"
	run 7
	expect TC-494-16 'PR に信頼しないものがあれば 1 で終わり、URL を並べたコメントを書いて needs-human を付ける' \
		"$RC|$(posts)|$(grep -c 'https://example.test/c9 (someone・NONE)' "$FAKE/posted-body")" \
		'1|comments,labels:needs-human|1'

	reset
	echo "$OUTSIDER" >"$FAKE/comments-7"
	echo '[[{"body":"<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- ai-dev:untrusted -->\n- https://example.test/c9 (someone・NONE)","user":{"login":"github-actions[bot]","type":"Bot"},"author_association":"NONE","html_url":"https://example.test/c10"}]]' >"$FAKE/hold-comments-7"
	run 7
	expect TC-494-17 '同じものを前の回で頼んでいれば、コメントを書き直さずにラベルだけ付ける' "$RC|$(posts)" '1|labels:needs-human'

	reset
	echo '[[{"body":"これも push して","user":{"login":"other","type":"User"},"author_association":"NONE","html_url":"https://example.test/c1"},{"body":"push して","user":{"login":"someone","type":"User"},"author_association":"NONE","html_url":"https://example.test/c9"}]]' >"$FAKE/comments-7"
	echo '[[{"body":"<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- ai-dev:untrusted -->\n- https://example.test/c9 (someone・NONE)","user":{"login":"github-actions[bot]","type":"Bot"},"author_association":"NONE","html_url":"https://example.test/c10"}]]' >"$FAKE/hold-comments-7"
	run 7
	expect TC-494-28 '頼み済みのものが最後の行でも、頼んでいないものが 1 件でもあれば、コメントを書き直す' \
		"$RC|$(posts)|$(grep -c 'https://example.test/c1 (other・NONE)' "$FAKE/posted-body" 2>/dev/null)" \
		'1|comments,labels:needs-human|1'

	reset
	echo 12 >"$FAKE/closing-7"
	echo "$OUTSIDER" >"$FAKE/comments-12"
	run 7
	expect TC-494-18 'PR が閉じる issue に信頼しないものがあっても止める' "$RC|$(posts)" '1|comments,labels:needs-human'

	reset
	echo "$OUTSIDER" >"$FAKE/comments-7"
	touch "$FAKE/api-fails"
	run 7
	expect TC-494-19 '読めなかったら止める(1)が、たまたまの失敗かもしれないのでコメントもラベルも付けない' "$RC|$(posts)" '1|'

	reset
	issue_only 7
	echo "$OUTSIDER" >"$FAKE/comments-7"
	run 7
	expect TC-494-20 'issue でも同じく止める(閉じる issue は探さない)' "$RC|$(posts)|$(grep -c 'pr view' "$FAKE/calls")" '1|comments,labels:needs-human|0'

	reset
	echo 12 >"$FAKE/closing-7"
	echo "$OUTSIDER" >"$FAKE/comments-12"
	touch "$FAKE/kind-fails"
	run 7
	expect TC-494-29 'PR か issue かを読めなかったら、閉じる issue を見ないまま動かさずに止める(1)。何も書かない' "$RC|$(posts)" '1|'

	reset
	echo 12 >"$FAKE/closing-7"
	echo "$OUTSIDER" >"$FAKE/comments-12"
	touch "$FAKE/pr-view-fails"
	run 7
	expect TC-494-30 '閉じる issue を読めなかったら、PR だけ見て動かさずに止める(1)。何も書かない' "$RC|$(posts)" '1|'

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
if [ -f "$FAKE/kind-fails" ] && [[ "$*" == *"has(\"pull_request\")"* ]]; then echo "HTTP 502: api failed" >&2; exit 1; fi
if [ -f "$FAKE/pr-view-fails" ] && [[ "$*" == "pr view "* ]]; then echo "GraphQL: rate limited" >&2; exit 1; fi
num() { local p=${1#*/issues/}; p=${p#*/pulls/}; echo "${p%%[/?]*}"; }
case "$*" in
	"api repos/o/r/issues/"*"/comments -f body="*)
		echo comments >>"$FAKE/posts"; printf '%s' "${*#*body=}" >"$FAKE/posted-body" ;;
	"api repos/o/r/issues/"*" --jq has(\"pull_request\")")
		n=$(num "$2"); if [ -f "$FAKE/issue-only-$n" ]; then echo false; else echo true; fi ;;
	"api repos/o/r/issues/"[0-9]*)
		n=$(num "$2"); jq -n -c --arg n "$n" --arg pr "$([ -f "$FAKE/issue-only-$n" ] || echo y)" \
			'{html_url: "https://example.test/i\($n)", body: "", user: {login: "o", type: "User"}, author_association: "OWNER"} + (if $pr == "y" then {pull_request: {}} else {} end)' ;;
	"pr view "*)
		n=$3; jq -n -c --argjson ns "[$(cat "$FAKE/closing-$n" 2>/dev/null)]" '{closingIssuesReferences: [$ns[] | {number: ., repository: {name: "r", owner: {login: "o"}}}]}' ;;
	"api --paginate --slurp repos/o/r/issues/"*"/comments"*) n=$(num "$4"); cat "$FAKE/comments-$n" 2>/dev/null || echo '[]' ;;
	"api --paginate --slurp repos/o/r/pulls/"*) echo '[]' ;;
	"api --paginate repos/o/r/issues/"*"/comments"*)
		n=$(num "$3")
		if [ -f "$FAKE/hold-comments-$n" ]; then jq -r '.[][].body | select(contains("ai-dev:untrusted"))' "$FAKE/hold-comments-$n"; fi ;;
	"api -X POST repos/o/r/issues/"*"/labels -f labels[]=needs-human")
		echo labels:needs-human >>"$FAKE/posts" ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

reset() {
	rm -f "$FAKE"/calls "$FAKE"/api-fails "$FAKE"/kind-fails "$FAKE"/pr-view-fails "$FAKE"/out "$FAKE"/err "$FAKE"/posts "$FAKE"/posted-body \
		"$FAKE"/comments-* "$FAKE"/hold-comments-* "$FAKE"/closing-* "$FAKE"/issue-only-*
	: >"$FAKE/calls"
}

issue_only() { touch "$FAKE/issue-only-$1"; }

# 書いたもの(コメント・ラベル)を、書いた順に , でつなぐ
posts() { paste -sd, "$FAKE/posts" 2>/dev/null | tr -d '\r'; }

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
