#!/usr/bin/env bash
#
# conflict-candidates.sh のテスト(TC-232-01〜)。gh を、決まった JSON を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/conflict-candidates.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../conflict-candidates.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

HEAD=bbbbbbbbbbbb
BASE=dddddddddddd
NOW=$(date -u -d 2026-10-25T12:00:00Z +%s)
OLD=2026-10-25T10:00:00Z   # 120 分前

main() {
	setup_fake_gh

	check TC-232-01 'コンフリクトしている ready の PR を選ぶ' "$(pr)" '[]' 1
	check TC-410-01 'draft は選ばない' "$(pr '.isDraft = true')" '[]' ''
	check TC-232-03 'needs-approval(人のマージ待ち)も選ぶ' "$(pr '.labels = [{name: "needs-approval"}]')" '[]' 1
	check TC-232-04 'コンフリクトしていない' "$(pr '.mergeable = "MERGEABLE"')" '[]' ''
	check_unknown TC-232-05 'UNKNOWN の間は取り直し、CONFLICTING になれば選ぶ'
	check TC-232-06 '閉じている' "$(pr '.state = "CLOSED"')" '[]' ''
	check TC-232-07 'フォークから' "$(pr '.isCrossRepository = true')" '[]' ''
	check TC-232-08 'オーナー以外が作った' "$(pr '.author.login = "someone"')" '[]' ''
	check TC-232-09 '向き先が develop でない(積んでいる途中)' "$(pr '.baseRefName = "issue-1-base"')" '[]' ''
	check TC-232-10 'needs-human が付いている' "$(pr '.labels = [{name: "needs-human"}]')" '[]' ''
	check TC-232-11 'needs-decision が付いている' "$(pr '.labels = [{name: "needs-decision"}]')" '[]' ''
	check TC-232-12 'ai-in-actions が付いている(Actions が対応中)' "$(pr '.labels = [{name: "ai-in-actions"}]')" '[]' ''
	check TC-232-13 '最後のコミットから 59 分' "$(pr)" '[]' '' 2026-10-25T11:01:00Z
	check TC-232-14 '最後のコミットから 60 分ちょうど' "$(pr)" '[]' 1 2026-10-25T11:00:00Z
	check TC-232-15 '同じコミットと develop の組をもう試した' "$(pr)" "[$(comment Bot "<!-- ai-dev:agent --> <!-- ai-dev:conflict-resolve sha=$HEAD base=$BASE -->")]" ''
	check TC-232-16 'develop が進んだら試し直す' "$(pr)" "[$(comment Bot "<!-- ai-dev:conflict-resolve sha=$HEAD base=cccccccccccc -->")]" 1
	check TC-232-17 '人の書いた目印は数えない' "$(pr)" "[$(comment User "<!-- ai-dev:conflict-resolve sha=$HEAD base=$BASE -->")]" 1
	check TC-232-18 '目印の後に大きなコメントがあっても見落とさない' "$(pr)" \
		"[$(comment Bot "<!-- ai-dev:conflict-resolve sha=$HEAD base=$BASE -->"), $(jq -nc '{user: {type: "Bot"}, body: ("x" * 300000)}')]" ''
	check_all TC-232-19 '番号なしなら開いている PR をすべて見て、コンフリクトしている ready のものだけを選ぶ'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# gh api・gh pr view・gh pr list に、$FAKE_DIR に置いた JSON を返す。--jq は本物の jq で当てる
# 外の jq の出力から \r を落とす。Windows の jq は CRLF で出し、Git Bash のコマンド置換は最後の行の \r しか落とさないので、
# 番号が 1\r のようになって pr-1\r.json を探してしまう(#362)。本物の gh の --jq は Windows でも LF で出す
setup_fake_gh() {
	cat >"$FAKE/gh" <<-'EOF'
		#!/usr/bin/env bash
		# tr にパイプしても、jq が失敗したら gh も失敗で返す(ファイルが無いときなど)
		set -o pipefail
		jq_expr=.
		args=("$@")
		for i in "${!args[@]}"; do [ "${args[$i]}" = --jq ] && jq_expr=${args[$((i + 1))]}; done
		case "$*" in
		  "api repos/o/r --jq"*) echo develop ;;
		  "api repos/o/r/branches/develop"*) echo "$FAKE_BASE" ;;
		  "pr list"*) jq -r "$jq_expr" "$FAKE_DIR/list.json" | tr -d '\r' ;;
		  "pr view"*)
		    n=$3
		    # pr-<番号>.json.<回数> があれば、呼ばれた回数ごとに返すものを変える(UNKNOWN から変わる場合)
		    count=$(($(cat "$FAKE_DIR/count-$n" 2>/dev/null || echo 0) + 1))
		    echo "$count" >"$FAKE_DIR/count-$n"
		    if [ -f "$FAKE_DIR/pr-$n.json.$count" ]; then cat "$FAKE_DIR/pr-$n.json.$count"; else cat "$FAKE_DIR/pr-$n.json"; fi ;;
		  *"/commits/"*) jq -r "$jq_expr" "$FAKE_DIR/commit.json" | tr -d '\r' ;;
		  *"/comments"*) jq -r "$jq_expr" "$FAKE_DIR/comments-$(sed -E 's#.*/issues/([0-9]+)/.*#\1#' <<<"$*").json" | tr -d '\r' ;;
		  *) echo "unexpected gh $*" >&2; exit 1 ;;
		esac
	EOF
	chmod +x "$FAKE/gh"
}

# コンフリクトしている、オーナーの、develop 向けの PR。jq の式で変える
pr() {
	jq -nc --arg head "$HEAD" '{state: "OPEN", isDraft: false, isCrossRepository: false, author: {login: "o"}, baseRefName: "develop",
		headRefName: "issue-1-x", headRefOid: $head, labels: [], mergeable: "CONFLICTING"}' | jq -c "${1:-.}"
}
comment() { jq -nc --arg t "$1" --arg b "$2" '{user: {type: $t}, body: $b}'; }

reset() {
	rm -f "$FAKE"/*.json "$FAKE"/*.json.* "$FAKE"/count-*
	jq -nc --arg d "${1:-$OLD}" '{commit: {committer: {date: $d}}}' >"$FAKE/commit.json"
}

run() { PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" FAKE_BASE="$BASE" NOW="$NOW" UNKNOWN_RETRY_SECONDS=0 bash "$SCRIPT" o/r "$@" 2>"$FAKE/stderr"; }

# expected: 選ばれる PR の番号(カンマ区切り)。空なら選ばれない
check() {
	local id=$1 title=$2 json=$3 comments=$4 expected=$5 committed=${6:-$OLD}
	reset "$committed"
	echo "$json" >"$FAKE/pr-1.json"
	echo "$comments" >"$FAKE/comments-1.json"
	assert "$id" "$title" "$expected" "$(run 1)"
}

check_unknown() {
	reset
	pr '.mergeable = "UNKNOWN"' >"$FAKE/pr-1.json.1"
	pr '.mergeable = "UNKNOWN"' >"$FAKE/pr-1.json.2"
	pr >"$FAKE/pr-1.json"
	echo '[]' >"$FAKE/comments-1.json"
	assert "$1" "$2" 1 "$(run 1)"
}

check_all() {
	reset
	echo '[{"number": 1}, {"number": 2}, {"number": 3}]' >"$FAKE/list.json"
	pr >"$FAKE/pr-1.json"
	pr '.mergeable = "MERGEABLE"' >"$FAKE/pr-2.json"
	pr '.isDraft = true' >"$FAKE/pr-3.json"
	for n in 1 2 3; do echo '[]' >"$FAKE/comments-$n.json"; done
	assert "$1" "$2" 1 "$(run)"
}

assert() {
	local id=$1 title=$2 expected=$3 out=$4 actual want
	actual=$(jq -r 'map(.number) | join(",")' <<<"$out" 2>/dev/null) || actual="(JSON でない: $out)"
	want=$(jq -nc --arg head "$HEAD" --arg base "$BASE" '{head: $head, branch: "issue-1-x", base: $base}')
	# 選んだときは、取り込みに要るもの(コミット・ブランチ・develop のコミット)がそろっていることも見る
	if [ "$actual" = "$expected" ] && { [ -z "$expected" ] || jq -e --argjson w "$want" 'all(.[]; del(.number) == $w)' <<<"$out" >/dev/null; }; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$out'"
	fi
}

main
