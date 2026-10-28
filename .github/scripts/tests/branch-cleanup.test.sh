#!/usr/bin/env bash
#
# branch-cleanup.sh のテスト(TC-463-01〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
# 向き先の付け替えとブランチの削除(GitHub への書き込み)そのものが仕様なので、偽物の gh が受けた書き込みとその順番を確かめる。
#
# 使い方: .github/scripts/tests/branch-cleanup.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../branch-cleanup.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

main() {
	setup_fake_gh

	reset
	stacked '8 9'
	check TC-463-01 '積み上げの PR は、マージした PR の向き先に付け替えてからブランチを消し、付け替えた番号を出す' \
		'exit 0|8 9|patch 8 base=develop,patch 9 base=develop,delete issue-5-base'

	reset
	check TC-463-02 'ほかに使っている PR が無ければ、ブランチを消すだけ(これまでどおり)' \
		'exit 0||delete issue-5-base'

	reset
	heads 11
	stacked 8
	check TC-463-03 'ほかの開いた PR が --head に使っていれば、付け替えも削除もしない(これまでどおり)' \
		'exit 0||'

	reset
	echo true >"$FAKE/protected"
	stacked 8
	check TC-463-04 '保護されたブランチは、付け替えも削除もしない(これまでどおり)' \
		'exit 0||'

	reset
	echo error >"$FAKE/protected"
	stacked 8
	check TC-463-05 '保護を読めなければ、保護されているものとして、付け替えも削除もしない' \
		'exit 0||'

	reset
	stacked '8 9'
	echo 8 >"$FAKE/patch-fail"
	check TC-463-06 '付け替えられない PR があれば、ブランチを消さない(消すとその PR が閉じられる)。付け替えた分は出す' \
		'exit 1|9|patch 8 base=develop,patch 9 base=develop'

	reset
	echo error >"$FAKE/stacked"
	check TC-463-07 '--base に使っている PR を読めなければ、無いと取り違えずに消さない' \
		'exit 1||'

	reset
	echo error >"$FAKE/heads"
	check TC-463-08 '--head に使っている PR を読めなければ、無いと取り違えずに消さない' \
		'exit 1||'

	reset
	stacked 8
	touch "$FAKE/delete-fail"
	check TC-463-09 '付け替えた後にブランチを消せなければ失敗を返し、付け替えた番号は出す' \
		'exit 1|8|patch 8 base=develop,delete issue-5-base'

	reset
	touch "$FAKE/pr-fail"
	check TC-463-10 'マージした PR を読めなければ、何もせずに失敗を返す' \
		'exit 1||'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# gh api repos/o/r/pulls/7: マージした PR(head は issue-5-base、base は develop)。$FAKE/pr-fail があれば失敗する
# gh api repos/o/r/branches/issue-5-base: $FAKE/protected を返す。error なら失敗する
# gh api --paginate repos/o/r/pulls?state=open&head=o:issue-5-base…: $FAKE/heads を返す。error なら失敗する
# gh api --paginate repos/o/r/pulls?state=open&base=issue-5-base…: $FAKE/stacked を返す。error なら失敗する
# gh api -X PATCH・-X DELETE: $FAKE/acted に残す。$FAKE/patch-fail の番号・$FAKE/delete-fail があれば失敗する
setup_fake_gh() {
	cat >"$FAKE/gh" <<-'EOF'
		#!/usr/bin/env bash
		answer() { [ "$(cat "$1")" != error ] || { echo "gh: Server Error (HTTP 500)" >&2; exit 1; }; cat "$1"; }
		[ "$1" = api ] || { echo "unexpected gh $*" >&2; exit 1; }
		shift
		case "$*" in
		  "repos/o/r/pulls/7 "*)
		    [ ! -e "$FAKE_DIR/pr-fail" ] || { echo "gh: Not Found (HTTP 404)" >&2; exit 1; }
		    echo "issue-5-base develop" ;;
		  "repos/o/r/branches/issue-5-base "*) answer "$FAKE_DIR/protected" ;;
		  "--paginate repos/o/r/pulls?state=open&head=o:issue-5-base&per_page=100 "*) answer "$FAKE_DIR/heads" ;;
		  "--paginate repos/o/r/pulls?state=open&base=issue-5-base&per_page=100 "*) answer "$FAKE_DIR/stacked" ;;
		  "-X PATCH repos/o/r/pulls/"*)
		    n=${3##*/}
		    echo "patch $n $5" >>"$FAKE_DIR/acted"
		    ! grep -qx "$n" "$FAKE_DIR/patch-fail" 2>/dev/null || { echo "gh: Validation Failed (HTTP 422)" >&2; exit 1; }
		    echo '{}' ;;
		  "-X DELETE repos/o/r/git/refs/heads/"*)
		    echo "delete ${3#repos/o/r/git/refs/heads/}" >>"$FAKE_DIR/acted"
		    [ ! -e "$FAKE_DIR/delete-fail" ] || { echo "gh: Reference does not exist (HTTP 422)" >&2; exit 1; } ;;
		  *) echo "unexpected gh api $*" >&2; exit 1 ;;
		esac
	EOF
	chmod +x "$FAKE/gh"
}

reset() {
	rm -f "$FAKE/patch-fail" "$FAKE/delete-fail" "$FAKE/pr-fail"
	: >"$FAKE/acted"
	echo false >"$FAKE/protected"
	: >"$FAKE/heads"
	: >"$FAKE/stacked"
}

heads() { tr ' ' '\n' <<<"$1" >"$FAKE/heads"; }
stacked() { tr ' ' '\n' <<<"$1" >"$FAKE/stacked"; }

# 終了コード・標準出力(付け替えた番号)・GitHub への書き込みの順番を「exit <コード>|<番号>|<書き込み>」にして返す。ログは $FAKE/err に残す
invoke() {
	local out code
	out=$(PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" bash "$SCRIPT" o/r 7 2>"$FAKE/err")
	code=$?
	echo "exit $code|$(paste -sd' ' - <<<"$out")|$(paste -sd, - <"$FAKE/acted")"
}

check() {
	local actual
	actual=$(invoke)
	if [ "$actual" = "$3" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $1 $2"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $1 $2: expected '$3', got '$actual'"
		sed 's/^/     /' "$FAKE/err"
	fi
}

main
