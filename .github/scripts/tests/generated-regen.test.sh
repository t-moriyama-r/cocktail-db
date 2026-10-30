#!/usr/bin/env bash
#
# generated-regen.sh のテスト(TC-495-xx)。一時ディレクトリに git のリポジトリを作って流す。
# build は composer・php・npm(プロセスの外のコマンド)を偽物にして、流れた順と失敗の伝わり方を確かめる。
#
# 使い方: .github/scripts/tests/generated-regen.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../generated-regen.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0
OUTPUT=""
ERR=""
STATUS=0

main() {
	tc_01_no_marker
	tc_02_marker_written
	tc_03_marker_by_other
	tc_04_marker_before_run
	tc_05_head_differs
	tc_06_collect_changed
	tc_07_collect_unchanged
	tc_08_check_moved
	tc_09_check_failed
	tc_10_check_unchanged
	tc_11_check_push
	tc_12_check_not_generated
	tc_13_check_symlink_file
	tc_14_check_symlink_dir
	tc_15_check_no_result
	tc_16_build_runs_in_order
	tc_17_build_fails
	tc_18_usage
	echo "passed: $PASSED, failed: $FAILED"
	[[ $FAILED -eq 0 ]]
}

# --- ケース -----------------------------------------------------------------

# request に渡すコメントの JSON を作る(1 件ずつ「書いた人<TAB>時刻<TAB>本文」)
comments() {
	local out=$1 line
	shift
	for line in "$@"; do
		IFS=$'\t' read -r login at body <<<"$line"
		jq -n --arg l "$login" --arg a "$at" --arg b "$body" '{user: {login: $l}, created_at: $a, body: $b}'
	done | jq -s . > "$out"
}

HEAD_A=1111111111111111111111111111111111111111
HEAD_B=2222222222222222222222222222222222222222
STARTED=2026-10-30T10:00:00Z

tc_01_no_marker() {
	comments "$WORK/c1.json" $'claude[bot]\t2026-10-30T10:05:00Z\t<!-- ai-dev:agent --> 指摘に対応した'
	run_in "$WORK" request "$WORK/c1.json" "$STARTED" "$HEAD_A"
	expect "TC-495-01: 目印が無ければ何も出さない" test -z "$OUTPUT" -a "$STATUS" -eq 0
}

tc_02_marker_written() {
	comments "$WORK/c2.json" $'claude[bot]\t2026-10-30T10:05:00Z\t<!-- ai-dev:agent --> <!-- ai-dev:regenerate head='"$HEAD_A"$' --> 生成ファイルの作り直しを後のジョブに頼んだ'
	run_in "$WORK" request "$WORK/c2.json" "$STARTED" "$HEAD_A"
	expect "TC-495-02: この回に Claude Code が書いた目印の head が PR の head と同じなら、その head を出す" test "$OUTPUT" = "$HEAD_A"
}

tc_03_marker_by_other() {
	comments "$WORK/c3.json" $'someone\t2026-10-30T10:05:00Z\t<!-- ai-dev:regenerate head='"$HEAD_A"$' -->'
	run_in "$WORK" request "$WORK/c3.json" "$STARTED" "$HEAD_A"
	expect "TC-495-03: Claude Code 以外が書いた目印では作り直さない" test -z "$OUTPUT"
}

tc_04_marker_before_run() {
	comments "$WORK/c4.json" $'claude[bot]\t2026-10-30T09:55:00Z\t<!-- ai-dev:regenerate head='"$HEAD_A"$' -->'
	run_in "$WORK" request "$WORK/c4.json" "$STARTED" "$HEAD_A"
	expect "TC-495-04: この回より前に書いた目印では作り直さない" test -z "$OUTPUT"
}

tc_05_head_differs() {
	comments "$WORK/c5.json" $'claude[bot]\t2026-10-30T10:05:00Z\t<!-- ai-dev:regenerate head='"$HEAD_A"$' -->'
	run_in "$WORK" request "$WORK/c5.json" "$STARTED" "$HEAD_B"
	expect "TC-495-05: 目印の head が PR の head と違えば作り直さない" test -z "$OUTPUT"
	expect_err "TC-495-05: 作り直さない理由を警告で出す" "PR の head(2222222)と違う"
}

tc_06_collect_changed() {
	repo
	echo '{"changed":true}' > "$REPO_DIR/openapi/openapi.json"
	echo 'other' > "$REPO_DIR/backend.php"
	run_in "$REPO_DIR" collect "$REPO_DIR" "$WORK/out6" abc123
	expect "TC-495-06: 変わった生成ファイルだけを changed に並べる" test "$(cat "$WORK/out6/changed")" = openapi/openapi.json
	expect "TC-495-06: 変わった生成ファイルの中身を files に写す" test "$(cat "$WORK/out6/files/openapi/openapi.json")" = '{"changed":true}'
	expect "TC-495-06: 生成ファイルでないものは写さない" test ! -e "$WORK/out6/files/backend.php"
	expect "TC-495-06: 作り直した head を残す" test "$(cat "$WORK/out6/head")" = abc123
}

tc_07_collect_unchanged() {
	repo
	run_in "$REPO_DIR" collect "$REPO_DIR" "$WORK/out7" abc123
	expect "TC-495-07: 何も変わらなければ changed を作らない" test ! -e "$WORK/out7/changed"
	expect "TC-495-07: head は残す" test "$(cat "$WORK/out7/head")" = abc123
}

tc_08_check_moved() {
	result "$WORK/out8" abc123 openapi/openapi.json
	run_in "$WORK" check "$WORK/out8" def456
	expect "TC-495-08: 作り直した後に head が進んでいれば moved" test "$OUTPUT" = moved
	expect_err "TC-495-08: push しない理由をログに出す" "def456 が push された"
}

tc_09_check_failed() {
	result "$WORK/out9" abc123
	echo "https://example.invalid/run" > "$WORK/out9/failed"
	run_in "$WORK" check "$WORK/out9" abc123
	expect "TC-495-09: 作り直すコマンドが失敗していれば failed" test "$OUTPUT" = "$(printf 'failed\t作り直すコマンドが失敗した')"
}

tc_10_check_unchanged() {
	result "$WORK/out10" abc123
	run_in "$WORK" check "$WORK/out10" abc123
	expect "TC-495-10: 変わったファイルが無ければ unchanged" test "$OUTPUT" = unchanged
}

tc_11_check_push() {
	result "$WORK/out11" abc123 openapi/openapi.json frontend/src/api/schema.ts
	run_in "$WORK" check "$WORK/out11" abc123
	expect "TC-495-11: 生成ファイルだけが通常のファイルで並んでいれば push" test "$OUTPUT" = push
}

tc_12_check_not_generated() {
	result "$WORK/out12" abc123 openapi/openapi.json .github/workflows/x.yml
	run_in "$WORK" check "$WORK/out12" abc123
	expect "TC-495-12: 生成ファイルでないものが並んでいれば failed" test "$OUTPUT" = "$(printf 'failed\t作り直したファイルの一覧が正しい形でなかった')"
}

tc_13_check_symlink_file() {
	result "$WORK/out13" abc123 openapi/openapi.json
	rm "$WORK/out13/files/openapi/openapi.json"
	ln -s /etc/hostname "$WORK/out13/files/openapi/openapi.json"
	run_in "$WORK" check "$WORK/out13" abc123
	expect "TC-495-13: 写したファイルがリンクなら failed" test "${OUTPUT%%$'\t'*}" = failed
}

tc_14_check_symlink_dir() {
	result "$WORK/out14" abc123
	mkdir -p "$WORK/real/openapi"
	echo '{}' > "$WORK/real/openapi/openapi.json"
	ln -s "$WORK/real" "$WORK/out14/files"
	echo openapi/openapi.json > "$WORK/out14/changed"
	run_in "$WORK" check "$WORK/out14" abc123
	expect "TC-495-14: リンクのディレクトリの下のファイルなら failed" test "${OUTPUT%%$'\t'*}" = failed
}

tc_15_check_no_result() {
	mkdir -p "$WORK/out15"
	run_in "$WORK" check "$WORK/out15" abc123
	expect "TC-495-15: 作り直した結果が無ければ failed" test "$OUTPUT" = "$(printf 'failed\t作り直した結果が残っていなかった')"
}

tc_16_build_runs_in_order() {
	repo
	fake_bin ""
	PATH="$FAKE:$PATH" run_in "$REPO_DIR" build "$REPO_DIR"
	expect "TC-495-16: 成功で終わる" test "$STATUS" -eq 0
	expect "TC-495-16: CLAUDE.md の「API を変えたら」の手順を順に流す" test "$(cat "$FAKE/log")" = "$(printf '%s\n' \
		"backend composer install --no-interaction --prefer-dist" \
		"backend php artisan key:generate" \
		"backend php artisan migrate --force" \
		"backend php artisan scramble:export --path=../openapi/openapi.json" \
		"frontend npm ci" \
		"frontend npm run gen:api")"
}

tc_17_build_fails() {
	repo
	fake_bin "migrate"
	PATH="$FAKE:$PATH" run_in "$REPO_DIR" build "$REPO_DIR"
	expect "TC-495-17: 途中のコマンドが失敗したら失敗で終わる" test "$STATUS" -ne 0
	expect "TC-495-17: 失敗の後のコマンドは流さない" test "$(grep -c 'scramble\|npm' "$FAKE/log")" -eq 0
}

tc_18_usage() {
	run_in "$WORK" unknown
	expect "TC-495-18: 知らないモードは使い方を出して 2 で終わる" test "$STATUS" -eq 2
}

# --- 道具 -------------------------------------------------------------------

# 生成ファイルと backend・frontend を持つ git のリポジトリを作る
repo() {
	REPO_DIR=$(mktemp -d "$WORK/repo.XXXX")
	mkdir -p "$REPO_DIR/openapi" "$REPO_DIR/frontend/src/api" "$REPO_DIR/backend"
	echo '{}' > "$REPO_DIR/openapi/openapi.json"
	echo 'export {}' > "$REPO_DIR/frontend/src/api/schema.ts"
	echo 'APP_KEY=' > "$REPO_DIR/backend/.env.example"
	echo 'base' > "$REPO_DIR/backend.php"
	git -C "$REPO_DIR" init -q
	git -C "$REPO_DIR" add -A
	git -C "$REPO_DIR" -c user.name=t -c user.email=t@example.invalid commit -qm init
	git -C "$REPO_DIR" config user.name t
	git -C "$REPO_DIR" config user.email t@example.invalid
}

head_of() {
	git -C "$1" rev-parse HEAD
}

# 作り直しのジョブが残す書き出し先を作る(head と、並べたファイル)
result() {
	local out=$1 head=$2 f
	shift 2
	mkdir -p "$out"
	echo "$head" > "$out/head"
	for f in "$@"; do
		mkdir -p "$out/files/$(dirname "$f")"
		echo '{}' > "$out/files/$f"
		echo "$f" >> "$out/changed"
	done
}

# composer・php・npm の偽物。流れたディレクトリ(backend・frontend)と引数を log に残す。引数に $1 を含む呼び出しは失敗させる
fake_bin() {
	local fail=$1 cmd
	FAKE=$(mktemp -d "$WORK/fake.XXXX")
	: > "$FAKE/log"
	for cmd in composer php npm; do
		cat > "$FAKE/$cmd" <<-EOF
			#!/usr/bin/env bash
			echo "\$(basename "\$PWD") $cmd \$*" >> "$FAKE/log"
			if [ -n "$fail" ] && [[ "\$*" == *"$fail"* ]]; then exit 1; fi
		EOF
		chmod +x "$FAKE/$cmd"
	done
}

run_in() {
	local dir=$1
	shift
	local errfile="$WORK/stderr"
	OUTPUT=$(cd "$dir" && bash "$SCRIPT" "$@" 2>"$errfile")
	STATUS=$?
	ERR=$(cat "$errfile")
}

expect() {
	local name=$1
	shift
	if "$@"; then pass "$name"; else fail "$name"; fi
}

expect_err() {
	if grep -qF -- "$2" <<<"$ERR"; then pass "$1"; else fail "$1"; fi
}

pass() {
	PASSED=$((PASSED + 1))
	echo "ok   $1"
}

fail() {
	FAILED=$((FAILED + 1))
	echo "FAIL $1"
	local line
	while IFS= read -r line; do
		echo "     | $line"
	done <<<"$OUTPUT"$'\n'"$ERR"
}

main
