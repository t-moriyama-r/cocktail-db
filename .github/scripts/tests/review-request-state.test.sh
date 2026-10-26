#!/usr/bin/env bash
#
# review-request-state.sh のテスト(TC-227-01〜)。gh を、決まった JSON を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/review-request-state.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../review-request-state.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

HEAD=bbbbbbb
OWNER=t-moriyama-r
# 依頼を待つのは 30 分(WAIT_MINUTES の既定)。コミットは 2 時間前、それより前・後の時刻をいまから作る
ago() { date -u -d "-$1 minutes" +%Y-%m-%dT%H:%M:%SZ; }
PUSHED=$(ago 120)
BEFORE_PUSH=$(ago 180)
LONG_AGO=$(ago 40)
JUST_NOW=$(ago 5)
REPLY_LONG_AGO=$(ago 39)
REPLY_JUST_NOW=$(ago 4)

main() {
	setup_fake_gh

	check TC-227-01 'いまのコミットに CodeRabbit のレビューがある' \
		"[$(review coderabbitai[bot] $HEAD 'Actionable comments posted: 1')]" '[]' reviewed
	check TC-227-02 '依頼がまだ無い' '[]' '[]' request
	check TC-227-03 'ボットの依頼は数えない(CodeRabbit が応じない)' \
		'[]' "[$(comment claude[bot] "$LONG_AGO" '@coderabbitai review')]" request
	check TC-227-04 'いまのコミットより前のオーナーの依頼は数えない' \
		'[]' "[$(comment $OWNER "$BEFORE_PUSH" '@coderabbitai review')]" request
	check TC-227-05 '依頼のほかの文が付いたコメントは依頼として数えない' \
		'[]' "[$(comment $OWNER "$LONG_AGO" '@coderabbitai review の結果を待つ')]" request
	check TC-227-06 'オーナーの依頼に上限が返った' \
		'[]' "[$(comment $OWNER "$JUST_NOW" '@coderabbitai review'),$(comment coderabbitai[bot] "$REPLY_JUST_NOW" 'Review rate limited.')]" limited
	check TC-227-07 '上限の文言が Review limit reached のとき' \
		'[]' "[$(comment $OWNER "$JUST_NOW" '@coderabbitai review'),$(comment coderabbitai[bot] "$REPLY_JUST_NOW" 'Review limit reached')]" limited
	check TC-227-08 '依頼から 30 分たって返事が無い' \
		'[]' "[$(comment $OWNER "$LONG_AGO" '@coderabbitai review')]" unanswered
	check TC-227-09 '依頼を受けた返事はあるが、30 分たってもレビューが無い' \
		'[]' "[$(comment $OWNER "$LONG_AGO" '@coderabbitai review'),$(comment coderabbitai[bot] "$REPLY_LONG_AGO" 'Action performed')]" unanswered
	check TC-227-10 '依頼から 30 分たっていない' \
		'[]' "[$(comment $OWNER "$JUST_NOW" '@coderabbitai review')]" waiting
	check TC-227-11 '前の依頼への上限の返事は、後の依頼の返事として数えない' \
		'[]' "[$(comment $OWNER "$LONG_AGO" '@coderabbitai review'),$(comment coderabbitai[bot] "$REPLY_LONG_AGO" 'Review rate limited.'),$(comment $OWNER "$JUST_NOW" '@coderabbitai review')]" waiting
	check TC-227-12 '依頼の後に「Review finished」が付いた' \
		'[]' "[$(comment $OWNER "$LONG_AGO" '@coderabbitai review'),$(comment coderabbitai[bot] "$REPLY_LONG_AGO" 'Review finished.')]" reviewed

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
	actual=$(PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" OWNER=$OWNER bash "$SCRIPT" o/r 1)
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$actual'"
	fi
}

main
