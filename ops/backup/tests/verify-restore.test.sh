#!/usr/bin/env bash
#
# ops/backup/verify-restore.sh のテスト。
#
#   bash ops/backup/tests/verify-restore.test.sh
#
# DB を使うケースは、管理者で入れる MariaDB を次の環境変数で渡したときだけ流す(渡さなければ SKIP)。
#   VERIFY_TEST_DB_HOST・VERIFY_TEST_DB_PORT・VERIFY_TEST_DB_ROOT_PASSWORD
# VERIFY_TEST_REQUIRE_DB=1 のときは、DB のケースを飛ばさずに失敗にする(CI はこれ)。
# ダンプは dump-db.sh で取る(ダンプ → 復元 → 検証を通しで確かめるため)。
# テスト用に verify_src・verify_scratch の DB と verify_test のユーザーを作り直し、終わったら消す。
# 本番の DB には向けない。

set -uo pipefail

BACKUP_DIR_OF_SCRIPTS=$(cd "$(dirname "$0")/.." && pwd)
SCRIPT=$BACKUP_DIR_OF_SCRIPTS/verify-restore.sh
DUMP_SCRIPT=$BACKUP_DIR_OF_SCRIPTS/dump-db.sh
WORK=$(mktemp -d)
FIXTURE_CREATED=0
DUMP=
trap cleanup EXIT

# 記号を一通り含める(オプションファイルのエスケープと、出力に漏れていないかの検索に使う)
VERIFY_PASS="Vr#9\"q\z'\$w x!"
WRONG_PASS="Wr0ng#\"\pa\$\$"

FAILED=0
PASSED=0
SKIPPED=0

run_tests() {
  tc_wrong_arguments
  tc_same_name_as_source
  tc_missing_dump

  if db_available; then
    if setup_fixture && take_dump; then
      tc_restore_and_match
      tc_deleted_row
      tc_updated_value
      tc_extra_table
      tc_scratch_not_empty
      tc_scratch_missing
      tc_broken_dump
      tc_wrong_password
    fi
  elif [[ ${VERIFY_TEST_REQUIRE_DB:-} == 1 ]]; then
    fail "DB のケース" "VERIFY_TEST_REQUIRE_DB=1 なのに VERIFY_TEST_DB_HOST が無い"
  else
    skip "TC-16-04〜TC-16-12(DB を使うケース)" "VERIFY_TEST_DB_HOST が無い"
  fi

  printf '\n%d passed, %d failed, %d skipped\n' "$PASSED" "$FAILED" "$SKIPPED"
  [[ $FAILED -eq 0 ]]
}

# ---- DB を使わないケース ----

tc_wrong_arguments() {
  local out status
  out=$(bash "$SCRIPT" "$WORK/a.env" "$WORK/a.sql.gz" 2>&1)
  status=$?
  if [ "$status" -eq 1 ] && [[ $out == *"使い方"* ]]; then
    pass "TC-16-01: 引数が 3 つでないと、使い方を出して 1 で止まる"
  else
    fail "TC-16-01: 引数が 3 つでないと、使い方を出して 1 で止まる" "status=$status"
  fi
}

tc_same_name_as_source() {
  local env=$WORK/same.env dump=$WORK/same.sql.gz
  write_env "$env" "$VERIFY_PASS"
  printf 'SELECT 1;\n' | gzip -c >"$dump"
  local out1 status1 out2 status2
  out1=$(bash "$SCRIPT" "$env" "$dump" verify_src 2>&1)
  status1=$?
  out2=$(bash "$SCRIPT" "$env" "$dump" VERIFY_SRC 2>&1)
  status2=$?
  if [ "$status1" -eq 1 ] && [[ $out1 == *"元の DB と同じ名前"* ]] \
    && [ "$status2" -eq 1 ] && [[ $out2 == *"元の DB と同じ名前"* ]] && no_secret "$out1$out2"; then
    pass "TC-16-02: 復元先が .env の DB_DATABASE と同じ名前(大文字小文字を問わない)だと 1 で止まる"
  else
    fail "TC-16-02: 復元先が .env の DB_DATABASE と同じ名前(大文字小文字を問わない)だと 1 で止まる" "status=$status1/$status2"
  fi
}

tc_missing_dump() {
  local env=$WORK/missing.env
  write_env "$env" "$VERIFY_PASS"
  local out status
  out=$(bash "$SCRIPT" "$env" "$WORK/nothing.sql.gz" verify_scratch 2>&1)
  status=$?
  if [ "$status" -eq 1 ] && [[ $out == *"ダンプが読めない"* ]]; then
    pass "TC-16-03: ダンプが無いと 1 で止まる"
  else
    fail "TC-16-03: ダンプが無いと 1 で止まる" "status=$status"
  fi
}

# ---- DB を使うケース ----

tc_restore_and_match() {
  local tmp=$WORK/tmp-ok out status
  mkdir -p "$tmp"
  out=$(TMPDIR=$tmp bash "$SCRIPT" "$WORK/ok.env" "$DUMP" verify_scratch 2>&1)
  status=$?
  if [ "$status" -eq 0 ] && [[ $out == *"一致した(4 テーブル)"* ]]; then
    pass "TC-16-04: dump-db.sh のダンプを復元すると、4 テーブルの行数・チェックサムが一致して 0 で終わる"
  else
    fail "TC-16-04: dump-db.sh のダンプを復元すると、4 テーブルの行数・チェックサムが一致して 0 で終わる" "status=$status 出力: $(mask "$out")"
  fi

  if scratch_is_empty && no_secret "$out" && [ -z "$(ls -A "$tmp")" ]; then
    pass "TC-16-05: 終わった後、復元先が空に戻り、一時ファイルが残らず、出力にパスワードが出ない"
  else
    fail "TC-16-05: 終わった後、復元先が空に戻り、一時ファイルが残らず、出力にパスワードが出ない" ""
  fi
}

tc_deleted_row() {
  admin verify_src -e "DELETE FROM bin_rows WHERE id = 2"
  local out status
  out=$(bash "$SCRIPT" "$WORK/ok.env" "$DUMP" verify_scratch 2>&1)
  status=$?
  admin verify_src -e "INSERT INTO bin_rows VALUES (2, '')"
  if [ "$status" -eq 2 ] && [[ $out == *$'-bin_rows\t1\t'* ]] && [[ $out == *$'+bin_rows\t2\t'* ]] && scratch_is_empty; then
    pass "TC-16-06: ダンプの後に元の DB から 1 行消すと、違うテーブルを出して 2 で終わり、復元先は空に戻る"
  else
    fail "TC-16-06: ダンプの後に元の DB から 1 行消すと、違うテーブルを出して 2 で終わり、復元先は空に戻る" "status=$status 出力: $(mask "$out")"
  fi
}

tc_updated_value() {
  admin verify_src -e "UPDATE items SET name = 'Gimlet' WHERE id = 1"
  local out status
  out=$(bash "$SCRIPT" "$WORK/ok.env" "$DUMP" verify_scratch 2>&1)
  status=$?
  admin verify_src -e "UPDATE items SET name = 'マティーニ' WHERE id = 1"
  if [ "$status" -eq 2 ] && [[ $out == *$'-items\t3\t'* ]] && [[ $out == *$'+items\t3\t'* ]] && scratch_is_empty; then
    pass "TC-16-07: 行数が同じでも値が 1 つ違うと、チェックサムの違いで 2 で終わる"
  else
    fail "TC-16-07: 行数が同じでも値が 1 つ違うと、チェックサムの違いで 2 で終わる" "status=$status 出力: $(mask "$out")"
  fi
}

tc_extra_table() {
  admin verify_src -e "CREATE TABLE added_later (id INT PRIMARY KEY)"
  local out status
  out=$(bash "$SCRIPT" "$WORK/ok.env" "$DUMP" verify_scratch 2>&1)
  status=$?
  admin verify_src -e "DROP TABLE added_later"
  if [ "$status" -eq 2 ] && [[ $out == *"-added_later"* ]] && scratch_is_empty; then
    pass "TC-16-08: 元の DB にしか無いテーブルがあると 2 で終わる"
  else
    fail "TC-16-08: 元の DB にしか無いテーブルがあると 2 で終わる" "status=$status 出力: $(mask "$out")"
  fi
}

tc_scratch_not_empty() {
  admin verify_scratch -e "CREATE TABLE keep_me (id INT PRIMARY KEY); INSERT INTO keep_me VALUES (1)"
  local out status
  out=$(bash "$SCRIPT" "$WORK/ok.env" "$DUMP" verify_scratch 2>&1)
  status=$?
  local kept
  kept=$(admin -N -B verify_scratch -e "SELECT COUNT(*) FROM keep_me" 2>/dev/null)
  local others
  others=$(admin -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'verify_scratch' AND TABLE_NAME <> 'keep_me'")
  admin verify_scratch -e "DROP TABLE keep_me"
  if [ "$status" -eq 1 ] && [[ $out == *"空でない"* ]] && [ "$kept" = 1 ] && [ "$others" = 0 ]; then
    pass "TC-16-09: 復元先にテーブルがあると、何も復元せず・何も消さずに 1 で止まる"
  else
    fail "TC-16-09: 復元先にテーブルがあると、何も復元せず・何も消さずに 1 で止まる" "status=$status kept=$kept others=$others"
  fi
}

tc_scratch_missing() {
  local out status
  out=$(bash "$SCRIPT" "$WORK/ok.env" "$DUMP" verify_nothing 2>&1)
  status=$?
  if [ "$status" -eq 1 ] && [[ $out == *"復元先の DB に接続できない"* ]] && no_secret "$out"; then
    pass "TC-16-10: 復元先の DB が無いと 1 で止まる"
  else
    fail "TC-16-10: 復元先の DB が無いと 1 で止まる" "status=$status 出力: $(mask "$out")"
  fi
}

# 途中で切れたダンプ: 前半のテーブルは入るが、後半で失敗する
tc_broken_dump() {
  local broken=$WORK/broken.sql.gz
  { gunzip -c "$DUMP" | head -c "$(($(gunzip -c "$DUMP" | wc -c) / 2))"; printf '\nINSERT INTO broken syntax;\n'; } | gzip -c >"$broken"
  local out status
  out=$(bash "$SCRIPT" "$WORK/ok.env" "$broken" verify_scratch 2>&1)
  status=$?
  if [ "$status" -eq 2 ] && [[ $out == *"復元できない"* ]] && scratch_is_empty; then
    pass "TC-16-11: 復元に失敗するダンプだと 2 で終わり、途中まで入ったものも消す"
  else
    fail "TC-16-11: 復元に失敗するダンプだと 2 で終わり、途中まで入ったものも消す" "status=$status 出力: $(mask "$out")"
  fi
}

tc_wrong_password() {
  local env=$WORK/wrong.env tmp=$WORK/tmp-wrong
  mkdir -p "$tmp"
  write_env "$env" "$WRONG_PASS"
  local out status
  out=$(TMPDIR=$tmp bash "$SCRIPT" "$env" "$DUMP" verify_scratch 2>&1)
  status=$?
  if [ "$status" -eq 1 ] && [[ $out != *"$WRONG_PASS"* ]] && no_secret "$out" && [ -z "$(ls -A "$tmp")" ]; then
    pass "TC-16-12: パスワードが違うと 1 で止まり、出力にパスワードが出ず、一時ファイルが残らない"
  else
    fail "TC-16-12: パスワードが違うと 1 で止まり、出力にパスワードが出ず、一時ファイルが残らない" "status=$status"
  fi
}

# ---- 補助 ----

db_available() {
  [[ -n ${VERIFY_TEST_DB_HOST:-} ]]
}

admin() {
  MYSQL_PWD=${VERIFY_TEST_DB_ROOT_PASSWORD:-} "$(client)" --default-character-set=utf8mb4 \
    -h "$VERIFY_TEST_DB_HOST" -P "${VERIFY_TEST_DB_PORT:-3306}" -u root "$@"
}

client() {
  if command -v mariadb >/dev/null 2>&1; then echo mariadb; else echo mysql; fi
}

# 検証するユーザーは、サーバーと同じく元の DB と復元先の DB にだけ権限を持つ。
# 外部キー・ビュー・トリガー・ルーチン・絵文字・バイナリを含め、復元と後片付けで取りこぼさないかを見る
setup_fixture() {
  FIXTURE_CREATED=1
  local sql_pass=${VERIFY_PASS//\\/\\\\}
  sql_pass=${sql_pass//\'/\\\'}
  admin <<SQL || { fail "fixture" "テスト用の DB を作れない"; return 1; }
DROP DATABASE IF EXISTS verify_src;
DROP DATABASE IF EXISTS verify_scratch;
DROP USER IF EXISTS 'verify_test'@'%';
CREATE DATABASE verify_src CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE verify_scratch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'verify_test'@'%' IDENTIFIED BY '$sql_pass';
GRANT ALL PRIVILEGES ON verify_src.* TO 'verify_test'@'%';
GRANT ALL PRIVILEGES ON verify_scratch.* TO 'verify_test'@'%';
USE verify_src;
CREATE TABLE items (id INT PRIMARY KEY, name VARCHAR(100) NOT NULL) CHARACTER SET utf8mb4;
INSERT INTO items VALUES (1, 'マティーニ'), (2, 'Mojito 🍹'), (3, 'It''s "quoted" \\\\ back');
CREATE TABLE item_tags (id INT PRIMARY KEY, item_id INT NOT NULL, FOREIGN KEY (item_id) REFERENCES items (id));
INSERT INTO item_tags VALUES (1, 1), (2, 2);
CREATE TABLE bin_rows (id INT PRIMARY KEY, data BLOB NOT NULL);
INSERT INTO bin_rows VALUES (1, 0x00FF80C3283A0A0D27225C), (2, '');
CREATE TABLE logs (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL);
CREATE DEFINER='verify_test'@'%' VIEW item_names AS SELECT name FROM items;
CREATE DEFINER='verify_test'@'%' TRIGGER items_after_insert AFTER INSERT ON items
  FOR EACH ROW INSERT INTO logs (item_id) VALUES (NEW.id);
CREATE DEFINER='verify_test'@'%' PROCEDURE item_count() SELECT COUNT(*) FROM items;
SQL
  write_env "$WORK/ok.env" "$VERIFY_PASS"
}

take_dump() {
  local env=$WORK/dump.env out
  { cat "$WORK/ok.env"; printf 'BACKUP_DIR=%s\n' "$WORK/backup"; } >"$env"
  out=$(bash "$DUMP_SCRIPT" "$env" 2>&1) || { fail "fixture" "dump-db.sh でダンプを取れない: $(mask "$out")"; return 1; }
  DUMP=${out##*$'\n'}
}

# 既知のパスワードのユーザーを共有の MariaDB に残さないよう、作ったものを消す
cleanup() {
  local status=$?
  if [[ $FIXTURE_CREATED -eq 1 ]]; then
    admin >/dev/null 2>&1 <<'SQL' || true
DROP DATABASE IF EXISTS verify_src;
DROP DATABASE IF EXISTS verify_scratch;
DROP USER IF EXISTS 'verify_test'@'%';
SQL
  fi
  rm -rf "$WORK"
  exit "$status"
}

scratch_is_empty() {
  [ "$(admin -N -B -e "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'verify_scratch')
    + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'verify_scratch')")" = 0 ]
}

write_env() {
  local file=$1 pass=$2
  local escaped=${pass//\\/\\\\}
  escaped=${escaped//\"/\\\"}
  {
    printf '# テスト用の .env\n'
    printf 'DB_CONNECTION=mariadb\n'
    printf 'DB_HOST=%s\n' "${VERIFY_TEST_DB_HOST:-127.0.0.1}"
    printf 'DB_PORT=%s # コメント\n' "${VERIFY_TEST_DB_PORT:-3306}"
    printf 'DB_DATABASE=verify_src\n'
    printf "DB_USERNAME='verify_test'\n"
    printf 'DB_PASSWORD="%s"\r\n' "$escaped"
  } >"$file"
}

no_secret() {
  [[ $1 != *"$VERIFY_PASS"* ]]
}

mask() {
  local s=${1//"$VERIFY_PASS"/***}
  printf '%s' "${s//"$WRONG_PASS"/***}"
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
