#!/usr/bin/env bash
#
# evidence-target.sh のテスト(TC-601-01〜09)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/evidence-target.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../evidence-target.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

EVIDENCE='<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=evidence --> 撮ってほしい'
PUSH='<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=push --> 当ててほしい'

main() {
	setup_fake_gh

	pr open o/r develop needs-local
	comments "OWNER|2026-11-01T00:00:00Z|$PUSH" "OWNER|2026-11-02T00:00:00Z|$EVIDENCE" 'OWNER|2026-11-03T00:00:00Z|<!-- ai-dev:agent --> 報告だけ'
	run 7 --labeled
	expect TC-601-01 'needs-local が付いていて、いちばん新しい頼むコメントが kind=evidence なら撮ってよい(0)' "$RC|$(cat "$FAKE/out")" '0|'

	comments "OWNER|2026-11-01T00:00:00Z|$EVIDENCE" "OWNER|2026-11-02T00:00:00Z|$PUSH"
	run 7 --labeled
	expect TC-601-02 'いちばん新しい頼むコメントが kind=push なら撮らない(1)' "$RC|$(grep -c 'kind=push' "$FAKE/out")" '1|1'

	comments "OWNER|2026-11-01T00:00:00Z|$PUSH" "NONE|2026-11-02T00:00:00Z|$EVIDENCE" "CONTRIBUTOR|2026-11-03T00:00:00Z|$EVIDENCE"
	run 7 --labeled
	expect TC-601-03 '信頼しない人が書いた kind=evidence の目印は数えない(信頼する人の kind=push で撮らない)' "$RC|$(grep -c 'kind=push' "$FAKE/out")" '1|1'

	comments "OWNER|2026-11-01T00:00:00Z|$PUSH" "BOT:github-actions[bot]|2026-11-02T00:00:00Z|$EVIDENCE"
	run 7 --labeled
	expect TC-601-04 '決まった bot(撮れなかったと書いた evidence-capture.yml)の kind=evidence は数える' "$RC" 0

	pr open o/r develop ai-in-actions
	comments "OWNER|2026-11-02T00:00:00Z|$EVIDENCE"
	run 7 --labeled
	expect TC-601-05 'いま needs-local が付いていなければ撮らない(イベントの後に外された)' "$RC|$(grep -c 'needs-local が付いていない' "$FAKE/out")" '1|1'

	local results=() base
	for base in staging release; do
		pr open o/r "$base" needs-local
		run 7 --labeled
		results+=("$RC")
		run 7
		results+=("$RC")
	done
	expect TC-601-06 '昇格の PR(staging・release 向け)では、ラベルの回でも手動の回でも撮らない' "${results[*]}|$(grep -c '昇格の PR' "$FAKE/out")" '1 1 1 1|1'

	pr closed o/r develop needs-local
	run 7
	local closed=$RC
	pr open someone/fork develop needs-local
	run 7
	expect TC-601-07 '開いていない PR・フォークからの PR では撮らない' "$closed|$RC|$(grep -c 'フォーク' "$FAKE/out")" '1|1|1'

	pr open o/r develop
	comments "OWNER|2026-11-02T00:00:00Z|$PUSH"
	run 7
	expect TC-601-08 '手動の回(--labeled なし)は、ラベルと頼むコメントの種類を見ずに撮ってよい' "$RC" 0

	pr open o/r develop needs-local
	touch "$FAKE/comments-fail"
	run 7 --labeled
	local read_fail=$RC
	rm -f "$FAKE/comments-fail"
	run abc
	local bad_number=$RC
	run 7 --other
	expect TC-601-09 'コメントを読めなかったら撮らないと取り違えずに 3、引数の誤りは 2' "$read_fail|$bad_number|$RC" '3|2|2'

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
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

# PR を作る: pr <state> <head のリポジトリ> <向き先> [ラベル...]
pr() {
	local state=$1 head=$2 base=$3
	shift 3
	jq -n -c --arg state "$state" --arg head "$head" --arg base "$base" \
		'{state: $state, head: {repo: {full_name: $head}}, base: {ref: $base}, labels: ($ARGS.positional | map({name: .}))}' --args "$@" >"$FAKE/pr"
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
