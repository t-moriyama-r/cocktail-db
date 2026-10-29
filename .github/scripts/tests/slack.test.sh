#!/usr/bin/env bash
#
# slack-post.sh・slack-mark-done.sh のテスト(TC-221-01〜)。curl と gh を、決まった JSON を返す偽物に差し替えて流す。
# Slack にも GitHub にも触らない。
#
# 使い方: .github/scripts/tests/slack.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
POST="$HERE/../slack-post.sh"
MARK="$HERE/../slack-mark-done.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

REPO=owner/app

main() {
	setup_fakes

	# --- slack-post.sh ---
	reset
	run_post bot '{"text":"hi","metadata":{"event_type":"ai_dev_ask"}}'
	expect TC-221-01 'bot があれば、chat.postMessage で SLACK_NOTIFY_USER に送り、メタデータを残す' \
		"$(sent chat.postMessage '[.channel, .text, .metadata.event_type] | join(",")')" 'U1,hi,ai_dev_ask'

	reset
	run_post webhook '{"text":"hi","metadata":{"event_type":"ai_dev_ask"}}'
	expect TC-221-02 'bot が無ければ Webhook に送り、メタデータを外す' \
		"$(sent hooks '[.text, (.metadata == null | tostring)] | join(",")')" 'hi,true'

	reset
	out=$(run_post none '{"text":"hi"}')
	expect TC-221-03 'どちらも無ければ、送らずに警告だけ出して成功で終わる' \
		"$?:$(calls | wc -l | tr -d ' '):$(grep -c '::warning::' <<<"$out")" '0:0:1'

	reset
	echo '{"ok":false,"error":"channel_not_found"}' >"$FAKE/res/chat.postMessage.json"
	run_post bot '{"text":"hi"}' >/dev/null
	expect TC-221-04 'Slack が ok: false を返したら失敗で終わる' "$?" 1

	# --- slack-mark-done.sh ---
	reset
	history "$(ask 100 7 needs-human)"
	issue 7 open '["ai-in-actions"]'
	run_mark >/dev/null
	expect TC-221-05 'ラベルが外れたら ✅ を付ける' "$(sent reactions.add '[.channel, .timestamp, .name] | join(",")')" 'D1,100,white_check_mark'
	expect TC-221-06 'ラベルが外れたら本文の先頭に【済】を足し、メタデータを done にする' \
		"$(sent chat.update '[.ts, .text, .metadata.event_type, .metadata.event_payload.number] | join(",")')" '100,【済】 ~ask 7~,ai_dev_done,7'

	reset
	history "$(ask 100 7 needs-human)"
	issue 7 open '["needs-human"]'
	run_mark >/dev/null
	expect TC-221-07 'ラベルが付いたまま開いていれば何もしない' "$(calls | grep -c -e reactions.add -e chat.update)" 0

	reset
	history "$(ask 100 7 needs-approval)"
	issue 7 closed '["needs-approval"]'
	run_mark >/dev/null
	expect TC-221-08 '閉じたら、ラベルが残っていても「済」を付ける' "$(sent chat.update .ts)" 100

	reset
	history "$(ask 100 7 needs-human),$(ask 200 7 needs-human)"
	issue 7 open '["needs-human"]'
	run_mark >/dev/null
	expect TC-221-09 '同じラベルで頼み直したら、前の通知にだけ「済」を付ける' "$(sent chat.update .ts | sort | paste -sd,)" 100

	reset
	history "$(ask 100 7 needs-human),$(ask 200 7 needs-decision)"
	issue 7 open '["needs-human","needs-decision"]'
	run_mark >/dev/null
	expect TC-221-10 '別のラベルの頼みは、頼み直しとみなさない' "$(calls | grep -c chat.update)" 0

	reset
	history "$(done_msg 100 7 needs-human),$(ask 150 8 needs-human repo/other)"
	issue 7 open '[]'
	run_mark >/dev/null
	expect TC-221-11 '「済」を付けた通知と、別のリポジトリの通知は見ない' "$(calls | grep -c -e 'gh api' -e chat.update)" 0

	reset
	history "$(ask 100 7 needs-human)"
	issue 7 open '[]'
	echo '{"ok":false,"error":"already_reacted"}' >"$FAKE/res/reactions.add.json"
	run_mark >/dev/null
	expect TC-221-12 '前の回でリアクションだけ付いていても、本文の書き換えまで進む' "$?:$(sent chat.update .ts)" '0:100'

	reset
	history "$(ask 100 7 needs-human)"
	issue 7 open '[]'
	run_mark none >/dev/null
	expect TC-221-13 'bot が無ければ、何もせず成功で終わる' "$?:$(calls | wc -l | tr -d ' ')" '0:0'

	reset
	history "$(ask 100 7 needs-human)"
	issue 7 open '[]'
	echo '{"ok":false,"error":"missing_scope"}' >"$FAKE/res/conversations.open.json"
	out=$(run_mark)
	expect TC-221-16 'DM を開けない(権限の不足)ときは、警告だけ出して成功で終わり、何も書かない' \
		"$?:$(calls | grep -c -e reactions.add -e chat.update):$(grep -c '::warning::' <<<"$out")" '0:0:1'

	reset
	issue 7 open '[]'
	echo '{"ok":false,"error":"missing_scope"}' >"$FAKE/res/conversations.history.json"
	out=$(run_mark)
	expect TC-221-17 '履歴を読めないときも、警告だけ出して成功で終わる' "$?:$(grep -c '::warning::' <<<"$out")" '0:1'

	reset
	history "$(ask 100 7 needs-human),$(ask 200 8 needs-human)"
	issue 7 open '[]'
	issue 8 open '[]'
	echo '{"ok":false,"error":"message_not_found"}' >"$FAKE/res/chat.update.json"
	run_mark >/dev/null
	expect TC-221-18 '1 件書き換えられなくても、ほかの通知へ進み、成功で終わる' "$?:$(sent chat.update .ts | paste -sd,)" '0:100,200'

	reset
	history "$(ask 100 7 needs-human),$(padding 200)"
	issue 7 open '[]'
	run_mark >/dev/null
	expect TC-422-01 '履歴の 1 ページが 1 引数の上限(128KB)を超えても、片付いた通知に「済」を付ける' "$(sent chat.update .ts)" 100

	reset
	echo '{"ok":true,"messages":"broken"}' >"$FAKE/res/conversations.history.json"
	out=$(run_mark)
	expect TC-422-02 '履歴を組み立てられないときは、「無かった」で済ませず、警告を出して成功で終わる' \
		"$?:$(grep -c '::warning::' <<<"$out"):$(grep -c '無かった' <<<"$out")" '0:1:0'

	reset
	history "$(ask 100 7 needs-human | with_text $'head\n\n*ask*\n&gt; line 1 \n&gt; \n<https://example.com|open>')"
	issue 7 open '[]'
	run_mark >/dev/null
	expect TC-491-01 '「済」を付けるとき、空の行を除く各行を打ち消し線にし、引用の行は印の後ろを打ち消す' \
		"$(sent chat.update .text)" "$(printf '%s\n' '【済】 ~head~' '' '~ask~' '&gt; ~line 1~' '&gt; ' '~<https://example.com|open>~')"

	reset
	history "$(done_msg 100 7 needs-human | with_text '【済】ask 7')"
	issue 7 open '["needs-human"]'
	run_mark >/dev/null
	expect TC-491-02 '前に「済」だけ付けた通知は、状態を見直さずに打ち消し線にする' \
		"$(calls | grep -c 'gh api'):$(sent chat.update '[.text, .metadata.event_type] | join(",")')" '0:【済】 ~ask 7~,ai_dev_done'

	reset
	history "$(done_msg 100 7 needs-human | with_text '【済】 ~ask 7~')"
	issue 7 open '[]'
	run_mark >/dev/null
	expect TC-491-03 '打ち消し線にした通知は、もう書き換えない' "$(calls | grep -c -e 'gh api' -e chat.update)" 0

	reset
	history "$(ask 100 7 needs-approval | with_text $':white_check_mark: *承認を待っています*(owner/app)\n*PR*: <https://example.com|#7 title>')"
	issue 7 closed '[]'
	run_mark >/dev/null
	expect TC-502-01 '見出しの前に空白を入れ、打ち消し線の中の太字の印を外す' \
		"$(sent chat.update .text)" "$(printf '%s\n' '【済】 ~:white_check_mark: 承認を待っています(owner/app)~' '~PR: <https://example.com|#7 title>~')"

	reset
	history "$(done_msg 100 7 needs-human | with_text $'【済】~:raising_hand: *ask*(owner/app)~\n\n~*PR*: <https://example.com|open>~\n&gt; ~line 1~')"
	issue 7 open '["needs-human"]'
	run_mark >/dev/null
	expect TC-502-02 '前の形(【済】~)で打ち消し線にした通知は、状態を見直さずに新しい形に書き直す' \
		"$(calls | grep -c 'gh api'):$(sent chat.update .text)" \
		"0:$(printf '%s\n' '【済】 ~:raising_hand: ask(owner/app)~' '' '~PR: <https://example.com|open>~' '&gt; ~line 1~')"

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# curl・gh を偽物にする。呼ばれた API と送った中身を $FAKE/calls・$FAKE/body.<呼んだ API> に残す
setup_fakes() {
	# 偽物の curl・gh が、置き場所を知るため
	export FAKE
	mkdir -p "$FAKE/bin"
	cat >"$FAKE/bin/curl" <<'SH'
#!/usr/bin/env bash
url=""; body=""
for a in "$@"; do case "$a" in https://*) url=$a ;; esac; done
if [[ " $* " == *" --data @- "* ]]; then body=$(cat); fi
case "$url" in
	https://hooks.*) method=hooks ;;
	*) method=${url##*/} ;;
esac
echo "curl $method" >>"$FAKE/calls"
[ -n "$body" ] && printf '%s\n' "$body" >>"$FAKE/body.$method"
if [ -f "$FAKE/res/$method.json" ]; then cat "$FAKE/res/$method.json"; else echo '{"ok":true}'; fi
SH
	cat >"$FAKE/bin/gh" <<'SH'
#!/usr/bin/env bash
echo "gh $*" >>"$FAKE/calls"
n=${2##*/}
cat "$FAKE/res/issue-$n.json"
SH
	chmod +x "$FAKE/bin/curl" "$FAKE/bin/gh"
}

reset() {
	rm -rf "$FAKE/res" "$FAKE/calls" "$FAKE"/body.*
	mkdir -p "$FAKE/res"
	touch "$FAKE/calls"
	echo '{"ok":true,"channel":{"id":"D1"}}' >"$FAKE/res/conversations.open.json"
	echo '{"ok":true,"channel":"D1","ts":"300"}' >"$FAKE/res/chat.postMessage.json"
}

# bot・webhook・none のどれで送るかを選んで slack-post.sh を流す
run_post() {
	local mode=$1 payload=$2 vars
	read -ra vars <<<"$(creds "$mode")"
	PATH="$FAKE/bin:$PATH" env "${vars[@]}" bash "$POST" <<<"$payload"
}

run_mark() {
	local mode=${1:-bot} vars
	read -ra vars <<<"$(creds "$mode")"
	PATH="$FAKE/bin:$PATH" REPO=$REPO GH_TOKEN=x env "${vars[@]}" bash "$MARK"
}

creds() {
	case "$1" in
		bot) echo SLACK_BOT_TOKEN=xoxb-test SLACK_NOTIFY_USER=U1 SLACK_WEBHOOK_URL=https://hooks.example/x ;;
		webhook) echo SLACK_BOT_TOKEN= SLACK_NOTIFY_USER=U1 SLACK_WEBHOOK_URL=https://hooks.example/x ;;
		none) echo SLACK_BOT_TOKEN= SLACK_NOTIFY_USER= SLACK_WEBHOOK_URL= ;;
	esac
}

# DM の履歴(conversations.history の応答)を置く。引数はメッセージの JSON をカンマでつないだもの
history() {
	echo "{\"ok\":true,\"messages\":[$1]}" >"$FAKE/res/conversations.history.json"
}

ask() {
	local ts=$1 number=$2 label=$3 repo=${4:-$REPO}
	echo "{\"ts\":\"$ts\",\"text\":\"ask $number\",\"metadata\":{\"event_type\":\"ai_dev_ask\",\"event_payload\":{\"repo\":\"$repo\",\"number\":\"$number\",\"label\":\"$label\"}}}"
}

done_msg() {
	ask "$@" | sed 's/"ai_dev_ask"/"ai_dev_done"/'
}

# 標準入力のメッセージの本文を差し替える
with_text() {
	jq -c --arg text "$1" '.text = $text'
}

# 「済」を付けた通知を、1 件 1KB の本文で n 件つないだもの(履歴を大きくするため)
padding() {
	local text i msgs=()
	text=$(printf 'x%.0s' {1..1000})
	for ((i = 1; i <= $1; i++)); do
		msgs+=("$(done_msg "$((1000 + i))" 9 needs-human | sed "s/\"ask 9\"/\"$text\"/")")
	done
	(IFS=,; echo "${msgs[*]}")
}

issue() {
	echo "{\"state\":\"$2\",\"labels\":$3}" >"$FAKE/res/issue-$1.json"
}

calls() {
	cat "$FAKE/calls"
}

# 呼んだ API に送った中身から、jq で取り出す
sent() {
	[ -f "$FAKE/body.$1" ] || return 0
	# Windows の jq は CRLF で出す
	jq -r "$2" "$FAKE/body.$1" | tr -d '\r'
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
	fi
}

main
