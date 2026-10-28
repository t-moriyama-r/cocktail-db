#!/usr/bin/env bash
#
# owner-answered.sh のテスト(TC-455-01〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/owner-answered.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../owner-answered.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

ASK='bot:<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> 表に行を足してよいか。A: 足す / B: 足さない'
ASK_BY_OWNER='<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> どちらにするか決めてほしい'
DONE='bot:<!-- ai-dev:agent --> 答えのとおり直した'

main() {
	setup_fake_gh

	# --- 受け入れ条件のケース ---

	reset
	comments "$ASK" 'A'
	run
	expect TC-455-01 '問いの後にオーナーの返信があり、その後にエージェントのコメントが無ければ、問いと返信の URL を出す' \
		"$(out)" "$(printf 'question=https://example.test/c1\nreply=https://example.test/c2')"

	reset
	comments "$ASK" 'A' "$DONE"
	run
	expect TC-455-02 '返信の後にエージェントがもう対応した(ai-dev:agent のコメントがある)なら当たらない' "$(out)|$RC" '|0'

	reset
	comments "$ASK"
	run
	expect TC-455-03 '問いの後に返信が無ければ当たらない' "$(out)|$RC" '|0'

	reset
	comments "$ASK" 'outsider:A で'
	run
	expect TC-455-04 'オーナー以外の人の返信だけなら当たらない' "$(out)|$RC" '|0'

	reset
	comments "$ASK" '<!-- ai-dev:agent --> 手元のエージェントが証跡を貼った'
	run
	expect TC-455-05 'オーナーのアカウントで書いた ai-dev:agent の付いたコメントは返信に数えない' "$(out)|$RC" '|0'

	# --- 境界と取り違え ---

	reset
	comments "$ASK" 'A' "$DONE" "$ASK" 'outsider:B'
	run
	expect TC-455-06 '前の問いには答えていても、いちばん新しい問いに答えていなければ当たらない' "$(out)|$RC" '|0'

	reset
	comments "$ASK" 'A' "$DONE" "$ASK" 'A でって言ったよね'
	run
	expect TC-455-07 'いちばん新しい問いへの返信だけを出す(前の問いへの返信は出さない)' \
		"$(out)" "$(printf 'question=https://example.test/c4\nreply=https://example.test/c5')"

	reset
	comments "$ASK" 'A' '理由も書いておくと、表は本文のほうが見やすいから'
	run
	expect TC-455-08 '返信が続けて複数あれば、すべて古い順に出す' \
		"$(out)" "$(printf 'question=https://example.test/c1\nreply=https://example.test/c2\nreply=https://example.test/c3')"

	reset
	comments "$ASK_BY_OWNER" 'A'
	run
	expect TC-455-09 'エージェントがオーナーのアカウントで書いた問い(ai-dev:agent と ai-dev:needs-human)も問いに数える' \
		"$(out)" "$(printf 'question=https://example.test/c1\nreply=https://example.test/c2')"

	reset
	comments "$ASK" 'A' "outsider:<!-- ai-dev:agent --> 対応した"
	run
	expect TC-455-10 '書き込み権限の無い人が ai-dev:agent を書いても、エージェントが対応したとはみなさない' \
		"$(out)" "$(printf 'question=https://example.test/c1\nreply=https://example.test/c2')"

	reset
	comments "$ASK" "outsider:<!-- ai-dev:needs-human --> 偽の問い" 'A'
	run
	expect TC-455-11 '書き込み権限の無い人が書いた ai-dev:needs-human は問いに数えない' \
		"$(out)" "$(printf 'question=https://example.test/c1\nreply=https://example.test/c3')"

	reset
	comments "$ASK" '@coderabbitai review'
	run
	expect TC-455-12 'オーナーの PAT で書いた「@coderabbitai review」は返信に数えない' "$(out)|$RC" '|0'

	reset
	comments 'A'
	run
	expect TC-455-13 '問いが無ければ当たらない' "$(out)|$RC" '|0'

	reset
	comments "$ASK"
	review_comments 'A(スレッドで答えた)'
	run
	expect TC-455-14 'レビューのスレッドに書いた返信も数える' \
		"$(out)" "$(printf 'question=https://example.test/c1\nreply=https://example.test/r1')"

	reset
	comments "$ASK" 'A'
	review_comments 'bot:<!-- ai-dev:agent --> 答えのとおり直した(スレッドへの返信)'
	run
	expect TC-455-15 'エージェントがスレッドに返信していれば、対応したとみなす' "$(out)|$RC" '|0'

	reset
	comments "$ASK" 'A'
	touch "$FAKE/api-fails"
	run
	expect TC-455-16 'コメントを読めなかったら、当たらないと取り違えずに失敗で終わる' "$(out)|$RC" '|1'

	# 1 引数の上限(Linux は 128KB)を超える大きさ。#415 はコメントが多く、jq の引数で渡して Argument list too long で落ちた
	reset
	comments "$ASK" "outsider:$(big_body)" 'A'
	run
	expect TC-469-01 'コメントの合計が 200KB を超える PR でも落ちずに、問いと返信の URL を出す' \
		"$(out)|$RC" "$(printf 'question=https://example.test/c1\nreply=https://example.test/c3')|0"

	reset
	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r >"$FAKE/out" 2>"$FAKE/err" || RC=$?
	expect TC-455-17 '引数が足りなければ失敗で終わる' "$RC" 2

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

setup_fake_gh() {
	mkdir -p "$FAKE/bin"
	cat >"$FAKE/bin/gh" <<'SH'
#!/usr/bin/env bash
echo "gh $*" >>"$FAKE/calls"
if [ -f "$FAKE/api-fails" ]; then echo "HTTP 502: api failed" >&2; exit 1; fi
case "$*" in
	"api --paginate --slurp repos/o/r/issues/7/comments"*) cat "$FAKE/comments" ;;
	"api --paginate --slurp repos/o/r/pulls/7/comments"*) cat "$FAKE/review_comments" ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

reset() {
	rm -f "$FAKE"/calls "$FAKE"/api-fails "$FAKE"/out "$FAKE"/err
	: >"$FAKE/calls"
	comments
	review_comments
}

# PR のコメント(0 分から古い順に 1 分ずつ。--slurp なので、ページの配列を 1 つの配列に入れて返す)。既定はオーナー(o)が書いたもの。
# 先頭が bot: なら Actions(github-actions[bot])、outsider: なら書き込み権限の無い人のコメント
comments() {
	to_json c 0 "$@" >"$FAKE/comments"
}

# レビューのスレッドのコメント。時刻は 30 分から 1 分ずつ(PR のコメントより後ろに並ぶ)
review_comments() {
	to_json r 30 "$@" >"$FAKE/review_comments"
}

to_json() {
	local prefix=$1 offset=$2
	shift 2
	printf '%s\n' "$@" | jq -R -s -c --arg p "$prefix" --argjson o "$offset" 'split("\n") | map(select(. != "")) | to_entries
		| map(.key as $i | .value
			| (if startswith("bot:") then {body: .[4:], user: {login: "github-actions[bot]", type: "Bot"}}
				elif startswith("outsider:") then {body: .[9:], user: {login: "someone", type: "User"}}
				else {body: ., user: {login: "o", type: "User"}} end)
			+ {html_url: "https://example.test/\($p)\($i + 1)",
				created_at: "2026-10-28T00:\($o + $i | tostring | if length < 2 then "0" + . else . end):00Z"})
		| [.]'
}

# 250KB の本文
big_body() { head -c 250000 /dev/zero | tr '\0' x; }

# Windows の jq は CRLF で出すので、手元でも CI と同じに比べられるよう CR を落とす
out() { tr -d '\r' <"$FAKE/out" 2>/dev/null; }

run() {
	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r 7 >"$FAKE/out" 2>"$FAKE/err" || RC=$?
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
		sed 's/^/     | /' "$FAKE/out" "$FAKE/err"
	fi
}

main
