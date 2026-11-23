#!/usr/bin/env bash
#
# workflow-run-pr.sh のテスト(TC-799-01〜)。gh を偽物に差し替え、CI の終わり(workflow_run)の回で
# どの PR の番号を出すかを確かめる。GitHub には触らない。
#
# 使い方: .github/scripts/tests/workflow-run-pr.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

SHA=aaaaaaa111111111111111111111111111111111
OLD=bbbbbbb222222222222222222222222222222222

main() {
	setup_fakes

	check TC-799-01 'コミットから開いた PR が見つかれば、その番号を出す(develop 向けの PR。ブランチの一覧は見ない)' \
		"[$(pull 10 open "$SHA")]" "[$(pull 20 open "$SHA")]" feature 0 '10' 'commits'
	check TC-799-02 'コミットからは閉じた PR だけのとき、head のブランチの開いた PR で head が同じもの(昇格の PR)を出す' \
		"[$(pull 794 closed "$OLD")]" "[$(pull 797 open "$SHA")]" develop 0 '797' 'commits
pulls state=open head=o:develop'
	check TC-799-03 'head のブランチの開いた PR でも、head が別のコミット(先へ進んだ)なら出さない' \
		"[$(pull 794 closed "$OLD")]" "[$(pull 797 open "$OLD")]" develop 0 '' 'commits
pulls state=open head=o:develop'
	check TC-799-04 'どちらにも無ければ何も出さず、成功で終える' \
		'[]' '[]' develop 0 '' 'commits
pulls state=open head=o:develop'
	check TC-799-05 '同じブランチに開いた PR が複数あれば、head が同じものを出す' \
		'[]' "[$(pull 801 open "$OLD"),$(pull 797 open "$SHA")]" develop 0 '797' 'commits
pulls state=open head=o:develop'
	check TC-799-06 'head のブランチが渡されなければ、コミットからだけ探す(今までの動き)' \
		"[$(pull 794 closed "$OLD")]" "[$(pull 797 open "$SHA")]" '' 0 '' 'commits'
	check TC-799-07 'コミットの PR の一覧を読めなければ失敗で終える(PR が無いと同じに扱わない)' \
		FAIL "[$(pull 797 open "$SHA")]" develop 1 '' 'commits'
	check TC-799-08 'ブランチの PR の一覧を読めなければ失敗で終える' \
		'[]' FAIL develop 1 '' 'commits
pulls state=open head=o:develop'
	check_bad_sha TC-799-09 'sha がコミットの SHA の形でなければ、GitHub を読まずに失敗で終える' '"] | .x'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

pull() {
	jq -cn --argjson n "$1" --arg state "$2" --arg sha "$3" '{number: $n, state: $state, head: {sha: $sha}}'
}

# gh の偽物。呼ばれた API を $FAKE/calls に書き、--jq の式は本物の jq で評価する
setup_fakes() {
	mkdir -p "$FAKE/bin"
	cat >"$FAKE/bin/gh" <<-'EOF'
		#!/usr/bin/env bash
		[ "$1" = api ] || { echo "unexpected gh $*" >&2; exit 1; }
		shift
		method=GET path="" query="" expr="."
		while [ $# -gt 0 ]; do
		  case "$1" in
		    -X) method=$2; shift 2 ;;
		    -f) query="$query ${2}"; shift 2 ;;
		    --jq) expr=$2; shift 2 ;;
		    *) path=$1; shift ;;
		  esac
		done
		[ "$method" = GET ] || { echo "unexpected method $method" >&2; exit 1; }
		case "$path" in
		  repos/o/r/commits/*/pulls)
		    echo commits >>"$FAKE_DIR/calls"
		    file=commits.json ;;
		  repos/o/r/pulls)
		    query=${query# }
		    echo "pulls ${query% per_page=100}" >>"$FAKE_DIR/calls"
		    file=pulls.json ;;
		  *) echo "unexpected path $path" >&2; exit 1 ;;
		esac
		[ "$(cat "$FAKE_DIR/$file")" = FAIL ] && { echo "HTTP 502" >&2; exit 1; }
		jq -r "$expr" "$FAKE_DIR/$file"
	EOF
	chmod +x "$FAKE/bin/gh"
}

# check <ID> <説明> <コミットの PR の一覧> <ブランチの PR の一覧> <head のブランチ> <期待する終了コード> <期待する出力> <期待する呼び出し>
check() {
	local id=$1 desc=$2 commits=$3 pulls=$4 branch=$5 want_status=$6 want_out=$7 want_calls=$8 out status calls
	rm -f "$FAKE/calls"
	: >"$FAKE/calls"
	printf '%s' "$commits" >"$FAKE/commits.json"
	printf '%s' "$pulls" >"$FAKE/pulls.json"
	out=$(FAKE_DIR="$FAKE" PATH="$FAKE/bin:$PATH" bash "$HERE/../workflow-run-pr.sh" o/r "$SHA" "$branch" 2>/dev/null)
	status=$?
	calls=$(cat "$FAKE/calls")
	if [ "$status" = "$want_status" ] && [ "$out" = "$want_out" ] && [ "$calls" = "$want_calls" ]; then
		echo "ok   $id: $desc"
		PASSED=$((PASSED + 1))
	else
		echo "FAIL $id: $desc"
		echo "  status: want $want_status, got $status"
		echo "  out:    want '$want_out', got '$out'"
		echo "  calls:  want '$want_calls', got '$calls'"
		FAILED=$((FAILED + 1))
	fi
}

check_bad_sha() {
	local id=$1 desc=$2 sha=$3 status calls
	: >"$FAKE/calls"
	FAKE_DIR="$FAKE" PATH="$FAKE/bin:$PATH" bash "$HERE/../workflow-run-pr.sh" o/r "$sha" develop >/dev/null 2>&1
	status=$?
	calls=$(cat "$FAKE/calls")
	if [ "$status" != 0 ] && [ -z "$calls" ]; then
		echo "ok   $id: $desc"
		PASSED=$((PASSED + 1))
	else
		echo "FAIL $id: $desc (status $status, calls '$calls')"
		FAILED=$((FAILED + 1))
	fi
}

main
