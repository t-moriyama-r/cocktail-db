#!/usr/bin/env bash
#
# ops/migrate/check-legacy-dump.sh のテスト。
#
#   bash ops/migrate/tests/check-legacy-dump.test.sh
#
# DB を使うケースは、管理者で入れる MariaDB を次の環境変数で渡したときだけ流す(渡さなければ SKIP)。
#   CHECK_TEST_DB_HOST・CHECK_TEST_DB_PORT・CHECK_TEST_DB_ROOT_PASSWORD
# CHECK_TEST_REQUIRE_DB=1 のときは、DB のケースを飛ばさずに失敗にする(CI はこれ)。
# ケースごとに check_legacy_test の DB を作り直し(backend/database/legacy/schema.sql の表を作り、主な表に 1 行ずつ入れる)、
# 終わったら消す。本番の DB には向けない。

set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
SCRIPT=$ROOT/ops/migrate/check-legacy-dump.sh
SCHEMA=$ROOT/backend/database/legacy/schema.sql
TEST_DB=check_legacy_test
FIXTURE_CREATED=0
trap cleanup EXIT

# 本番のダンプで行がある主な表(issue #229 の「主な表の件数が 0 でない」)。users はダンプに行が無いので入れない
MAIN_TABLES=(m_category_kinds m_category m_middle m_brand t_cocktails t_recipies m_method m_ice
	m_dictionary_category t_dictionary affiliates paraphrases skip_db)

FAILED=0
PASSED=0
SKIPPED=0

run_tests() {
	tc_wrong_arguments

	if db_available; then
		tc_complete_dump
		tc_dropped_table
		tc_dropped_users
		tc_emptied_table
		tc_missing_and_empty
		tc_no_tables
		tc_connection_failed
	elif [[ ${CHECK_TEST_REQUIRE_DB:-} == 1 ]]; then
		fail "DB のケース" "CHECK_TEST_REQUIRE_DB=1 なのに CHECK_TEST_DB_HOST が無い"
	else
		skip "TC-229-02〜TC-229-08(DB を使うケース)" "CHECK_TEST_DB_HOST が無い"
	fi

	printf '\n%d passed, %d failed, %d skipped\n' "$PASSED" "$FAILED" "$SKIPPED"
	[[ $FAILED -eq 0 ]]
}

# ---- DB を使わないケース ----

tc_wrong_arguments() {
	local out status
	out=$(bash "$SCRIPT" legacy 2>&1)
	status=$?
	check "TC-229-01: クライアントのコマンドを渡さないと、使い方を出して 1 で止まる" \
		"$status" 1 "$out" "使い方"
}

# ---- DB を使うケース ----

tc_complete_dump() {
	setup_fixture || return
	local out status
	out=$(run_script)
	status=$?
	check "TC-229-02: schema.sql の表がそろい、主な表に 1 行ずつ・users が 0 行なら、0 で終わる" \
		"$status" 0 "$out" "そろっている(16 表。うち主な 13 表に行がある)"
}

tc_dropped_table() {
	setup_fixture || return
	admin "$TEST_DB" -e "DROP TABLE t_recipies"
	local out status
	out=$(run_script)
	status=$?
	check "TC-229-03: 表を 1 つ消すと、その表の名前を出して 2 で終わる" \
		"$status" 2 "$out" "表が無い: t_recipies" && only_lines "$out" 1
}

tc_dropped_users() {
	setup_fixture || return
	admin "$TEST_DB" -e "DROP TABLE users"
	local out status
	out=$(run_script)
	status=$?
	check "TC-229-04: users の定義が無いと、users を出して 2 で終わる(#213 で投入が成功扱いになった形)" \
		"$status" 2 "$out" "表が無い: users"
}

tc_emptied_table() {
	setup_fixture || return
	admin "$TEST_DB" -e "DELETE FROM m_brand"
	local out status
	out=$(run_script)
	status=$?
	check "TC-229-05: 主な表を空にすると、その表の名前を出して 2 で終わる" \
		"$status" 2 "$out" "行が無い: m_brand" && only_lines "$out" 1
}

tc_missing_and_empty() {
	setup_fixture || return
	admin "$TEST_DB" -e "DROP TABLE m_middle; DELETE FROM t_cocktails"
	local out status
	out=$(run_script)
	status=$?
	if [[ $status -eq 2 && $out == *"表が無い: m_middle"* && $out == *"行が無い: t_cocktails"* ]]; then
		pass "TC-229-06: 無い表と空の表が両方あると、両方の名前を出して 2 で終わる"
	else
		fail "TC-229-06: 無い表と空の表が両方あると、両方の名前を出して 2 で終わる" "status=$status 出力: $out"
	fi
}

tc_no_tables() {
	setup_fixture || return
	admin -e "DROP DATABASE $TEST_DB; CREATE DATABASE $TEST_DB"
	local out status
	out=$(run_script)
	status=$?
	check "TC-229-07: 何も入っていない DB では、16 表すべてを無いと出して 2 で終わる" \
		"$status" 2 "$out" "表が無い: affiliates" && only_lines "$out" 16
}

tc_connection_failed() {
	local out status
	out=$(bash "$SCRIPT" check_legacy_nothing "${CLIENT[@]}" 2>&1)
	status=$?
	if [[ $status -eq 1 && $out == *"DB に接続できない: check_legacy_nothing"* && $out != *"表が無い"* ]]; then
		pass "TC-229-08: DB に接続できないと、欠けた表を出さずに 1 で止まる"
	else
		fail "TC-229-08: DB に接続できないと、欠けた表を出さずに 1 で止まる" "status=$status 出力: $out"
	fi
}

# ---- 補助 ----

db_available() {
	[[ -n ${CHECK_TEST_DB_HOST:-} ]]
}

client() {
	if command -v mariadb >/dev/null 2>&1; then echo mariadb; else echo mysql; fi
}

# スクリプトに渡すクライアントのコマンド。パスワードは環境変数で渡す
CLIENT=(env "MYSQL_PWD=${CHECK_TEST_DB_ROOT_PASSWORD:-}" "$(client)" -h "${CHECK_TEST_DB_HOST:-127.0.0.1}"
	-P "${CHECK_TEST_DB_PORT:-3306}" -u root)

admin() {
	"${CLIENT[@]}" "$@"
}

run_script() {
	bash "$SCRIPT" "$TEST_DB" "${CLIENT[@]}" 2>&1
}

# 本番の旧 DB と同じ DDL で表を作り、主な表に 1 行ずつ入れる(境界: 1 行あれば通る)。
# 値は問わないので、厳格モードを切って列の既定値で入れる
setup_fixture() {
	FIXTURE_CREATED=1
	local inserts='' table
	for table in "${MAIN_TABLES[@]}"; do inserts+="INSERT INTO \`$table\` () VALUES ();"; done
	if ! admin -e "DROP DATABASE IF EXISTS $TEST_DB; CREATE DATABASE $TEST_DB CHARACTER SET utf8mb3" \
		|| ! admin "$TEST_DB" <"$SCHEMA" \
		|| ! admin "$TEST_DB" -e "SET SESSION sql_mode = ''; $inserts"; then
		fail "fixture" "テスト用の DB を作れない"
		return 1
	fi
}

cleanup() {
	local status=$?
	if [[ $FIXTURE_CREATED -eq 1 ]]; then
		admin -e "DROP DATABASE IF EXISTS $TEST_DB" >/dev/null 2>&1 || true
	fi
	exit "$status"
}

# 終了コードと、出力に含まれる文字列を確かめる
check() {
	local name=$1 status=$2 want_status=$3 out=$4 want_text=$5
	if [[ $status -eq $want_status && $out == *"$want_text"* ]]; then
		pass "$name"
	else
		fail "$name" "status=$status 出力: $out"
		return 1
	fi
}

# 欠けたものとして出た行の数が、want と同じか(ほかの表を巻き込んで出していないか)
only_lines() {
	local out=$1 want=$2 got
	got=$(grep -cE '^  (表が無い|行が無い): ' <<<"$out" || true)
	if [[ $got -ne $want ]]; then
		fail "  欠けたものの行の数" "want=$want got=$got 出力: $out"
	fi
}

pass() {
	PASSED=$((PASSED + 1))
	printf 'PASS %s\n' "$1"
}

fail() {
	FAILED=$((FAILED + 1))
	printf 'FAIL %s %s\n' "$1" "$2"
}

skip() {
	SKIPPED=$((SKIPPED + 1))
	printf 'SKIP %s(%s)\n' "$1" "$2"
}

run_tests
