#!/usr/bin/env bash
#
# review-on-push.sh のテスト(TC-366-01〜)。gh と request-review.sh を偽物に差し替え、
# どの PR のレビューを頼んだかを記録して確かめる。GitHub には触らない。
#
# 使い方: .github/scripts/tests/review-on-push.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

SHA=aaaaaaa1111111111111111111111111111111111
OLD=bbbbbbb2222222222222222222222222222222222

main() {
	setup_fakes

	check TC-366-01 'CI が終わったコミットを head にした PR のレビューを頼む' \
		success "[$(pull 1 open "$SHA")]" '{"1": {}}' 0 'request-review 1'
	check TC-366-02 'CI の回が承認待ち(action_required)なら頼まない' \
		action_required "[$(pull 1 open "$SHA")]" '{"1": {}}' 0 ''
	check TC-366-03 'CI が赤でも頼む(レビューは CI の結果によらない)' \
		failure "[$(pull 1 open "$SHA")]" '{"1": {}}' 0 'request-review 1'
	check TC-366-04 '閉じた PR には頼まない' \
		success "[$(pull 1 closed "$SHA")]" '{"1": {}}' 0 ''
	check TC-366-05 '一覧で head が別のコミットの PR には頼まない' \
		success "[$(pull 1 open "$OLD")]" '{"1": {}}' 0 ''
	check TC-366-06 '頼む直前に head が進んでいれば頼まない' \
		success "[$(pull 1 open "$SHA")]" "{\"1\": {\"headRefOid\": \"$OLD\"}}" 0 ''
	check TC-366-07 'オーナー以外が作った PR には頼まない' \
		success "[$(pull 1 open "$SHA")]" '{"1": {"author": {"login": "someone"}}}' 0 ''
	check TC-366-08 'フォークからの PR には頼まない' \
		success "[$(pull 1 open "$SHA")]" '{"1": {"isCrossRepository": true}}' 0 ''
	check TC-366-09 '同じコミットを head にした PR が 2 つあれば、両方に頼む(develop の head を持つ昇格の PR など)' \
		success "[$(pull 1 open "$SHA"),$(pull 2 open "$SHA")]" '{"1": {}, "2": {}}' 0 'request-review 1
request-review 2'
	check TC-366-10 'PR の一覧を読めなければ失敗で終える(PR が無いと同じに扱わない)' \
		success 'FAIL' '{}' 1 ''
	check TC-366-11 '1 つの PR を読めなくても、ほかの PR には頼み、最後に失敗で終える' \
		success "[$(pull 1 open "$SHA"),$(pull 2 open "$SHA")]" '{"2": {}}' 1 'request-review 2'
	check TC-366-12 'request-review.sh が失敗したら失敗で終える' \
		success "[$(pull 1 open "$SHA")]" '{"1": {"request_fails": true}}' 1 'request-review 1'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

pull() {
	jq -cn --argjson n "$1" --arg state "$2" --arg sha "$3" '{number: $n, state: $state, head: {sha: $sha}}'
}

# review-on-push.sh を、偽物の request-review.sh と並べて置く。request-review.sh は呼ばれた PR を $FAKE/calls に書く
setup_fakes() {
	mkdir -p "$FAKE/scripts" "$FAKE/bin"
	cp "$HERE/../review-on-push.sh" "$FAKE/scripts/"
	cat >"$FAKE/scripts/request-review.sh" <<-'EOF'
		#!/usr/bin/env bash
		echo "request-review $2" >>"$FAKE_DIR/calls"
		[ "$(jq -r --arg n "$2" '.[$n].request_fails // false' "$FAKE_DIR/prs.json")" != true ]
	EOF
	cat >"$FAKE/bin/gh" <<-'EOF'
		#!/usr/bin/env bash
		case "$*" in
		  "api repos/o/r/commits/"*"/pulls"*)
		    [ "$(cat "$FAKE_DIR/pulls.json")" = FAIL ] && exit 1
		    cat "$FAKE_DIR/pulls.json" ;;
		  "pr view"*)
		    jq -e --arg n "$3" --arg sha "$FAKE_SHA" \
		      'if has($n) then {headRefOid: $sha, author: {login: "o"}, isCrossRepository: false} + .[$n] else error("no pr") end' \
		      "$FAKE_DIR/prs.json" 2>/dev/null ;;
		  *) echo "unexpected gh $*" >&2; exit 1 ;;
		esac
	EOF
	chmod +x "$FAKE/bin/gh"
}

check() {
	local id=$1 title=$2 conclusion=$3 pulls=$4 prs=$5 status=$6 expected=$7 actual code
	printf '%s' "$pulls" >"$FAKE/pulls.json"
	printf '%s' "$prs" >"$FAKE/prs.json"
	: >"$FAKE/calls"
	PATH="$FAKE/bin:$PATH" FAKE_DIR="$FAKE" FAKE_SHA=$SHA GH_TOKEN=github \
		bash "$FAKE/scripts/review-on-push.sh" o/r "$SHA" "$conclusion" >/dev/null 2>&1
	code=$?
	actual=$(cat "$FAKE/calls")
	if [ "$actual" = "$expected" ] && [ "$code" -eq "$status" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected' (exit $status), got '$actual' (exit $code)"
	fi
}

main
