#!/usr/bin/env bash
#
# ci-rerun-request.sh のテスト(TC-268-01〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
# ワークフローを呼ぶこと(外への送信)そのものが仕様なので、偽物の gh が受けた workflow run の引数を確かめる。
#
# 使い方: .github/scripts/tests/ci-rerun-request.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../ci-rerun-request.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

main() {
	setup_fake_gh

	check TC-268-01 '既定のブランチ向けで ci-rerun.yml が動くなら、番号を付けて流し直しを頼む' \
		develop active ok 'exit 0|ci-rerun.yml -R o/r -f number=7'
	check TC-268-02 'ci-rerun.yml が無いリポジトリでは、頼まずにログだけ(失敗にしない)' \
		develop 404 ok 'exit 0|'
	check TC-268-03 'ci-rerun.yml が止めてあるなら、頼まずにログだけ' \
		develop disabled_manually ok 'exit 0|'
	check TC-268-04 '向き先が既定のブランチでない(昇格の PR・積んでいる途中)なら頼まない' \
		staging active ok 'exit 0|'
	check TC-268-05 'ci-rerun.yml があるかを読めない(404 以外)なら、無いと取り違えずに失敗を返す' \
		develop 500 ok 'exit 1|'
	check TC-268-06 'ci-rerun.yml を呼べなかったら失敗を返す' \
		develop active fail 'exit 1|ci-rerun.yml -R o/r -f number=7'
	check_log TC-268-07 '頼まなかったときも、理由をログに出す' \
		develop 404 ok 'ci-rerun.yml が無いので'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# gh api repos/o/r/actions/workflows/ci-rerun.yml: $FAKE_DIR/state が数字なら HTTP エラー、そうでなければ state を返す
# gh workflow run: 引数を $FAKE_DIR/dispatched に残す。$FAKE_DIR/dispatch が fail なら失敗する
setup_fake_gh() {
	cat >"$FAKE/gh" <<-'EOF'
		#!/usr/bin/env bash
		case "$1 $2" in
		  "api repos/o/r/actions/workflows/ci-rerun.yml")
		    state=$(cat "$FAKE_DIR/state")
		    case "$state" in
		      404) echo "gh: Not Found (HTTP 404)" >&2; exit 1 ;;
		      [0-9]*) echo "gh: Server Error (HTTP $state)" >&2; exit 1 ;;
		      *) echo "$state" ;;
		    esac ;;
		  "workflow run")
		    shift 2
		    echo "$*" >>"$FAKE_DIR/dispatched"
		    [ "$(cat "$FAKE_DIR/dispatch")" = ok ] || { echo "could not create workflow dispatch event: HTTP 403" >&2; exit 1; } ;;
		  *) echo "unexpected gh $*" >&2; exit 1 ;;
		esac
	EOF
	chmod +x "$FAKE/gh"
}

# 終了コードと、呼んだワークフローの引数を「exit <コード>|<引数>」にして返す。ログは $FAKE/out に残す
invoke() {
	local base=$1 state=$2 dispatch=$3 code
	rm -f "$FAKE/dispatched"
	echo "$state" >"$FAKE/state"
	echo "$dispatch" >"$FAKE/dispatch"
	PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" bash "$SCRIPT" o/r 7 "$base" develop >"$FAKE/out" 2>&1
	code=$?
	echo "exit $code|$(cat "$FAKE/dispatched" 2>/dev/null)"
}

check() {
	local actual
	actual=$(invoke "$3" "$4" "$5")
	report "$1" "$2" "$6" "$actual"
}

check_log() {
	local id=$1 title=$2 needle=$6 actual
	invoke "$3" "$4" "$5" >/dev/null
	if grep -qF "$needle" "$FAKE/out"; then actual=$needle; else actual=$(cat "$FAKE/out"); fi
	report "$id" "$title" "$needle" "$actual"
}

report() {
	if [ "$4" = "$3" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $1 $2"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $1 $2: expected '$3', got '$4'"
	fi
}

main
