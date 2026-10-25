#!/usr/bin/env bash
#
# archive-storage.sh のテスト(TC-15-xx)。MariaDB に旧スキーマ
# (backend/database/legacy/schema.sql)で DB を作り、ダミーの画像を作って流す。
#
# 使い方:
#   TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 TEST_DB_USER=root TEST_DB_PASSWORD=root \
#     ops/backup/tests/archive-storage.test.sh
#
# TEST_DB_USER は DB とユーザーを作れる権限が要る。テストは DB archive_storage_test と、
# 読み取りだけのユーザー archive_reader を作り直す。

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../archive-storage.sh"
SCHEMA="$HERE/../../../backend/database/legacy/schema.sql"

DB_HOST=${TEST_DB_HOST:-127.0.0.1}
DB_PORT=${TEST_DB_PORT:-3306}
DB_USER=${TEST_DB_USER:-root}
DB_PASSWORD=${TEST_DB_PASSWORD:-root}
DB_NAME=archive_storage_test
READER=archive_reader
# 記号を混ぜ、my.cnf へのエスケープと「出力に出ないこと」を確かめる。$ は展開させずに文字として入れる
# shellcheck disable=SC2016
READER_PASSWORD='p@ss"w\rd$x'"'"'y'
CLIENT=${MYSQL_CLIENT:-$(command -v mariadb || command -v mysql)}

FAILED=0
PASSED=0

main() {
	setup_db
	tc_01_archive_matches_source
	tc_02_missing_file_is_listed
	tc_03_unreferenced_file_is_listed
	tc_04_null_empty_and_url_are_not_missing
	tc_05_same_name_in_two_tables
	tc_07_empty_directory
	tc_08_verify_detects_mismatch
	tc_09_password_is_not_printed
	tc_10_refuses_public_html
	tc_11_config_errors
	tc_12_permissions
	tc_13_db_connection_failure
	echo "passed: $PASSED, failed: $FAILED"
	[[ $FAILED -eq 0 ]]
}

# --- ケース -----------------------------------------------------------------

# TC-15-01 / TC-15-06 / TC-15-14
tc_01_archive_matches_source() {
	new_sandbox
	add_image "a.jpeg" 2048
	add_image "空白 と 日本語.png" 100
	add_image "sub/dir/deep.gif" 10
	add_image ".gitignore" 5
	db_rows "INSERT INTO t_cocktails (id, image) VALUES (1, 'a.jpeg')"
	run_script "$ENV_FILE"

	expect_status "TC-15-01: 正常に終わる" 0
	local archive
	archive=$(find "$BACKUP/storage" -maxdepth 1 -name '*.tar.gz' | head -n 1)
	expect "TC-15-01: アーカイブが <日時>.tar.gz の名前でできる" \
		test -n "$archive" -a "$(basename "${archive:-x}" | grep -cE '^[0-9]{8}-[0-9]{6}\.tar\.gz$')" = 1
	expect "TC-15-01: .sha256 で sha256sum -c が通る" \
		sh -c "cd '$BACKUP/storage' && sha256sum -c --quiet '$(basename "${archive:-x}").sha256'"

	local extracted="$SANDBOX/extracted"
	mkdir -p "$extracted"
	tar -C "$extracted" -xzf "$archive"
	expect "TC-15-01: 展開したファイルの数が元と一致する" \
		test "$(count_files "$STORAGE")" = "$(count_files "$extracted")" -a "$(count_files "$STORAGE")" = 4
	expect "TC-15-06: 空白・日本語・サブディレクトリの名前を含め、ハッシュが元と一致する" \
		cmp -s <(manifest "$STORAGE") <(manifest "$extracted")
	expect_output "TC-15-14: 画像のディレクトリを省くと <.env の場所>/storage/app/public を固める" "files: 4 (展開して数とハッシュが元と一致した)"
	expect_output "TC-15-01: DB が指すファイルがあれば欠けは 0" "missing: 0"
}

# TC-15-02
tc_02_missing_file_is_listed() {
	new_sandbox
	add_image "here.jpeg" 10
	db_rows "INSERT INTO t_cocktails (id, image) VALUES (1, 'here.jpeg'), (2, 'gone.jpeg')" \
		"INSERT INTO m_brand (id, image) VALUES (7, 'gone-brand.png')"
	run_script "$ENV_FILE"

	expect_status "TC-15-02: 欠けがあっても 0 で終わる" 0
	expect_output "TC-15-02: 欠けている数を出す" "missing: 2"
	expect_output "TC-15-02: 欠けているものを表・id・名前で出す" "  t_cocktails	2	gone.jpeg"
	expect "TC-15-02: check.tsv に missing の行がある" \
		grep -qxF "missing	m_brand	7	gone-brand.png" "$(check_file)"
	expect "TC-15-02: あるファイルは missing に出ない" \
		bash -c "! grep -q 'here.jpeg' '$(check_file)'"
}

# TC-15-03
tc_03_unreferenced_file_is_listed() {
	new_sandbox
	add_image "used.jpeg" 10
	add_image "orphan.jpeg" 10
	add_image ".gitignore" 5
	db_rows "INSERT INTO m_category (id, image) VALUES (1, 'used.jpeg')"
	run_script "$ENV_FILE"

	expect_status "TC-15-03: 正常に終わる" 0
	expect_output "TC-15-03: DB に無いファイルの数を出す" "unreferenced: 1"
	expect "TC-15-03: check.tsv に unreferenced の行がある" \
		grep -qxF "unreferenced	-	-	orphan.jpeg" "$(check_file)"
	expect "TC-15-03: ドットファイルは unreferenced に出さない" \
		bash -c "! grep -q 'gitignore' '$(check_file)'"
}

# TC-15-04
tc_04_null_empty_and_url_are_not_missing() {
	new_sandbox
	add_image "x.jpeg" 10
	db_rows "INSERT INTO t_cocktails (id, image) VALUES (1, NULL), (2, ''), (3, 'https://example.com/a.jpg'), (4, 'x.jpeg')"
	run_script "$ENV_FILE"

	expect_status "TC-15-04: 正常に終わる" 0
	expect_output "TC-15-04: NULL・空文字・URL は欠けに数えない" "missing: 0"
	expect_output "TC-15-04: URL は external として数える" "external: 1"
	expect "TC-15-04: check.tsv に external の行がある" \
		grep -qxF "external	t_cocktails	3	https://example.com/a.jpg" "$(check_file)"
}

# TC-15-05
tc_05_same_name_in_two_tables() {
	new_sandbox
	add_image "shared.jpeg" 10
	db_rows "INSERT INTO m_middle (id, image) VALUES (1, 'shared.jpeg')" \
		"INSERT INTO t_dictionary (id, image) VALUES (1, 'shared.jpeg')"
	run_script "$ENV_FILE"

	expect_status "TC-15-05: 正常に終わる" 0
	expect_output "TC-15-05: 2 つの表が同じファイルを指しても欠けにならない" "missing: 0"
	expect_output "TC-15-05: 参照されているので unreferenced にもならない" "unreferenced: 0"
}

# TC-15-07
tc_07_empty_directory() {
	new_sandbox
	run_script "$ENV_FILE"

	expect_status "TC-15-07: 空のディレクトリでも正常に終わる" 0
	expect_output "TC-15-07: ファイルの数は 0" "files: 0 (展開して数とハッシュが元と一致した)"

	new_sandbox
	db_rows "INSERT INTO t_cocktails (id, image) VALUES (1, 'a.jpeg'), (2, 'b.jpeg')"
	run_script "$ENV_FILE"
	expect_output "TC-15-07: 空のディレクトリでも DB が指すファイルは欠けに数える" "missing: 2"
	expect_output "TC-15-07: DB の値を unreferenced に出さない" "unreferenced: 0"
}

# TC-15-08
tc_08_verify_detects_mismatch() {
	new_sandbox
	add_image "a.jpeg" 100
	add_image "b.jpeg" 100
	run_script "$ENV_FILE"
	local archive
	archive=$(find "$BACKUP/storage" -maxdepth 1 -name '*.tar.gz' | head -n 1)

	run_script --verify "$archive" "$STORAGE"
	expect_status "TC-15-08: --verify は一致していれば 0" 0

	head -c 100 /dev/urandom >"$STORAGE/a.jpeg"
	run_script --verify "$archive" "$STORAGE"
	expect_status "TC-15-08: 中身が変わったファイルがあれば 2" 2

	# 中身を元に戻してから、ファイルを 1 つ足す
	tar -C "$STORAGE" -xzf "$archive" ./a.jpeg
	add_image "extra.jpeg" 10
	run_script --verify "$archive" "$STORAGE"
	expect_status "TC-15-08: ファイルの数が違えば 2" 2
}

# TC-15-09
tc_09_password_is_not_printed() {
	new_sandbox
	add_image "a.jpeg" 10
	run_script "$ENV_FILE"

	expect_status "TC-15-09: 記号を含むパスワードで接続できる" 0
	expect "TC-15-09: 出力にパスワードが出ない" \
		not_contains "$OUTPUT" "$READER_PASSWORD"
	expect "TC-15-09: 一時ファイル(接続情報)が残らない" \
		test -z "$(find "$BACKUP" -name '.work.*')"

	new_sandbox
	add_image "a.jpeg" 10
	write_env "\"$READER_PASSWORD\""
	run_script "$ENV_FILE"
	expect_status "TC-15-09: .env の値が引用符で囲まれていても接続できる" 0
}

# TC-15-10
tc_10_refuses_public_html() {
	new_sandbox
	add_image "a.jpeg" 10
	BACKUP="$SANDBOX/public_html/backup"
	write_env "$READER_PASSWORD"
	run_script "$ENV_FILE"

	expect_status "TC-15-10: public_html の下の BACKUP_DIR は拒む" 1
	expect "TC-15-10: 何も書かない" test ! -e "$BACKUP"
}

# TC-15-11
tc_11_config_errors() {
	new_sandbox
	run_script "$SANDBOX/none/.env"
	expect_status "TC-15-11: .env が無ければ 1" 1

	run_script "$ENV_FILE" "$SANDBOX/no-such-dir"
	expect_status "TC-15-11: 画像のディレクトリが無ければ 1" 1

	grep -v '^BACKUP_DIR=' "$ENV_FILE" >"$ENV_FILE.tmp" && mv "$ENV_FILE.tmp" "$ENV_FILE"
	run_script "$ENV_FILE"
	expect_status "TC-15-11: BACKUP_DIR が無ければ 1" 1

	run_script
	expect_status "TC-15-11: 引数が無ければ 1" 1
}

# TC-15-12
tc_12_permissions() {
	new_sandbox
	add_image "a.jpeg" 10
	run_script "$ENV_FILE"

	expect_status "TC-15-12: 正常に終わる" 0
	expect "TC-15-12: 出力先のディレクトリは 700" test "$(stat -c %a "$BACKUP/storage")" = 700
	expect "TC-15-12: 出力したファイルはすべて 600" \
		test -z "$(find "$BACKUP/storage" -maxdepth 1 -type f ! -perm 600)" -a \
		"$(find "$BACKUP/storage" -maxdepth 1 -type f | wc -l)" = 3
}

# TC-15-13
tc_13_db_connection_failure() {
	new_sandbox
	add_image "a.jpeg" 10
	write_env "wrong-password"
	run_script "$ENV_FILE"

	expect_status "TC-15-13: DB に繋がらなければ 1" 1
	expect "TC-15-13: アーカイブを出さない" \
		test -z "$(find "$BACKUP" -name '*.tar.gz' 2>/dev/null)"
}

# --- 道具 -------------------------------------------------------------------

setup_db() {
	db_admin -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
	db_admin "$DB_NAME" <"$SCHEMA"
	local escaped=${READER_PASSWORD//\\/\\\\}
	escaped=${escaped//\'/\'\'}
	db_admin -e "DROP USER IF EXISTS '$READER'@'%'; CREATE USER '$READER'@'%' IDENTIFIED BY '$escaped'; GRANT SELECT ON $DB_NAME.* TO '$READER'@'%';"
}

db_admin() {
	MYSQL_PWD=$DB_PASSWORD "$CLIENT" -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$@"
}

# 旧スキーマは NOT NULL で既定値の無い列が多いので、sql_mode を空にして画像列だけを入れる
db_rows() {
	local sql="SET SESSION sql_mode='';"
	local table
	for table in t_cocktails m_category m_middle m_brand t_dictionary; do
		sql+="DELETE FROM $table;"
	done
	local stmt
	for stmt in "$@"; do
		sql+="$stmt;"
	done
	db_admin "$DB_NAME" -e "$sql"
}

new_sandbox() {
	SANDBOX=$(mktemp -d)
	STORAGE="$SANDBOX/app/storage/app/public"
	BACKUP="$SANDBOX/backup"
	ENV_FILE="$SANDBOX/app/.env"
	mkdir -p "$STORAGE"
	db_rows
	write_env "$READER_PASSWORD"
}

write_env() {
	cat >"$ENV_FILE" <<EOF
APP_NAME=Cocktail
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_DATABASE=$DB_NAME
DB_USERNAME=$READER
DB_PASSWORD=$1
BACKUP_DIR=$BACKUP
EOF
}

add_image() {
	local path="$STORAGE/$1"
	mkdir -p "$(dirname "$path")"
	head -c "$2" /dev/urandom >"$path"
}

run_script() {
	OUTPUT=$("$SCRIPT" "$@" 2>&1)
	STATUS=$?
}

check_file() {
	find "$BACKUP/storage" -maxdepth 1 -name '*.check.tsv' | head -n 1
}

count_files() {
	find "$1" -type f | wc -l
}

manifest() {
	(cd "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum)
}

expect() {
	local name=$1
	shift
	if "$@"; then pass "$name"; else fail "$name"; fi
}

not_contains() {
	! grep -qF -- "$2" <<<"$1"
}

expect_status() {
	if [[ $STATUS -eq $2 ]]; then pass "$1"; else fail "$1 (終了コード $STATUS)"; fi
}

expect_output() {
	if grep -qxF -- "$2" <<<"$OUTPUT"; then pass "$1"; else fail "$1 (「$2」の行が無い)"; fi
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
	done <<<"$OUTPUT"
}

main
