#!/usr/bin/env bash
#
# notify-needs-human.yml の Slack の文面(message.jq・digest.jq)のテスト(TC-444-xx)。
# ワークフローの run に書いた jq のプログラムをそのまま取り出し、決まった入力で流す。Slack にも GitHub にも触らない。
#
# 使い方: .github/scripts/tests/notify-message.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
WORKFLOW="$HERE/../../workflows/notify-needs-human.yml"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0

main() {
	extract message.jq
	extract digest.jq

	local ask pr issue closing
	ask='[{"author_association":"OWNER","user":{"type":"User"},"body":"<!-- ai-dev:needs-human -->\nB で決めてほしい","html_url":"https://example.test/c/1"}]'
	pr='{"title":"【CI】issue-444: 通知の番号を消す","url":"https://example.test/pull/445","body":"Closes #444","kind":"PR"}'
	issue='{"title":"【CI】通知の番号を消す","url":"https://example.test/issues/444","body":"","kind":"issue"}'
	closing='[{"number":444,"title":"【CI】通知の番号を消す","url":"https://example.test/issues/444"}]'

	message 445 "$pr" "$ask" "$closing"
	expect TC-444-01 'PR の通知の見出しに、PR の番号(#445)を出さない' \
		"$(line '^\*PR\*: ')" '*PR*: <https://example.test/c/1|【CI】issue-444: 通知の番号を消す>'
	expect TC-444-02 'PR の通知の「対応 issue」は、issue の番号とタイトルのまま' \
		"$(line '^\*対応 issue\*: ')" '*対応 issue*: <https://example.test/issues/444|#444 【CI】通知の番号を消す>'

	message 444 "$issue" "$ask" '[]'
	expect TC-444-03 'issue の通知の見出しは、issue の番号を出したまま' \
		"$(line '^\*issue\*: ')" '*issue*: <https://example.test/c/1|#444 【CI】通知の番号を消す>'

	message 445 '{"title":"a & <b>","url":"https://example.test/pull/445","body":"","kind":"PR"}' "$ask" '[]'
	expect TC-444-04 'PR のタイトルの & < > は、番号を外してもエスケープする' \
		"$(line '^\*PR\*: ')" '*PR*: <https://example.test/c/1|a &amp; &lt;b&gt;>'

	digest \
		'{"number":445,"title":"【CI】issue-444: 通知の番号を消す","url":"https://example.test/pull/445","kind":"PR","label":"needs-human","ask":{"text":"","url":"https://example.test/pull/445"},"days":2}' \
		'{"number":444,"title":"【CI】通知の番号を消す","url":"https://example.test/issues/444","kind":"issue","label":"needs-human","ask":{"text":"","url":"https://example.test/issues/444"},"days":0}'
	expect TC-444-05 '毎朝のまとめの PR の行に、PR の番号を出さない' \
		"$(line '^• .*pull/445')" '• <https://example.test/pull/445|PR 【CI】issue-444: 通知の番号を消す>(2 日前から)'
	expect TC-444-06 '毎朝のまとめの issue の行は、issue の番号を出したまま' \
		"$(line '^• .*issues/444')" '• <https://example.test/issues/444|issue #444 【CI】通知の番号を消す>(1 日たっていない)'

	echo "passed: $PASSED, failed: $FAILED"
	[[ $FAILED -eq 0 ]]
}

# ワークフローの `cat > <名前> <<'JQ'` から `JQ` までを、run の字下げを外して取り出す
extract() {
	awk -v start="cat > $1 <<'JQ'" '
		index($0, start) { on = 1; indent = match($0, /[^ ]/) - 1; next }
		on && $0 ~ /^[ ]*JQ$/ { exit }
		on { print substr($0, indent + 1) }
	' "$WORKFLOW" >"$WORK/$1"
	if [[ ! -s "$WORK/$1" ]]; then
		echo "FAIL: $1 をワークフローから取り出せなかった"
		exit 1
	fi
}

# ワークフローと同じく、入力はファイルにして --slurpfile で渡す(message.jq は $target[0] などで受ける)
message() {
	printf '%s' "$2" >"$WORK/target.json"
	printf '%s' "$3" >"$WORK/comments.json"
	printf '%s' "$4" >"$WORK/issues.json"
	jq -n -r -f "$WORK/message.jq" \
		--arg repo owner/app --arg number "$1" --arg label needs-human --arg sender "" \
		--slurpfile target "$WORK/target.json" --slurpfile comments "$WORK/comments.json" --slurpfile issues "$WORK/issues.json" \
		| jq -r .text >"$WORK/out.txt"
}

digest() {
	printf '%s\n' "$@" >"$WORK/items.jsonl"
	jq -n -f "$WORK/digest.jq" --arg repo owner/app --arg labels "needs-human needs-decision needs-approval" \
		--slurpfile items "$WORK/items.jsonl" \
		| jq -r .text >"$WORK/out.txt"
}

line() {
	grep -E -m1 -e "$1" "$WORK/out.txt"
}

expect() {
	local id=$1 name=$2 actual=$3 expected=$4
	if [[ "$actual" == "$expected" ]]; then
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
