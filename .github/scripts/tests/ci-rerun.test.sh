#!/usr/bin/env bash
#
# ci-rerun.sh のテスト(TC-228-01〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/ci-rerun.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../ci-rerun.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

OLD=$(date -u -d '-2 hours' +%Y-%m-%dT%H:%M:%SZ)
NEW=$(date -u -d '-2 minutes' +%Y-%m-%dT%H:%M:%SZ)

main() {
	setup_fake_gh

	reset
	pr 7 develop false MERGEABLE
	runs 0
	run 7
	expect TC-228-01 '付け替わって CI が動いていない PR は、PR のブランチで test.yml を流す' "$(dispatched)" 'test.yml -R o/r --ref issue-7'

	reset
	pr 7 develop false MERGEABLE
	runs 1
	run 7
	expect TC-228-02 'いまのコミットで CI が動いた(動いている)なら流さない' "$(dispatched)$(commented)" ''

	reset
	pr 7 issue-5-base false MERGEABLE
	runs 0
	run 7
	expect TC-228-03 '向き先が既定のブランチでない(積んでいる途中)なら流さない' "$(dispatched)" ''

	reset
	pr 7 develop true MERGEABLE
	runs 0
	run 7
	expect TC-228-04 'フォークからの PR では流さない' "$(dispatched)" ''

	reset
	pr 7 develop false MERGEABLE CLOSED
	runs 0
	run 7
	expect TC-228-05 '閉じた PR では流さない' "$(dispatched)" ''

	reset
	pr 7 develop false MERGEABLE
	runs 0
	committed "$NEW"
	run 7
	expect TC-228-06 'push の直後(10 分たっていない)は流さない' "$(dispatched)" ''

	reset
	pr 7 develop false CONFLICTING
	runs 0
	run 7
	expect TC-228-07 'コンフリクトしていたら流さず、人にも頼まない(ai-conflict-resolve.yml が直す)' \
		"$(dispatched)$(commented)$(labeled)$(notified)" ''

	reset
	pr 7 develop false MERGEABLE
	runs 0
	echo 1 >"$FAKE/dispatch-fails"
	run 7
	expect TC-228-08 'test.yml を動かせなかったら、そのことを頼み、Slack に知らせる' \
		"$(commented | grep -c 'workflow_dispatch が無い')|$(notified | grep -c needs-human)" '1|1'

	reset
	pr 7 develop false MERGEABLE
	runs 0
	echo 1 >"$FAKE/dispatch-fails"
	echo '<!-- ai-dev:agent --> <!-- ai-dev:ci-rerun sha=sha7 -->' >"$FAKE/comments"
	run 7
	expect TC-228-09 '同じコミットでもう頼んでいたら、もう頼まない' "$(commented)$(labeled)$(notified)" ''

	reset
	pr 7 develop false MERGEABLE
	pr 8 develop false MERGEABLE
	runs 0
	echo "7 8" >"$FAKE/list"
	run
	expect TC-228-10 '番号なしなら、既定のブランチ向けの開いている PR をすべて見る' "$(dispatched | wc -l | tr -d ' ')" 2

	# grep -q に直接つなぐと、目印を見つけた時点で gh が SIGPIPE で止まり、pipefail で見落とす
	reset
	pr 7 develop false MERGEABLE
	runs 0
	echo 1 >"$FAKE/dispatch-fails"
	{ echo '<!-- ai-dev:ci-rerun sha=sha7 -->'; head -c 300000 /dev/zero | tr '\0' 'x'; echo; } >"$FAKE/comments"
	run 7
	expect TC-228-11 'コメントが大きくても、同じコミットで頼み直さない' "$(commented)" ''

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
	"api repos/o/r --jq .default_branch") echo develop ;;
	"pr list"*) tr ' ' '\n' <"$FAKE/list" ;;
	"pr view"*) cat "$FAKE/pr-$3.json" ;;
	"api repos/o/r/actions/workflows/"*) cat "$FAKE/runs" ;;
	"api repos/o/r/commits/"*) cat "$FAKE/committed" ;;
	"workflow run notify-needs-human.yml"*) shift 2; echo "$*" >>"$FAKE/notified" ;;
	"workflow run"*) [ -f "$FAKE/dispatch-fails" ] && exit 1; shift 2; echo "$*" >>"$FAKE/dispatched" ;;
	"pr edit"*) shift 2; echo "$*" >>"$FAKE/labeled" ;;
	"api --paginate"*) cat "$FAKE/comments" ;;
	"pr comment"*) printf '%s\n' "$*" >>"$FAKE/commented" ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

reset() {
	rm -f "$FAKE"/pr-*.json "$FAKE"/calls "$FAKE"/dispatched "$FAKE"/commented "$FAKE"/dispatch-fails "$FAKE"/labeled "$FAKE"/notified
	: >"$FAKE/comments"
	: >"$FAKE/calls"
	echo 7 >"$FAKE/list"
	committed "$OLD"
}

# PR の番号・向き先・フォークか・mergeable・状態
pr() {
	echo "{\"state\":\"${5:-OPEN}\",\"baseRefName\":\"$2\",\"headRefName\":\"issue-$1\",\"headRefOid\":\"sha$1\",\"isCrossRepository\":$3,\"mergeable\":\"$4\"}" >"$FAKE/pr-$1.json"
}

runs() { echo "$1" >"$FAKE/runs"; }
committed() { echo "$1" >"$FAKE/committed"; }
dispatched() { cat "$FAKE/dispatched" 2>/dev/null; }
commented() { cat "$FAKE/commented" 2>/dev/null; }
labeled() { cat "$FAKE/labeled" 2>/dev/null; }
notified() { cat "$FAKE/notified" 2>/dev/null; }

run() {
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r "$@" >"$FAKE/out" 2>&1
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
		sed 's/^/     | /' "$FAKE/out"
	fi
}

main
