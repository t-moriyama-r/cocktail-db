#!/usr/bin/env bash
#
# claude-idle.sh のテスト(TC-240-01〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/claude-idle.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../claude-idle.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

AGENT='<!-- ai-dev:agent --> 直した'
RETRIED='<!-- ai-dev:claude-idle-retry sha=sha7 --> 起こし直す'
ASKED='<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- ai-dev:claude-idle sha=sha7 --> 人に頼む'
SINCE=2026-10-25T15:00:00Z

main() {
	setup_fake_gh

	# --- review: Claude Code が動いて、仕事をした・することが無かった ---

	reset
	pr 7 ''
	threads "$AGENT"
	run review true
	expect TC-240-01 '動いて印も外れていれば、何もしない' "$(out)|$(edited)$(commented)|$RC" 'ok||0'

	reset
	pr 7 ai-in-actions
	threads "$AGENT"
	run review true
	expect TC-240-02 '動いて返信待ちのスレッドが無いのに印が残っていれば(#244)、印だけ外す' \
		"$(out)|$(edited)|$(commented)" 'cleared|7 -R o/r --remove-label ai-in-actions|'

	reset
	pr 7 ai-in-actions,needs-human
	threads '指摘'
	run review true
	expect TC-240-03 '動いて needs-human を付けていれば、返信待ちが残っていても印だけ外す(人の番)' \
		"$(out)|$(edited)|$(commented)" 'cleared|7 -R o/r --remove-label ai-in-actions|'

	reset
	pr 7 ai-in-actions CLOSED
	threads '指摘'
	run review true
	expect TC-240-04 '閉じた PR では、印だけ外して起こし直さない' "$(out)|$(edited)|$(commented)" 'cleared|7 -R o/r --remove-label ai-in-actions|'

	# --- review: 仕事をしていない → 1 回だけ起こし直す ---

	reset
	pr 7 ai-in-actions
	threads "$AGENT"
	run review false
	expect TC-240-05 '動かなかった回(ワークフローの検査で飛ばされた。#237)は、印を外し、目印を書いて起こし直す' \
		"$(out)|$(edited)|$(commented | grep -c '^<!-- ai-dev:claude-idle-retry sha=sha7 -->')" \
		'retry|7 -R o/r --remove-label ai-in-actions|1'

	reset
	pr 7 ai-in-actions
	threads "$AGENT" '指摘'
	run review true
	expect TC-240-06 '動いたのに返信待ちのスレッドが残って印も残った回(権限で止められた。#209)は、起こし直す' \
		"$(out)|$(edited)|$(commented | grep -c 'ai-dev:claude-idle-retry sha=sha7')" 'retry|7 -R o/r --remove-label ai-in-actions|1'

	reset
	pr 7 ai-in-actions
	run review false
	expect TC-240-07 '起こし直しの目印には ai-dev:agent を付けない(見送りの判定が「もう対応した」と取り違えないように)' \
		"$(commented | grep -c 'ai-dev:agent')" 0

	reset
	pr 7 ai-in-actions
	run review false
	expect TC-240-08 '印を外してから目印を書く(外せずに落ちたら、次の回でまた起こし直せるように)' \
		"$(grep -oE '^gh (pr edit|pr comment)' "$FAKE/calls" | paste -sd, -)" 'gh pr edit,gh pr comment'

	reset
	pr 7 ai-in-actions
	touch "$FAKE/edit-fails"
	run review false
	expect TC-240-09 '印を外せなかったら、目印を書かずに失敗で終わる' "$(commented)|$RC" '|1'

	reset
	pr 7 ai-in-actions OPEN sha8
	comments "bot:$RETRIED"
	run review false
	expect TC-240-10 '前のコミットで起こし直していても、新しいコミットではまた起こし直す' \
		"$(out)|$(commented | grep -c 'claude-idle-retry sha=sha8')" 'retry|1'

	reset
	pr 7 ai-in-actions
	comments "outsider:$RETRIED"
	run review false
	expect TC-240-11 '書き込み権限の無い人が目印を書いても、起こし直しを止めない' "$(out)" 'retry'

	# --- review: 起こし直しても仕事をしない → 人に頼む ---

	reset
	pr 7 ai-in-actions
	comments "bot:$RETRIED"
	run review false
	expect TC-240-12 '同じコミットでもう起こし直していれば、起こし直さず、印を外し、頼むコメントを書いて needs-human を付ける' \
		"$(out)|$(edited | paste -sd, -)|$(commented | grep -c '^<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- ai-dev:claude-idle sha=sha7 -->')|$(commented | grep -c '<!-- ai-dev:claude-idle-retry')" \
		'asked|7 -R o/r --remove-label ai-in-actions,7 -R o/r --add-label needs-human|1|0'

	reset
	pr 7 ai-in-actions
	comments "bot:$RETRIED"
	run review false
	expect TC-240-13 '頼むコメントの 1 行目(目印の次の行)は、何を決めてほしいかの 1 文' \
		"$(commented | sed -n 2p)" 'Actions の Claude Code が、起こし直してもレビューの指摘に対応しないまま終わった。原因を見て、どう進めるかを決めてほしい。'

	reset
	pr 7 ai-in-actions
	threads '指摘'
	comments "bot:$RETRIED" "$ASKED"
	run review true
	expect TC-240-14 '同じコミットでもう頼んでいれば、コメントを書き直さずに needs-human だけ付ける' \
		"$(out)|$(commented)|$(edited | paste -sd, -)" 'asked||7 -R o/r --remove-label ai-in-actions,7 -R o/r --add-label needs-human'

	# --- review: 読めないときは、無かったことにしない ---

	reset
	pr 7 ai-in-actions
	threads '指摘'
	touch "$FAKE/graphql-fails"
	run review true
	expect TC-240-15 'スレッドを読めなかったら、0 件と取り違えずに、何もせず失敗で終わる' "$(edited)$(commented)|$RC" '|1'

	reset
	pr 7 ai-in-actions
	touch "$FAKE/comments-fail"
	run review false
	expect TC-240-16 'コメントを読めなかったら、起こし直していないと取り違えずに失敗で終わる' "$(commented)|$RC" '|1'

	# --- resume: オーナーの返信の続き ---

	reset
	pr 7 ai-in-actions OPEN sha8
	run resume true sha7 "$SINCE"
	expect TC-240-17 'コミットが進んでいれば仕事をした。印は次のレビューで外すので残す' "$(out)|$(edited)$(commented)" 'ok|'

	reset
	pr 7 ''
	comments "2026-10-25T15:01:00Z|<!-- ai-dev:agent --> 承認は、マージでお願いします"
	run resume true sha7 "$SINCE"
	expect TC-240-18 '動き始めてからエージェントがコメントしていれば、仕事をした' "$(out)|$(edited)$(commented)" 'ok|'

	reset
	pr 7 ai-in-actions
	review_comments "2026-10-25T15:01:00Z|<!-- ai-dev:agent --> 決めたこと。理由: …"
	run resume true sha7 "$SINCE"
	expect TC-240-19 '動き始めてからエージェントがスレッドに返信していれば、仕事をした' "$(out)|$(edited)$(commented)" 'ok|'

	reset
	pr 7 ai-in-actions,needs-human
	comments "2026-10-25T15:01:00Z|<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> 聞き直す"
	run resume true sha7 "$SINCE"
	expect TC-240-20 'needs-human を付けて聞き直して終わったら、印を外す(人の番)' \
		"$(out)|$(edited)|$(commented)" 'cleared|7 -R o/r --remove-label ai-in-actions|'

	reset
	pr 7 ai-in-actions
	comments "2026-10-25T14:59:00Z|$AGENT"
	run resume true sha7 "$SINCE"
	expect TC-240-21 '動いたのにコミットもコメントも無ければ、印を外し、もう一度返信してほしいとコメントして needs-human を付ける' \
		"$(out)|$(edited | paste -sd, -)|$(commented | grep -c '^<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- ai-dev:claude-idle-resume sha=sha7 -->')" \
		'asked|7 -R o/r --remove-label ai-in-actions,7 -R o/r --add-label needs-human|1'

	reset
	pr 7 ai-in-actions
	comments "2026-10-25T15:01:00Z|$AGENT"
	run resume false sha7 "$SINCE"
	expect TC-240-22 'Claude Code が動かなかった回は、コメントがあっても仕事をしていない' "$(out)" 'asked'

	reset
	pr 7 ai-in-actions
	comments "outsider:2026-10-25T15:01:00Z|$AGENT"
	review_comments "outsider:2026-10-25T15:01:00Z|$AGENT"
	run resume true sha7 "$SINCE"
	expect TC-240-23 '書き込み権限の無い人が目印を書いても、仕事をしたとみなさない' "$(out)" 'asked'

	reset
	pr 7 ai-in-actions CLOSED
	run resume false sha7 "$SINCE"
	expect TC-240-24 '閉じた PR では、印だけ外して人に頼まない' "$(out)|$(edited)|$(commented)" 'cleared|7 -R o/r --remove-label ai-in-actions|'

	reset
	pr 7 ai-in-actions
	touch "$FAKE/comments-fail"
	run resume true sha7 "$SINCE"
	expect TC-240-27 'resume でコメントを読めなかったら、書いていないと取り違えず、何も変えずに失敗で終わる' "$(edited)$(commented)|$RC" '|1'

	reset
	run bogus true
	expect TC-240-25 '知らない使い方は失敗で終わる' "$RC" 2

	reset
	run review maybe
	expect TC-240-26 'ran が true・false でなければ失敗で終わる' "$RC" 2

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
	"pr view"*) cat "$FAKE/pr.json" ;;
	"api graphql"*)
		if [ -f "$FAKE/graphql-fails" ]; then echo "HTTP 502: graphql failed" >&2; exit 1; fi
		# --jq はページごとに当てる(unanswered-review-threads.sh が使う)
		expr=
		while [ $# -gt 0 ]; do
			if [ "$1" = --jq ]; then expr=$2; fi
			shift
		done
		while IFS= read -r line; do jq -c "$expr" <<<"$line"; done <"$FAKE/threads" ;;
	"api --paginate --slurp repos/o/r/issues/7/comments"*)
		if [ -f "$FAKE/comments-fail" ]; then echo "HTTP 502: comments failed" >&2; exit 1; fi
		cat "$FAKE/comments" ;;
	"api --paginate --slurp repos/o/r/pulls/7/comments"*) cat "$FAKE/review_comments" ;;
	"pr edit"*)
		if [ -f "$FAKE/edit-fails" ]; then echo "HTTP 502: edit failed" >&2; exit 1; fi
		shift 2; echo "$*" >>"$FAKE/edited" ;;
	"pr comment"*)
		# --body の中身だけを残す
		while [ $# -gt 0 ]; do
			if [ "$1" = --body ]; then printf '%s\n' "$2" >>"$FAKE/commented"; fi
			shift
		done ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

reset() {
	rm -f "$FAKE"/pr.json "$FAKE"/calls "$FAKE"/commented "$FAKE"/edited "$FAKE"/out "$FAKE"/err \
		"$FAKE"/graphql-fails "$FAKE"/comments-fail "$FAKE"/edit-fails
	: >"$FAKE/calls"
	threads
	comments
	review_comments
}

# PR の番号・付いているラベル(カンマ区切り)・状態・いまのコミット
pr() {
	jq -n --arg labels "${2:-}" --arg state "${3:-OPEN}" --arg sha "${4:-sha$1}" \
		'{state: $state, headRefOid: $sha, labels: ($labels | split(",") | map(select(. != "") | {name: .}))}' >"$FAKE/pr.json"
}

# CodeRabbit が立てた未解決のレビュースレッド。引数ごとに 1 つで、値はそのスレッドの最後のコメント。
# GraphQL の --paginate は、ページごとの JSON を続けて出すので、2 ページに分けて返す
threads() {
	local body nodes=()
	for body in "$@"; do
		nodes+=("$(jq -n -c --arg b "$body" \
			'{isResolved: false, first: {nodes: [{author: {login: "coderabbitai"}, body: "指摘"}]}, last: {nodes: [{body: $b}]}}')")
	done
	{
		page "${nodes[@]:0:1}"
		page "${nodes[@]:1}"
	} >"$FAKE/threads"
}
page() {
	printf '%s\n' "$@" | jq -s -c '{data: {repository: {pullRequest: {reviewThreads: {nodes: .}}}}}'
}

# PR のコメント(--slurp なので、ページの配列を 1 つの配列に入れて返す)。既定はオーナーが書いたもの。
# 先頭が bot: なら Actions(github-actions[bot])、outsider: なら書き込み権限の無い人。<時刻>| で書いた時刻を付けられる
comments() { to_comments "$@" >"$FAKE/comments"; }
review_comments() { to_comments "$@" >"$FAKE/review_comments"; }
to_comments() {
	printf '%s\n' "$@" | jq -R -s -c 'split("\n") | map(select(. != "")
		| (if startswith("bot:") then {who: "bot", rest: .[4:]} elif startswith("outsider:") then {who: "outsider", rest: .[9:]} else {who: "owner", rest: .} end)
		| (.rest | capture("^(?<at>[0-9TZ:-]+)\\|(?<b>.*)$") // {at: "2026-10-25T00:00:00Z", b: .}) as $p
		| {body: $p.b, created_at: $p.at,
		   author_association: (if .who == "owner" then "OWNER" else "NONE" end),
		   user: {type: (if .who == "bot" then "Bot" else "User" end)}}) | [.]'
}

commented() { cat "$FAKE/commented" 2>/dev/null; }
edited() { cat "$FAKE/edited" 2>/dev/null; }
out() { cat "$FAKE/out" 2>/dev/null; }

run() {
	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" "$1" o/r 7 "${@:2}" >"$FAKE/out" 2>"$FAKE/err" || RC=$?
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
