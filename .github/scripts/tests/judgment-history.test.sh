#!/usr/bin/env bash
#
# judgment-history.sh のテスト(TC-624-01〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/judgment-history.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../judgment-history.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

ASK='bot:<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> 表に行を足してよいか決めてほしい。
- A(おすすめ): 足す
- B: 足さない'
AUTO='bot:<!-- ai-dev:agent --> <!-- ai-dev:auto-judged rule=JC-03 --> 判断表の JC-03 のとおり、指摘どおり直した'
DONE='bot:<!-- ai-dev:agent --> 答えのとおり直した'

main() {
	setup_fake_gh

	# --- 問いと答えの対 ---

	reset
	comments "7:$ASK" '7:おすすめで'
	run
	expect TC-624-01 '「おすすめで」と答えた問いは recommended になり、件数に入る' "$(asks)|$(summary)" \
		"ask	https://example.test/c1	https://example.test/c2	recommended	表に行を足してよいか決めてほしい。|asks=1 answered=1 recommended=1 auto=0 auto_replied=0"

	reset
	comments "7:$ASK" '7:A で'
	run
	expect TC-624-02 'おすすめとした案の記号(A)で答えたら recommended' "$(verdicts)" 'recommended'

	reset
	comments "7:$ASK" '7:Bで'
	run
	expect TC-624-03 'おすすめと違う案(B)で答えたら other' "$(verdicts)|$(summary)" \
		'other|asks=1 answered=1 recommended=0 auto=0 auto_replied=0'

	reset
	comments "7:$ASK"
	run
	expect TC-624-04 '答えが無ければ none で、答えた数に入らない' "$(verdicts)|$(summary)" \
		'none|asks=1 answered=0 recommended=0 auto=0 auto_replied=0'

	reset
	comments "7:$ASK" '7:@coderabbitai review' '7:<!-- ai-dev:agent --> 手元のエージェントが証跡を貼った'
	run
	expect TC-624-05 'オーナーのアカウントで書いた自動の依頼・ai-dev の目印付きのコメントは答えに数えない' "$(verdicts)" 'none'

	reset
	comments '7:outsider:<!-- ai-dev:needs-human --> 偽の問い' '7:A'
	run
	expect TC-624-06 '書き込み権限の無い人が書いた目印は問いに数えない' "$(summary)" \
		'asks=0 answered=0 recommended=0 auto=0 auto_replied=0'

	reset
	comments "7:$ASK" "7:$ASK" '7:A'
	run
	expect TC-624-07 '次の問いの後に書いた答えは、前の問いの答えに数えない' "$(verdicts)" "$(printf 'none\nrecommended')"

	reset
	comments "7:$ASK" "8:$ASK" '7:A' '8:B'
	run
	expect TC-624-08 '答えは同じ issue・PR の中の問いとだけ対にする' "$(verdicts)" "$(printf 'recommended\nother')"

	reset
	comments '7:bot:<!-- ai-dev:agent --> <!-- ai-dev:needs-decision --> どちらにするか決めてほしい(おすすめ: B)' '7:B でよい'
	run
	expect TC-624-09 'needs-decision の問いも数え、「おすすめ: B」に B で答えたら recommended' "$(verdicts)" 'recommended'

	# --- 判断表で進めた回 ---

	reset
	comments "7:$AUTO" '7:それは直さないでほしい'
	run
	expect TC-624-10 '判断表で進めた後にオーナーの返信があれば、規則の ID と返信の URL を出す' "$(autos)|$(summary)" \
		"auto	https://example.test/c1	JC-03	https://example.test/c2|asks=0 answered=0 recommended=0 auto=1 auto_replied=1"

	reset
	comments "7:$AUTO" "7:$DONE" '7:別の話'
	run
	expect TC-624-11 '次のエージェントのコメントより後の返信は、判断表で進めた回への返信に数えない' "$(autos)" \
		"auto	https://example.test/c1	JC-03	-"

	# --- 期間・引数・失敗 ---

	reset
	comments "7:$ASK" '7:A'
	run 2026-10-29
	expect TC-624-12 '日付を渡すと、API に since を付け、その日より前のコメントは数えない' \
		"$(grep -c 'since=2026-10-29T00:00:00Z' "$FAKE/calls")|$(summary)" \
		'1|asks=0 answered=0 recommended=0 auto=0 auto_replied=0'

	reset
	comments "7:$ASK" '7:A'
	run 2026-10-28
	expect TC-624-13 'その日に書いたコメントは数える' "$(summary)" \
		'asks=1 answered=1 recommended=1 auto=0 auto_replied=0'

	reset
	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" >"$FAKE/out" 2>"$FAKE/err" || RC=$?
	expect TC-624-14 'リポジトリを渡さなければ失敗で終わる' "$RC" 2

	reset
	run 2026/10/28
	expect TC-624-15 '日付の形が違えば失敗で終わる' "$RC" 2

	reset
	touch "$FAKE/api-fails"
	run
	expect TC-624-16 'コメントを読めなかったら、0 件と取り違えずに失敗で終わる' "$(out)|$RC" '|1'

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
	"api --paginate --slurp repos/o/r/issues/comments?per_page=100"*) cat "$FAKE/comments" ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

reset() {
	rm -f "$FAKE"/calls "$FAKE"/api-fails "$FAKE"/out "$FAKE"/err
	: >"$FAKE/calls"
	comments
}

# リポジトリのコメント(2026-10-28 の 0 分から古い順に 1 分ずつ)。各引数は「<issue の番号>:<本文>」。
# 本文の先頭が bot: なら Actions(github-actions[bot])、outsider: なら書き込み権限の無い人、ほかはオーナー(o)が書いたもの。
# 本文は改行を含んでよい(NUL で区切って渡す)
comments() {
	if [ $# -eq 0 ]; then
		echo '[[]]' >"$FAKE/comments"
		return
	fi
	printf '%s\0' "$@" | jq -R -s -c 'split("\u0000") | map(select(. != "")) | to_entries
		| map(.key as $i | (.value | index(":")) as $p | (.value[0:$p]) as $n | .value[$p + 1:]
			| (if startswith("bot:") then {body: .[4:], user: {login: "github-actions[bot]", type: "Bot"}}
				elif startswith("outsider:") then {body: .[9:], user: {login: "someone", type: "User"}}
				else {body: ., user: {login: "o", type: "User"}} end)
			+ {html_url: "https://example.test/c\($i + 1)",
				issue_url: "https://api.example.test/repos/o/r/issues/\($n)",
				created_at: "2026-10-28T00:\($i | tostring | if length < 2 then "0" + . else . end):00Z"})
		| [.]' >"$FAKE/comments"
}

# Windows の jq は CRLF で出すので、手元でも CI と同じに比べられるよう CR を落とす
out() { tr -d '\r' <"$FAKE/out" 2>/dev/null; }
asks() { out | grep '^ask' ; }
autos() { out | grep '^auto' ; }
verdicts() { out | awk -F'\t' '$1 == "ask" { print $4 }'; }
summary() { out | grep '^summary' | cut -f2- | tr '\t' ' '; }

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
		echo "  expected: $expected"
		echo "  actual:   $actual"
		[ ! -s "$FAKE/err" ] || sed 's/^/  stderr:   /' "$FAKE/err"
	fi
}

main "$@"
