#!/usr/bin/env bash
#
# ops/backup/dump-db.sh のテスト。
#
#   bash ops/backup/tests/dump-db.test.sh
#
# DB を使うケースは、管理者で入れる MariaDB を次の環境変数で渡したときだけ流す(渡さなければ SKIP)。
#   DUMP_TEST_DB_HOST・DUMP_TEST_DB_PORT・DUMP_TEST_DB_ROOT_PASSWORD
# DUMP_TEST_REQUIRE_DB=1 のときは、DB のケースを飛ばさずに失敗にする(CI はこれ)。
# テスト用に dump_src・dump_restore の DB と dump_test のユーザーを作り直し、終わったら消す。本番の DB には向けない。

set -uo pipefail

SCRIPT=$(cd "$(dirname "$0")/.." && pwd)/dump-db.sh
WORK=$(mktemp -d)
FIXTURE_CREATED=0
trap cleanup EXIT

# 記号を一通り含める(オプションファイルのエスケープと、出力に漏れていないかの検索に使う)
DUMP_PASS="Dp#9\"q\z'\$w x!"
WRONG_PASS="Wr0ng#\"\pa\$\$"

FAILED=0
PASSED=0
SKIPPED=0

run_tests() {
  tc_missing_env_file
  tc_missing_required_key
  tc_relative_backup_dir
  tc_public_html_direct
  tc_public_html_symlink
  tc_public_html_db_symlink

  if db_available; then
    if setup_fixture; then
      tc_dump_and_restore
      tc_wrong_password
    fi
  elif [[ ${DUMP_TEST_REQUIRE_DB:-} == 1 ]]; then
    fail "DB のケース" "DUMP_TEST_REQUIRE_DB=1 なのに DUMP_TEST_DB_HOST が無い"
  else
    skip "TC-14-06〜TC-14-12(DB を使うケース)" "DUMP_TEST_DB_HOST が無い"
  fi

  printf '\n%d passed, %d failed, %d skipped\n' "$PASSED" "$FAILED" "$SKIPPED"
  [[ $FAILED -eq 0 ]]
}

# ---- DB を使わないケース ----

tc_missing_env_file() {
  local out
  out=$(bash "$SCRIPT" "$WORK/nothing.env" 2>&1)
  local status=$?
  if [ "$status" -ne 0 ] && [[ $out == *".env が読めない"* ]]; then
    pass "TC-14-01: .env が無いと止まる"
  else
    fail "TC-14-01: .env が無いと止まる" ""
  fi
}

tc_missing_required_key() {
  local env=$WORK/no-backup-dir.env
  printf 'DB_DATABASE=app\nDB_USERNAME=app\nDB_PASSWORD="%s"\n' "$DUMP_PASS" >"$env"
  local out status
  out=$(bash "$SCRIPT" "$env" 2>&1)
  status=$?
  if \
    [ "$status" -ne 0 ] && [[ $out == *"BACKUP_DIR が無い"* ]] && no_secret "$out"; then
    pass "TC-14-02: 必須のキー(BACKUP_DIR)が無いと、キーの名前を出して止まる"
  else
    fail "TC-14-02: 必須のキー(BACKUP_DIR)が無いと、キーの名前を出して止まる" ""
  fi
}

tc_relative_backup_dir() {
  local env=$WORK/relative.env
  write_env "$env" "backups/here" "$DUMP_PASS"
  local out status
  out=$(cd "$WORK" && bash "$SCRIPT" "$env" 2>&1)
  status=$?
  if \
    [ "$status" -ne 0 ] && [[ ! -e $WORK/backups ]] && no_secret "$out"; then
    pass "TC-14-03: BACKUP_DIR が相対パスだと止まり、何も作らない"
  else
    fail "TC-14-03: BACKUP_DIR が相対パスだと止まり、何も作らない" ""
  fi
}

tc_public_html_direct() {
  local env=$WORK/public.env
  mkdir -p "$WORK/home/public_html"
  write_env "$env" "$WORK/home/public_html/backups" "$DUMP_PASS"
  local out status
  out=$(bash "$SCRIPT" "$env" 2>&1)
  status=$?
  if \
    [ "$status" -ne 0 ] && [[ $out == *"public_html"* ]] && [[ ! -e $WORK/home/public_html/backups ]] && no_secret "$out"; then
    pass "TC-14-04: BACKUP_DIR が public_html の下だと止まり、何も作らない"
  else
    fail "TC-14-04: BACKUP_DIR が public_html の下だと止まり、何も作らない" ""
  fi
}

tc_public_html_symlink() {
  local env=$WORK/www.env
  mkdir -p "$WORK/home/public_html"
  if ! ln -s "$WORK/home/public_html" "$WORK/home/www" 2>/dev/null || [[ ! -L $WORK/home/www ]]; then
    skip "TC-14-05: シンボリックリンク経由の public_html" "この環境ではシンボリックリンクを作れない"
    return
  fi
  write_env "$env" "$WORK/home/www/backups" "$DUMP_PASS"
  local out status
  out=$(bash "$SCRIPT" "$env" 2>&1)
  status=$?
  if \
    [ "$status" -ne 0 ] && [[ $out == *"public_html"* ]] && [[ ! -e $WORK/home/public_html/backups ]] && no_secret "$out"; then
    pass "TC-14-05: BACKUP_DIR がリンク(~/www など)経由で public_html を指すと止まる"
  else
    fail "TC-14-05: BACKUP_DIR がリンク(~/www など)経由で public_html を指すと止まる" ""
  fi
}

tc_public_html_db_symlink() {
  local env=$WORK/db-link.env
  mkdir -p "$WORK/home/public_html" "$WORK/home/backups"
  if ! ln -s "$WORK/home/public_html" "$WORK/home/backups/db" 2>/dev/null || [[ ! -L $WORK/home/backups/db ]]; then
    skip "TC-14-13: BACKUP_DIR/db のリンク経由の public_html" "この環境ではシンボリックリンクを作れない"
    return
  fi
  write_env "$env" "$WORK/home/backups" "$DUMP_PASS"
  local out status
  out=$(bash "$SCRIPT" "$env" 2>&1)
  status=$?
  if [ "$status" -ne 0 ] && [[ $out == *"public_html"* ]] && [[ -z $(ls -A "$WORK/home/public_html") ]] && no_secret "$out"; then
    pass "TC-14-13: BACKUP_DIR/db がリンクで public_html を指すと止まり、何も書かない"
  else
    fail "TC-14-13: BACKUP_DIR/db がリンクで public_html を指すと止まり、何も書かない" ""
  fi
}

# ---- DB を使うケース ----

tc_dump_and_restore() {
  local env=$WORK/ok.env backup=$WORK/backup tmp=$WORK/tmp-ok
  mkdir -p "$tmp"
  write_env "$env" "$backup" "$DUMP_PASS"
  local out status
  out=$(TMPDIR=$tmp bash "$SCRIPT" "$env" 2>&1)
  status=$?
  printf '%s\n' "$out" >"$WORK/ok.out"

  local gz=${out##*$'\n'}
  local stem=${gz%.sql.gz}
  if \
    [ "$status" -eq 0 ] && [[ $(basename "$gz") =~ ^dump_src-[0-9]{8}-[0-9]{6}\.sql\.gz$ ]] && [[ -f $gz ]]; then
    pass "TC-14-06: 終了コード 0 で <DB名>-<YYYYMMDD-HHMMSS>.sql.gz を作る"
  else
    fail "TC-14-06: 終了コード 0 で <DB名>-<YYYYMMDD-HHMMSS>.sql.gz を作る" ""
  fi
  if [[ $status -ne 0 || ! -f $gz ]]; then
    printf '    出力: %s\n' "$(mask "$out")"
    return
  fi

  if \
    [ "$(stat -c %a "$backup/db")" = 700 ] && [ "$(stat -c %a "$gz")" = 600 ] \
    && [ "$(stat -c %a "$stem.sha256")" = 600 ] && [ "$(stat -c %a "$stem.counts.tsv")" = 600 ]; then
    pass "TC-14-07: ディレクトリは 700、.sql.gz・.sha256・.counts.tsv は 600"
  else
    fail "TC-14-07: ディレクトリは 700、.sql.gz・.sha256・.counts.tsv は 600" ""
  fi

  if \
    bash -c 'cd "$1" && sha256sum -c --quiet "$2"' _ "$backup/db" "$(basename "$stem.sha256")"; then
    pass "TC-14-08: .sha256 が db/ の中で sha256sum -c に通る"
  else
    fail "TC-14-08: .sha256 が db/ の中で sha256sum -c に通る" ""
  fi

  local expected_counts
  expected_counts=$(printf 'bin_rows\t2\nitems\t3\nlogs\t0\n')
  if \
    [ "$(cat "$stem.counts.tsv")" = "$expected_counts" ]; then
    pass "TC-14-09: .counts.tsv にテーブルごとの行数が出る(ビューは出さない)"
  else
    fail "TC-14-09: .counts.tsv にテーブルごとの行数が出る(ビューは出さない)" ""
  fi

  admin -e "DROP DATABASE IF EXISTS dump_restore; CREATE DATABASE dump_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  gunzip -c "$gz" >"$WORK/dump.sql"
  admin dump_restore <"$WORK/dump.sql"
  local restore_status=$?
  local restored_counts
  restored_counts=$(for t in bin_rows items logs; do
    printf '%s\t%s\n' "$t" "$(admin -N -B dump_restore -e "SELECT COUNT(*) FROM \`$t\`")"
  done)
  if \
    [ "$restore_status" -eq 0 ] && [ "$restored_counts" = "$expected_counts" ] \
    && [ "$(table_digest dump_restore)" = "$(table_digest dump_src)" ] \
    && [ "$(admin -N -B -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='dump_restore'")" = 1 ] \
    && [ "$(admin -N -B -e "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='dump_restore'")" = 1 ] \
    && [ "$(admin -N -B -e "SELECT COUNT(*) FROM dump_src.items")" = 3 ] \
    && ! grep -Eq '^(CREATE DATABASE|USE )' "$WORK/dump.sql"; then
    pass "TC-14-10: gunzip | mysql で別の DB に入り、中身(絵文字・バイナリ・トリガー・ルーチン)が元と同じ"
  else
    fail "TC-14-10: gunzip | mysql で別の DB に入り、中身(絵文字・バイナリ・トリガー・ルーチン)が元と同じ" ""
  fi

  if \
    no_secret "$out" && ! grep -qF "$DUMP_PASS" "$WORK/dump.sql" \
    && [ -z "$(ls -A "$tmp")" ] && only_outputs_of "$backup/db" "$(basename "$stem")"; then
    pass "TC-14-11: 出力・ダンプの中にパスワードが出ず、一時ファイルが残らない"
  else
    fail "TC-14-11: 出力・ダンプの中にパスワードが出ず、一時ファイルが残らない" ""
  fi
}

tc_wrong_password() {
  local env=$WORK/wrong.env backup=$WORK/backup-wrong tmp=$WORK/tmp-wrong
  mkdir -p "$tmp"
  write_env "$env" "$backup" "$WRONG_PASS"
  local out status
  out=$(TMPDIR=$tmp bash "$SCRIPT" "$env" 2>&1)
  status=$?
  if \
    [ "$status" -ne 0 ] && [[ $out != *"$WRONG_PASS"* ]] && no_secret "$out" \
    && [ -z "$(ls -A "$tmp")" ] && [ -z "$(ls -A "$backup/db" 2>/dev/null)" ]; then
    pass "TC-14-12: パスワードが違うと失敗し、作りかけのダンプも一時ファイルも残さず、出力にパスワードが出ない"
  else
    fail "TC-14-12: パスワードが違うと失敗し、作りかけのダンプも一時ファイルも残さず、出力にパスワードが出ない" ""
  fi
}

# ---- 補助 ----

db_available() {
  [[ -n ${DUMP_TEST_DB_HOST:-} ]]
}

admin() {
  MYSQL_PWD=${DUMP_TEST_DB_ROOT_PASSWORD:-} "$(client)" --default-character-set=utf8mb4     -h "$DUMP_TEST_DB_HOST" -P "${DUMP_TEST_DB_PORT:-3306}" -u root "$@"
}

client() {
  if command -v mariadb >/dev/null 2>&1; then echo mariadb; else echo mysql; fi
}

# ダンプするユーザーは、サーバーと同じく自分の DB にだけ権限を持ち、ルーチン・トリガーの定義者でもある
setup_fixture() {
  FIXTURE_CREATED=1
  local sql_pass=${DUMP_PASS//\\/\\\\}
  sql_pass=${sql_pass//\'/\\\'}
  admin <<SQL || { fail "fixture" "テスト用の DB を作れない"; return 1; }
DROP DATABASE IF EXISTS dump_src;
DROP DATABASE IF EXISTS dump_restore;
DROP USER IF EXISTS 'dump_test'@'%';
CREATE DATABASE dump_src CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dump_test'@'%' IDENTIFIED BY '$sql_pass';
GRANT ALL PRIVILEGES ON dump_src.* TO 'dump_test'@'%';
USE dump_src;
CREATE TABLE items (id INT PRIMARY KEY, name VARCHAR(100) NOT NULL) CHARACTER SET utf8mb4;
INSERT INTO items VALUES (1, 'マティーニ'), (2, 'Mojito 🍹'), (3, 'It''s "quoted" \\\\ back');
CREATE TABLE bin_rows (id INT PRIMARY KEY, data BLOB NOT NULL);
INSERT INTO bin_rows VALUES (1, 0x00FF80C3283A0A0D27225C), (2, '');
CREATE TABLE logs (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL);
CREATE VIEW item_names AS SELECT name FROM items;
CREATE DEFINER='dump_test'@'%' TRIGGER items_after_insert AFTER INSERT ON items
  FOR EACH ROW INSERT INTO logs (item_id) VALUES (NEW.id);
CREATE DEFINER='dump_test'@'%' PROCEDURE item_count() SELECT COUNT(*) FROM items;
SQL
}

# 既知のパスワードのユーザーを共有の MariaDB に残さないよう、作ったものを消す
cleanup() {
  local status=$?
  if [[ $FIXTURE_CREATED -eq 1 ]]; then
    admin >/dev/null 2>&1 <<'SQL' || true
DROP DATABASE IF EXISTS dump_src;
DROP DATABASE IF EXISTS dump_restore;
DROP USER IF EXISTS 'dump_test'@'%';
SQL
  fi
  rm -rf "$WORK"
  exit "$status"
}

# 行の中身を比べる(バイナリは HEX にして比べる)
table_digest() {
  local db=$1
  admin -N -B "$db" -e "SELECT id, HEX(name) FROM items ORDER BY id; SELECT id, HEX(data) FROM bin_rows ORDER BY id; SELECT COUNT(*) FROM logs"
}

write_env() {
  local file=$1 backup_dir=$2 pass=$3
  local escaped=${pass//\\/\\\\}
  escaped=${escaped//\"/\\\"}
  {
    printf '# テスト用の .env\n'
    printf 'DB_CONNECTION=mariadb\n'
    printf 'DB_HOST=%s\n' "${DUMP_TEST_DB_HOST:-127.0.0.1}"
    printf 'DB_PORT=%s # コメント\n' "${DUMP_TEST_DB_PORT:-3306}"
    printf 'DB_DATABASE=other\n'
    printf 'DB_DATABASE=dump_src\n'
    printf "DB_USERNAME='dump_test'\n"
    printf 'DB_PASSWORD="%s"\r\n' "$escaped"
    printf 'BACKUP_DIR=%s\n' "$backup_dir"
  } >"$file"
}

# ディレクトリにあるのが <stem>.sql.gz・.sha256・.counts.tsv だけか(作りかけが残っていないか)
only_outputs_of() {
  local dir=$1 stem=$2 f
  for f in "$dir"/* "$dir"/.[!.]*; do
    [[ -e $f ]] || continue
    case ${f##*/} in
      "$stem.sql.gz" | "$stem.sha256" | "$stem.counts.tsv") ;;
      *) return 1 ;;
    esac
  done
}

no_secret() {
  [[ $1 != *"$DUMP_PASS"* ]]
}

mask() {
  local s=${1//"$DUMP_PASS"/***}
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
