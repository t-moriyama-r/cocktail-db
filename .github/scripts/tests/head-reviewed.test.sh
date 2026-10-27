#!/usr/bin/env bash
#
# head-reviewed.sh のテスト(TC-216-25〜)。gh を、決まった JSON を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/head-reviewed.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../head-reviewed.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

HEAD=bbbbbbb
OLD=aaaaaaa
PUSHED=2026-10-25T10:00:00Z
BEFORE=2026-10-25T09:00:00Z
AFTER=2026-10-25T11:00:00Z

main() {
	setup_fake_gh

	check TC-216-25 'CodeRabbit の本文のあるレビューがいまのコミットにある' \
		"[$(review coderabbitai[bot] $HEAD 'Actionable comments posted: 1')]" '[]' true
	check TC-216-26 'CodeRabbit の本文の無いレビュー(スレッドへの返信)だけでは数えない' \
		"[$(review coderabbitai[bot] $HEAD '')]" '[]' false
	check TC-216-27 '前のコミットへのレビューは数えない' \
		"[$(review coderabbitai[bot] $OLD 'Actionable comments posted: 1')]" '[]' false
	check TC-216-28 '代わりの Claude のレビュー(目印あり)' \
		"[$(review github-actions[bot] $HEAD '<!-- ai-dev:agent --> <!-- ai-dev:claude-review --> 指摘 0 件')]" '[]' true
	check TC-216-29 'github-actions の目印の無いレビューは数えない' \
		"[$(review github-actions[bot] $HEAD 'something else')]" '[]' false
	check TC-216-30 'いまのコミットより後の「Review finished」' \
		'[]' "[$(comment coderabbitai[bot] $AFTER 'Review finished.')]" true
	check TC-216-31 'いまのコミットより前の「Review finished」は数えない' \
		'[]' "[$(comment coderabbitai[bot] $BEFORE 'Review finished.')]" false
	check TC-216-32 '上限のコメントはレビューではない' \
		'[]' "[$(comment coderabbitai[bot] $AFTER 'Review rate limited')]" false
	check TC-216-33 'CodeRabbit 以外の「Review finished」は数えない' \
		'[]' "[$(comment t-moriyama-r $AFTER 'Review finished.')]" false
	check TC-216-34 'レビューもコメントも無い' '[]' '[]' false

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# gh pr view・gh api(reviews / comments)に、$FAKE に置いた JSON を返す。--slurp の形(ページの配列)にそろえる
setup_fake_gh() {
	cat >"$FAKE/gh" <<-'EOF'
		#!/usr/bin/env bash
		case "$*" in
		  "pr view"*) cat "$FAKE_DIR/pr.json" ;;
		  *"/reviews"*) echo "[$(cat "$FAKE_DIR/reviews.json")]" ;;
		  *"/comments"*) echo "[$(cat "$FAKE_DIR/comments.json")]" ;;
		  *) echo "unexpected gh $*" >&2; exit 1 ;;
		esac
	EOF
	chmod +x "$FAKE/gh"
	jq -n --arg head "$HEAD" --arg pushed "$PUSHED" '{headRefOid: $head, commits: [{committedDate: $pushed}]}' >"$FAKE/pr.json"
}

review() { jq -nc --arg u "$1" --arg c "$2" --arg b "$3" '{user: {login: $u}, commit_id: $c, body: $b}'; }
comment() { jq -nc --arg u "$1" --arg t "$2" --arg b "$3" '{user: {login: $u}, created_at: $t, body: $b}'; }

check() {
	local id=$1 title=$2 reviews=$3 comments=$4 expected=$5 actual
	echo "$reviews" >"$FAKE/reviews.json"
	echo "$comments" >"$FAKE/comments.json"
	actual=$(PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" bash "$SCRIPT" o/r 1)
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$actual'"
	fi
}

main
