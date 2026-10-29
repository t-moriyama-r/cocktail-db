#!/usr/bin/env bash
#
# head-reviewed.sh のテスト(TC-216-25〜・TC-355-01〜02・TC-479-01〜07)。gh を、決まった JSON を返す偽物に差し替えて流す。GitHub には触らない。
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
# 101 コミット以上の PR の 100 件目のコミットの時刻と、それより後・いまのコミットより前の時刻
COMMIT_100=2026-10-25T08:00:00Z
BETWEEN=2026-10-25T08:30:00Z

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

	set_pr_over_100_commits "$COMMIT_100"
	check TC-355-01 '101 コミット以上の PR: 100 件目より後・いまのコミットより前の「Review finished」は数えない' \
		'[]' "[$(comment coderabbitai[bot] $BETWEEN 'Review finished.')]" false
	check TC-355-02 '101 コミット以上の PR: いまのコミットより後の「Review finished」' \
		'[]' "[$(comment coderabbitai[bot] $AFTER 'Review finished.')]" true

	LOCAL='<!-- ai-dev:agent --> <!-- ai-dev:local-review --> 指摘 0 件'
	set_pr_base staging
	check TC-479-01 '昇格の PR: オーナーの目印付きのレビューがいまのコミットにある' 		"[$(review o $HEAD "$LOCAL")]" '[]' true
	check TC-479-02 '昇格の PR: 前のコミットへの目印付きのレビューは数えない' 		"[$(review o $OLD "$LOCAL")]" '[]' false
	check TC-479-03 '昇格の PR: オーナー以外の目印付きのレビューは数えない' 		"[$(review someone $HEAD "$LOCAL")]" '[]' false
	check TC-479-04 '昇格の PR: オーナーの目印の無いレビューは数えない' 		"[$(review o $HEAD 'LGTM')]" '[]' false
	check TC-479-05 '昇格の PR: CodeRabbit のレビュー・「Review finished」は数えない' 		"[$(review coderabbitai[bot] $HEAD 'Actionable comments posted: 1')]" "[$(comment coderabbitai[bot] $AFTER 'Review finished.')]" false
	set_pr_base release
	check TC-479-06 'release 向けの昇格の PR も、オーナーの目印付きのレビューで数える' 		"[$(review o $HEAD "$LOCAL")]" '[]' true
	set_pr_base develop
	check TC-479-07 'develop 向けの PR では、オーナーの目印付きのレビューを数えない' 		"[$(review o $HEAD "$LOCAL")]" '[]' false

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# gh pr view・gh api(reviews / comments)に、$FAKE に置いた JSON を返す。--slurp の形(ページの配列)にそろえる
setup_fake_gh() {
	cat >"$FAKE/gh" <<-'EOF'
		#!/usr/bin/env bash
		case "$*" in
		  "pr view"*) cat "$FAKE_DIR/pr.json" ;;
		  # いまのコミットの日時(gh api repos/o/r/commits/<sha> --jq .commit.committer.date)。置いていないコミットを聞かれたら失敗する
		  *"/commits/"*) sha=${2##*/}; jq -r .commit.committer.date "$FAKE_DIR/commit-$sha.json" ;;
		  *"/reviews"*) echo "[$(cat "$FAKE_DIR/reviews.json")]" ;;
		  *"/comments"*) echo "[$(cat "$FAKE_DIR/comments.json")]" ;;
		  *) echo "unexpected gh $*" >&2; exit 1 ;;
		esac
	EOF
	chmod +x "$FAKE/gh"
	jq -n --arg head "$HEAD" --arg pushed "$PUSHED" '{headRefOid: $head, commits: [{committedDate: $pushed}]}' >"$FAKE/pr.json"
	jq -n --arg pushed "$PUSHED" '{commit: {committer: {date: $pushed}}}' >"$FAKE/commit-$HEAD.json"
}

# 向き先を変えた PR
set_pr_base() {
	jq -n --arg head "$HEAD" --arg base "$1" '{headRefOid: $head, baseRefName: $base}' >"$FAKE/pr.json"
}

# 101 コミット以上の PR。--json commits は先頭 100 件までしか返さないので、その最後(100 件目)は、いまのコミットより前の時刻になる
set_pr_over_100_commits() {
	jq -n --arg head "$HEAD" --arg c100 "$1" '{headRefOid: $head, commits: (([range(99)] | map({committedDate: "2026-01-01T00:00:00Z"})) + [{committedDate: $c100}])}' >"$FAKE/pr.json"
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
