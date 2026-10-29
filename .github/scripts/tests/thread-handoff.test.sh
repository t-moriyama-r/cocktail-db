#!/usr/bin/env bash
#
# thread-handoff.sh のテスト(TC-388-01〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/thread-handoff.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../thread-handoff.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

AGENT='<!-- ai-dev:agent --> 直さない理由'
ASKED='<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- ai-dev:thread-handoff sha=sha7 -->'

main() {
	setup_fake_gh

	# --- check: 自動マージが、マージを止めているのが返信済みのスレッドだけかを見分ける ---

	reset
	threads "$AGENT" "$AGENT" resolved
	run check
	expect TC-388-01 '解決済みを除いた未解決の数と、そのうち返信待ちの数を出す' "$(out)" '2 0'

	reset
	threads "$AGENT" 'human:人が立てたスレッド' '<!-- ai-dev:agent --> <!-- ai-dev:claude-review --> 指摘' '人の返信が最後'
	run check
	expect TC-388-02 'AI レビュアーのスレッドで最後がエージェントの返信でないもの(代わりの Claude の指摘・人の返信)だけを返信待ちに数える' "$(out)" '4 2'

	reset
	run check
	expect TC-388-03 'スレッドが無ければ 0 0' "$(out)" '0 0'

	reset
	threads "$AGENT"
	touch "$FAKE/graphql-fails"
	run check
	expect TC-388-04 'スレッドを読めなかったら、0 件と取り違えずに失敗で終わる' "$(out)|$RC" '|1'

	# --- ask: 人の番にする ---

	reset
	pr 7
	threads "$AGENT" "$AGENT"
	run ask
	expect TC-388-05 '返信済みで未解決のスレッドだけなら、目印とリンク付きでコメントし、needs-human を付けて Slack に知らせる' \
		"$(commented | grep -c '<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- ai-dev:thread-handoff sha=sha7 -->')|$(commented | grep -c 'https://example.test/t')|$(labeled)|$(notified)" \
		'1|2|7 -R o/r --add-label needs-human|-R o/r -f number=7 -f label=needs-human'

	reset
	pr 7
	threads "$AGENT" "$AGENT"
	run ask
	expect TC-388-06 '頼むコメントは、ラベルより先に書く(Slack の通知がそのコメントを引用する)' \
		"$(grep -oE '^gh (pr comment|pr edit|workflow run)' "$FAKE/calls" | paste -sd, -)" 'gh pr comment,gh pr edit,gh workflow run'

	reset
	pr 7
	threads "$AGENT"
	run ask
	expect TC-453-01 'スレッドの URL の直後は空白にし、ファイル名の括弧を続けない(自動リンクが括弧まで拾って壊れる)' \
		"$(commented | grep -E '^- https://example\.test/t' | sed -E 's/t[0-9]+/tN/')" '- https://example.test/tN (`a.php`)'

	reset
	pr 7
	threads "$AGENT" "$AGENT"
	comments "$ASKED"
	run ask
	expect TC-388-07 '同じコミットでもう頼むコメントがあれば、書き直さない' "$(commented)" ''

	reset
	pr 7 needs-human
	threads "$AGENT"
	comments "$ASKED"
	run ask
	expect TC-388-08 '同じコミットで頼んでいて needs-human も付いていれば、何もしない' "$(commented)$(labeled)$(notified)" ''

	reset
	pr 7
	threads "$AGENT"
	comments "$ASKED"
	run ask
	expect TC-388-09 'コメントだけ書けて止まった回の後は、コメントを書き直さずにラベルと通知だけ足す' \
		"$(commented)|$(labeled)|$(notified)" '|7 -R o/r --add-label needs-human|-R o/r -f number=7 -f label=needs-human'

	reset
	pr 7 '' OPEN sha8
	threads "$AGENT"
	comments "$ASKED"
	run ask
	expect TC-388-10 '前のコミットで頼んでいても、新しいコミットではまた頼む' "$(commented | grep -c 'thread-handoff sha=sha8')" 1

	reset
	pr 7
	threads "$AGENT" '人が立てたスレッド'
	run ask
	expect TC-388-11 '返信待ちのスレッドがあれば頼まない(ai-review-loop.yml が対応する)' "$(commented)$(labeled)$(notified)" ''

	reset
	pr 7
	threads "$AGENT" 'human:人が立てたスレッド'
	run ask
	expect TC-388-27 '人が立てたスレッド(AI レビュアーが返す相手ではない)は人の番として頼む' \
		"$(commented | grep -c 'https://example.test/t')|$(labeled)" '2|7 -R o/r --add-label needs-human'

	reset
	pr 7
	run ask
	expect TC-388-12 '未解決のスレッドが無ければ頼まない' "$(commented)$(labeled)$(notified)" ''

	reset
	pr 7
	threads "$AGENT"
	touch "$FAKE/graphql-fails"
	run ask
	expect TC-388-13 'スレッドを読めなかったら、何もせず失敗で終わる' "$(commented)$(labeled)$(notified)|$RC" '|1'

	# --- release: 人がすべて解決したら戻す ---

	reset
	pr 7 needs-human
	threads resolved resolved
	comments "$ASKED"
	label_events 'github-actions[bot]'
	run release
	expect TC-388-14 'この理由で付けた needs-human は、すべて解決されたら外し、Slack の「済」と自動マージの判定を呼ぶ' \
		"$(labeled)|$(notified)|$(dispatched)" '7 -R o/r --remove-label needs-human|-R o/r -f label=done|ai-auto-merge.yml -R o/r -f number=7'

	reset
	pr 7 needs-human
	threads "$AGENT" resolved
	comments "$ASKED"
	label_events 'github-actions[bot]'
	run release
	expect TC-388-15 'まだ未解決のスレッドがあれば外さない' "$(labeled)$(notified)$(dispatched)" ''

	reset
	pr 7 needs-human
	threads resolved
	comments "$ASKED" '<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- ai-dev:ci-rerun sha=sha7 --> CI を流せない'
	label_events 'github-actions[bot]'
	run release
	expect TC-388-16 'いちばん新しい頼みごとがほかの理由(CI を流せない など)なら外さない' "$(labeled)$(notified)$(dispatched)" ''

	reset
	pr 7 needs-human
	threads resolved
	comments "$ASKED"
	label_events 'github-actions[bot]' owner
	run release
	expect TC-388-17 '頼んだ後に人が needs-human を付け直していたら外さない' "$(labeled)$(notified)$(dispatched)" ''

	reset
	pr 7 needs-human
	threads resolved
	comments '<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> 指摘の判断を頼む'
	label_events 'github-actions[bot]'
	run release
	expect TC-388-18 'この理由で頼んだことが無ければ外さない' "$(labeled)$(notified)$(dispatched)" ''

	reset
	pr 7
	threads resolved
	comments "$ASKED"
	run release
	expect TC-388-19 'needs-human が付いていなければ何もしない' "$(labeled)$(notified)$(dispatched)" ''

	reset
	pr 7 needs-human CLOSED
	threads resolved
	comments "$ASKED"
	label_events 'github-actions[bot]'
	run release
	expect TC-388-20 '閉じた PR では何もしない' "$(labeled)$(notified)$(dispatched)" ''

	reset
	pr 7 needs-human
	threads resolved
	comments "$ASKED"
	label_events 'github-actions[bot]'
	touch "$FAKE/graphql-fails"
	run release
	expect TC-388-21 'スレッドを読めなかったら、0 件と取り違えずに外さず、失敗で終わる' "$(labeled)$(dispatched)|$RC" '|1'

	reset
	pr 7 needs-human
	threads resolved
	comments "$ASKED"
	label_events 'github-actions[bot]'
	touch "$FAKE/done-fails"
	run release
	expect TC-388-22 'Slack の「済」を呼べなくても、自動マージの判定は呼ぶ' "$(dispatched)|$RC" 'ai-auto-merge.yml -R o/r -f number=7|0'

	reset
	run bogus
	expect TC-388-23 '知らない使い方は失敗で終わる' "$RC" 2

	# --- public のリポジトリなので、書き込み権限の無い人のコメントの目印は信じない ---

	reset
	pr 7 needs-human
	threads resolved
	comments 'bot:<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- ai-dev:ci-rerun sha=sha7 --> CI を流せない' "outsider:$ASKED"
	label_events 'github-actions[bot]'
	run release
	expect TC-388-24 'ほかの理由の頼みごとの後に、書き込み権限の無い人が目印を書いても外さない' "$(labeled)$(notified)$(dispatched)" ''

	reset
	pr 7
	threads "$AGENT"
	comments "outsider:$ASKED"
	run ask
	expect TC-388-25 '書き込み権限の無い人が同じコミットの目印を書いても、頼むのを止めない' \
		"$(commented | grep -c 'thread-handoff sha=sha7')|$(labeled)" '1|7 -R o/r --add-label needs-human'

	reset
	pr 7 needs-human
	threads resolved
	comments "bot:$ASKED"
	label_events 'github-actions[bot]'
	run release
	expect TC-388-26 'Actions(GitHub App)が書いた頼むコメントの目印は信じる' "$(dispatched)" 'ai-auto-merge.yml -R o/r -f number=7'

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
		if [ -n "$expr" ]; then
			while IFS= read -r line; do jq -c "$expr" <<<"$line"; done <"$FAKE/threads"
		else
			cat "$FAKE/threads"
		fi ;;
	"api --paginate --slurp repos/o/r/issues/7/comments"*) cat "$FAKE/comments" ;;
	"api --paginate --slurp repos/o/r/issues/7/events"*) cat "$FAKE/events" ;;
	"workflow run notify-needs-human.yml"*)
		if [ -f "$FAKE/done-fails" ]; then echo "HTTP 502: dispatch failed" >&2; exit 1; fi
		shift 3; echo "$*" >>"$FAKE/notified" ;;
	"workflow run"*) shift 2; echo "$*" >>"$FAKE/dispatched" ;;
	"pr edit"*) shift 2; echo "$*" >>"$FAKE/labeled" ;;
	"pr comment"*) printf '%s\n' "$*" >>"$FAKE/commented" ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

reset() {
	rm -f "$FAKE"/pr.json "$FAKE"/calls "$FAKE"/commented "$FAKE"/labeled "$FAKE"/notified "$FAKE"/dispatched \
		"$FAKE"/graphql-fails "$FAKE"/done-fails "$FAKE"/out
	: >"$FAKE/calls"
	threads
	comments
	label_events
}

# PR の番号・付いているラベル・状態・いまのコミット
pr() {
	local labels=
	if [ -n "${2:-}" ]; then labels="{\"name\":\"$2\"}"; fi
	jq -n --arg state "${3:-OPEN}" --arg sha "${4:-sha$1}" --argjson labels "[$labels]" \
		'{state: $state, headRefOid: $sha, labels: $labels}' >"$FAKE/pr.json"
}

# レビュースレッド。引数ごとに 1 つで、値はそのスレッドの最後のコメント(resolved なら解決済み)。
# 立てたのは CodeRabbit。先頭が human: なら人(オーナー)が立てたスレッド。
# GraphQL の --paginate は、ページごとの JSON を続けて出すので、2 ページに分けて返す
threads() {
	local i=0 body nodes=()
	for body in "$@"; do
		i=$((i + 1))
		if [ "$body" = resolved ]; then
			nodes+=("$(node_json "$i" true coderabbitai x)")
		elif [ "${body#human:}" != "$body" ]; then
			nodes+=("$(node_json "$i" false t-moriyama-r "${body#human:}")")
		else
			nodes+=("$(node_json "$i" false coderabbitai "$body")")
		fi
	done
	{
		page "${nodes[@]:0:1}"
		page "${nodes[@]:1}"
	} >"$FAKE/threads"
}
node_json() {
	jq -n -c --arg i "$1" --argjson resolved "$2" --arg author "$3" --arg b "$4" \
		'{isResolved: $resolved, path: "a.php",
		  first: {nodes: [{url: "https://example.test/t\($i)", author: {login: $author}, body: "指摘"}]},
		  last: {nodes: [{body: $b}]}}'
}
page() {
	printf '%s\n' "$@" | jq -s -c '{data: {repository: {pullRequest: {reviewThreads: {nodes: .}}}}}'
}

# PR のコメント(--slurp なので、ページの配列を 1 つの配列に入れて返す)。既定はオーナーが書いたもの。
# 先頭が bot: なら Actions(github-actions[bot])、outsider: なら書き込み権限の無い人のコメント
comments() {
	printf '%s\n' "$@" | jq -R -s -c 'split("\n") | map(select(. != "")
		| if startswith("bot:") then {body: .[4:], author_association: "NONE", user: {type: "Bot"}}
		  elif startswith("outsider:") then {body: .[9:], author_association: "NONE", user: {type: "User"}}
		  else {body: ., author_association: "OWNER", user: {type: "User"}} end) | [.]' >"$FAKE/comments"
}

# needs-human を付けた人(古い順)
label_events() {
	printf '%s\n' "$@" | jq -R -s -c 'split("\n") | map(select(. != "") | {event: "labeled", label: {name: "needs-human"}, actor: {login: .}}) | [.]' >"$FAKE/events"
}

commented() { cat "$FAKE/commented" 2>/dev/null; }
labeled() { cat "$FAKE/labeled" 2>/dev/null; }
notified() { cat "$FAKE/notified" 2>/dev/null; }
dispatched() { cat "$FAKE/dispatched" 2>/dev/null; }
out() { cat "$FAKE/out" 2>/dev/null; }

run() {
	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" "$1" o/r 7 >"$FAKE/out" 2>"$FAKE/err" || RC=$?
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
