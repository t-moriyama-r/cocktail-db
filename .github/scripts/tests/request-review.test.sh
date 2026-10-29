#!/usr/bin/env bash
#
# request-review.sh のテスト(TC-227-13〜)。gh と review-request-state.sh を偽物に差し替え、
# どのトークンで何を呼んだかを記録して確かめる。GitHub には触らない。
#
# 使い方: .github/scripts/tests/request-review.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

main() {
	setup_fakes

	check TC-227-13 '依頼がまだ無ければ、PAT で CodeRabbit に頼む' \
		request pat '{}' 'pr-comment token=pat'
	check TC-227-14 'PAT が無ければ、代わりの Claude を呼ぶ(request-failed)' \
		request '' '{}' 'workflow ai-claude-review.yml reason=request-failed'
	check TC-227-15 'PAT で書けなければ、代わりの Claude を呼ぶ(request-failed)' \
		request pat '{"comment_fails": true}' 'pr-comment token=pat
workflow ai-claude-review.yml reason=request-failed'
	check TC-227-16 '上限が返っていれば、代わりの Claude を呼ぶ(limited)' \
		limited pat '{}' 'workflow ai-claude-review.yml reason=limited'
	check TC-227-17 '返事が無ければ、代わりの Claude を呼ぶ(no-response)' \
		unanswered pat '{}' 'workflow ai-claude-review.yml reason=no-response'
	check TC-227-18 'レビュー済みなら何もしない' reviewed pat '{}' ''
	check TC-227-19 '返事を待っているあいだは何もしない' waiting pat '{}' ''
	check TC-227-20 'draft は見送る' request pat '{"isDraft": true}' ''
	check TC-227-21 '人の番(needs-human)は見送る' request pat '{"labels": [{"name": "needs-human"}]}' ''
	check TC-456-06 '手元の番(needs-local)は見送る' request pat '{"labels": [{"name": "needs-local"}]}' ''
	check TC-496-08 '手元の番(needs-manual)は見送る' request pat '{"labels": [{"name": "needs-manual"}]}' ''
	check TC-227-22 '閉じた PR は見送る' request pat '{"state": "CLOSED"}' ''
	check TC-479-08 '昇格の PR(staging 向け)は見送る' request pat '{"baseRefName": "staging"}' ''
	check TC-479-09 '昇格の PR(release 向け)は見送る' request pat '{"baseRefName": "release"}' ''
	check TC-479-10 'develop 向けの PR は、これまでどおり CodeRabbit に頼む' request pat '{"baseRefName": "develop"}' 'pr-comment token=pat'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# request-review.sh を、偽物の review-request-state.sh と並べて置く。gh は呼ばれ方を $FAKE/calls に書く
setup_fakes() {
	mkdir -p "$FAKE/scripts" "$FAKE/bin"
	cp "$HERE/../request-review.sh" "$FAKE/scripts/"
	cat >"$FAKE/scripts/review-request-state.sh" <<-'EOF'
		#!/usr/bin/env bash
		echo "$FAKE_STATE"
	EOF
	cat >"$FAKE/bin/gh" <<-'EOF'
		#!/usr/bin/env bash
		case "$*" in
		  "pr view"*) cat "$FAKE_DIR/pr.json" ;;
		  "pr comment"*)
		    echo "pr-comment token=$GH_TOKEN" >>"$FAKE_DIR/calls"
		    [ "$(jq -r '.comment_fails // false' "$FAKE_DIR/pr.json")" = true ] && exit 1
		    exit 0 ;;
		  "workflow run"*)
		    echo "workflow $3 ${!#}" >>"$FAKE_DIR/calls" ;;
		  *) echo "unexpected gh $*" >&2; exit 1 ;;
		esac
	EOF
	chmod +x "$FAKE/bin/gh"
}

check() {
	local id=$1 title=$2 state=$3 token=$4 pr=$5 expected=$6 actual
	jq -n --argjson pr "$pr" '{state: "OPEN", isDraft: false, labels: [], baseRefName: "develop"} + $pr' >"$FAKE/pr.json"
	: >"$FAKE/calls"
	PATH="$FAKE/bin:$PATH" FAKE_DIR="$FAKE" FAKE_STATE=$state GH_TOKEN=github CODERABBIT_REQUEST_TOKEN=$token \
		bash "$FAKE/scripts/request-review.sh" o/r 1 >/dev/null
	actual=$(cat "$FAKE/calls")
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$actual'"
	fi
}

main
