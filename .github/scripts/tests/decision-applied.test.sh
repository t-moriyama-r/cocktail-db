#!/usr/bin/env bash
#
# decision-applied.sh のテスト(TC-742-01〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/decision-applied.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../decision-applied.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

# きっかけのコメント(オーナーの返信)の時刻。コメントは 0 分から 1 分ずつ並ぶので、c3 から後が「きっかけより後」
SINCE=2026-11-13T00:02:00Z

main() {
	setup_fake_gh

	# --- 受け入れ条件のケース ---

	reset
	labels needs-decision
	comments '<!-- ai-dev:agent --> <!-- ai-dev:needs-decision --> 前の回の頼むコメント' 'A で'
	run
	expect TC-742-01 'ラベルが残り、きっかけより後にエージェントのコメントが無ければ not-applied(何もせずに終わった)' "$(out)|$RC" 'not-applied|0'

	reset
	labels area:ci
	comments 'A で' '<!-- ai-dev:agent --> 決まったことを本文に反映した: A にした'
	run
	expect TC-742-02 'needs-decision が外れていれば label-removed(判断を反映した)' "$(out)|$RC" 'label-removed|0'

	reset
	labels needs-decision area:ci
	comments 'x' 'y' 'A で(きっかけ)' '<!-- ai-dev:agent --> <!-- ai-dev:needs-decision --> 改めて決めてほしい'
	run
	expect TC-742-03 'ラベルが残っていても、きっかけより後にエージェントのコメントがあれば agent-commented(改めて判断を頼んだ)' "$(out)|$RC" 'agent-commented|0'

	# --- 境界と取り違え ---

	reset
	labels needs-decision
	comments 'x' 'y' 'bot:<!-- ai-dev:agent --> きっかけと同じ時刻'
	run
	expect TC-742-04 'きっかけと同じ時刻のコメントは「後」に数えない(境界)' "$(out)" 'not-applied'

	reset
	labels needs-decision
	comments '<!-- ai-dev:agent --> きっかけより前' 'A で' 'A で(きっかけ)' '続けて人が書いた'
	run
	expect TC-742-05 'エージェントのコメントがきっかけより前にしか無ければ not-applied' "$(out)" 'not-applied'

	reset
	labels needs-decision
	comments 'x' 'y' 'A で' 'outsider:<!-- ai-dev:agent --> 反映した'
	run
	expect TC-742-06 '書き込み権限の無い人が書いた ai-dev:agent は、エージェントのコメントに数えない' "$(out)" 'not-applied'

	reset
	labels needs-decision
	comments 'x' 'y' 'A で' 'bot:<!-- ai-dev:agent --> 決まったことを本文に反映した'
	run
	expect TC-742-07 'bot(Claude の GitHub App・Actions)が書いた ai-dev:agent のコメントは数える' "$(out)" 'agent-commented'

	reset
	labels needs-decision
	comments 'x' 'y' 'A で' 'bot:<!-- ai-dev:agent --> <!-- ai-dev:decision-not-applied --> 反映しなかった'
	run
	expect TC-742-08 '前の回の「反映しなかった」の知らせ(ai-dev:decision-not-applied)は数えない(Re-run で取り違えない)' "$(out)" 'not-applied'

	reset
	labels needs-decision-later
	comments 'A で'
	run
	expect TC-742-09 'needs-decision で始まる別の名前のラベルだけなら、needs-decision は外れているとみなす(名前の一部で取り違えない)' "$(out)" 'label-removed'

	reset
	labels needs-decision
	comments
	touch "$FAKE/comments-fail"
	run
	expect TC-742-10 'コメントを読めなかったら、not-applied と取り違えずに失敗で終わる' "$(out)|$((RC != 0))" '|1'

	reset
	labels needs-decision
	touch "$FAKE/issue-fail"
	run
	expect TC-742-11 'ラベルを読めなかったら、label-removed と取り違えずに失敗で終わる' "$(out)|$((RC != 0))" '|1'

	reset
	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r 7 >"$FAKE/out" 2>"$FAKE/err" || RC=$?
	expect TC-742-12 '引数が足りなければ失敗で終わる' "$RC" 2

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

setup_fake_gh() {
	mkdir -p "$FAKE/bin"
	cat >"$FAKE/bin/gh" <<'SH'
#!/usr/bin/env bash
echo "gh $*" >>"$FAKE/calls"
case "$*" in
	"api repos/o/r/issues/7 --jq "*)
		if [ -f "$FAKE/issue-fail" ]; then echo "HTTP 502: api failed" >&2; exit 1; fi
		jq -r "${*: -1}" "$FAKE/issue" ;;
	"api --paginate --slurp repos/o/r/issues/7/comments?since=$SINCE_EXPECT&per_page=100")
		if [ -f "$FAKE/comments-fail" ]; then echo "HTTP 502: api failed" >&2; exit 1; fi
		cat "$FAKE/comments" ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

reset() {
	rm -f "$FAKE"/calls "$FAKE"/comments-fail "$FAKE"/issue-fail "$FAKE"/out "$FAKE"/err
	: >"$FAKE/calls"
	SINCE_ARG=$SINCE
	labels
	comments
}

labels() {
	printf '%s\n' "$@" | jq -R -s -c 'split("\n") | map(select(. != "") | {name: .}) | {labels: .}' >"$FAKE/issue"
}

# issue のコメント(0 分から古い順に 1 分ずつ。--slurp なので、ページの配列を 1 つの配列に入れて返す)。既定はオーナー(o)が書いたもの。
# 先頭が bot: なら Claude の GitHub App、outsider: なら書き込み権限の無い人のコメント。
# 本物の API は since(更新の時刻)で絞るが、ここでは絞らずに全部返す(作った時刻で絞り直すことを確かめるため)
comments() {
	printf '%s\n' "$@" | jq -R -s -c 'split("\n") | map(select(. != "")) | to_entries
		| map(.key as $i | .value
			| (if startswith("bot:") then {body: .[4:], user: {login: "claude[bot]", type: "Bot"}}
				elif startswith("outsider:") then {body: .[9:], user: {login: "someone", type: "User"}}
				else {body: ., user: {login: "o", type: "User"}} end)
			+ {created_at: "2026-11-13T00:\($i | tostring | if length < 2 then "0" + . else . end):00Z"})
		| [.]' >"$FAKE/comments"
}

# Windows の jq は CRLF で出すので、手元でも CI と同じに比べられるよう CR を落とす
out() { tr -d '\r' <"$FAKE/out" 2>/dev/null; }

run() {
	RC=0
	SINCE_EXPECT=$SINCE_ARG PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r 7 "$SINCE_ARG" >"$FAKE/out" 2>"$FAKE/err" || RC=$?
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
