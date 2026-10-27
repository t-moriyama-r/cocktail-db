#!/usr/bin/env bash
#
# DB のダンプ(dump-db.sh が作った .sql.gz)を使い捨ての DB に復元し、元の DB と中身が同じかを確かめる。
# サーバー上で実行する前提。
#
#   ops/backup/verify-restore.sh <アプリの .env のパス> <ダンプ(.sql.gz)> <復元先の DB 名>
#
# .env から読むもの(dump-db.sh と同じ読み方):
#   DB_DATABASE・DB_USERNAME  必須。DB_DATABASE が比べる元の DB
#   DB_PASSWORD・DB_HOST・DB_PORT・DB_SOCKET  あれば使う
#
# 確かめること:
#   実テーブル(BASE TABLE)の一覧が両方で同じで、テーブルごとの行数と CHECKSUM TABLE の値が一致すること。
#   ビューは行数を持たないので比べない
#
# 終了コード:
#   0  一致した
#   1  引数・設定・接続のエラー、復元先が元の DB と同じ名前・空でない(何も復元しない)
#   2  検証に通らなかった(復元できない・テーブルの一覧・行数・チェックサムのどれかが違う)
#
# 前提と決めごと:
# - 復元先の DB は前もって作っておき、.env のユーザーに権限を付けておく(cPanel ではアプリのユーザーが
#   SQL で DB を作れないため、このスクリプトは DB を作らない・消さない)
# - 復元先が元の DB と同じ名前(大文字小文字を問わない)なら止める。ダンプの DROP TABLE で元の DB を消さないため
# - 復元先にテーブル・ビュー・ルーチンが 1 つでもあれば止める。名前を取り違えて渡された DB を上書きしないため
# - 終わったら(一致しても・しなくても)、復元したテーブル・ビュー・ルーチンを消し、復元先を空に戻す。
#   本番のデータの写しをサーバーにもう 1 つ残さないため
# - 元の DB は今の中身と比べる。ダンプを取った後に書き込みがあれば、一致しない(2 で終わる)。
#   ダンプの直後に動かす
# - CHECKSUM TABLE は行の中身だけでなく列の型・行の形式にもよる。同じ MariaDB の版で取ったダンプを
#   同じサーバーに戻す使い方を前提にしている
# - パスワードはコマンドラインに出さない。dump-db.sh と同じく権限 600 の一時ファイルに書き、
#   --defaults-extra-file で渡して、終わったら消す

set -euo pipefail

verify_restore() {
  if [[ $# -ne 3 ]]; then
    die "使い方: $0 <アプリの .env のパス> <ダンプ(.sql.gz)> <復元先の DB 名>"
  fi
  local env_file=$1 dump_file=$2 scratch_db=$3
  [[ -f $env_file && -r $env_file ]] || die ".env が読めない: $env_file"
  [[ -f $dump_file && -r $dump_file ]] || die "ダンプが読めない: $dump_file"
  [[ -n $scratch_db ]] || die "復元先の DB 名が空"

  local source_db db_user db_pass db_host db_port db_socket
  source_db=$(require_env "$env_file" DB_DATABASE)
  db_user=$(require_env "$env_file" DB_USERNAME)
  db_pass=$(read_env "$env_file" DB_PASSWORD)
  db_host=$(read_env "$env_file" DB_HOST)
  db_port=$(read_env "$env_file" DB_PORT)
  db_socket=$(read_env "$env_file" DB_SOCKET)

  if [[ ${scratch_db,,} == "${source_db,,}" ]]; then
    die "復元先が元の DB と同じ名前($scratch_db)。使い捨ての DB を指定する"
  fi

  CLIENT=$(find_command mariadb mysql)

  umask 077
  OPTION_FILE=$(mktemp "${TMPDIR:-/tmp}/verify-restore.XXXXXXXX.cnf")
  trap cleanup EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  write_option_file "$OPTION_FILE" "$db_user" "$db_pass" "$db_host" "$db_port" "$db_socket"

  local objects
  objects=$(sql_in "$scratch_db" "$(scratch_objects_sql)") || die "復元先の DB に接続できない: $scratch_db"
  if [[ -n $objects ]]; then
    die "復元先の DB が空でない: $scratch_db(テーブル・ビュー・ルーチンを消してから使う)"
  fi
  sql_in "$source_db" "SELECT 1" >/dev/null || die "元の DB に接続できない: $source_db"

  # ここから先で落ちても、復元したものを消す
  SCRATCH_DB=$scratch_db

  if ! gunzip -c "$dump_file" | "$CLIENT" --defaults-extra-file="$OPTION_FILE" "$scratch_db"; then
    printf 'verify-restore: 復元できない: %s\n' "$dump_file" >&2
    return 2
  fi

  local source_summary scratch_summary
  source_summary=$(table_summary "$source_db") || die "元の DB のテーブルを読めない"
  scratch_summary=$(table_summary "$scratch_db") || die "復元先の DB のテーブルを読めない"

  if [[ $source_summary != "$scratch_summary" ]]; then
    printf 'verify-restore: 元の DB と復元した DB が一致しない(テーブル<TAB>行数<TAB>チェックサム。- が元、+ が復元)\n' >&2
    diff <(printf '%s\n' "$source_summary") <(printf '%s\n' "$scratch_summary") | grep '^[<>]' | sed 's/^</-/; s/^>/+/' >&2 || true
    return 2
  fi

  local tables
  tables=$(grep -c . <<<"$source_summary" || true)
  printf 'verify-restore: 一致した(%s テーブル)\n' "$tables"
}

OPTION_FILE=
CLIENT=
SCRATCH_DB=

die() {
  printf 'verify-restore: %s\n' "$*" >&2
  exit 1
}

cleanup() {
  local status=$?
  if [[ -n $SCRATCH_DB ]]; then
    if ! empty_scratch "$SCRATCH_DB"; then
      printf 'verify-restore: 復元先の DB を空に戻せなかった: %s(手で消す)\n' "$SCRATCH_DB" >&2
      [[ $status -ne 0 ]] || status=1
    fi
  fi
  if [[ -n $OPTION_FILE ]]; then rm -f "$OPTION_FILE"; fi
  exit "$status"
}

# 実テーブルごとに「テーブル<TAB>行数<TAB>チェックサム」を名前の順に出す
table_summary() {
  local db=$1 tables table quoted count checksum
  tables=$(sql_in "$db" "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") || return 1
  while IFS= read -r table; do
    [[ -n $table ]] || continue
    quoted=$(quote_identifier "$table")
    count=$(sql_in "$db" "SELECT COUNT(*) FROM $quoted") || return 1
    # CHECKSUM TABLE は「<DB>.<テーブル><TAB>値」を返すので、DB 名の違う 2 つを比べられるよう値だけを取る
    checksum=$(sql_in "$db" "CHECKSUM TABLE $quoted") || return 1
    printf '%s\t%s\t%s\n' "$table" "$count" "${checksum##*$'\t'}"
  done <<<"$tables"
}

# 外部キーの順に関わらず消せるよう、外部キーの検査を切ってから消す
empty_scratch() {
  local db=$1 statements
  statements=$(sql_in "$db" "
    SELECT CONCAT('DROP ', CASE TABLE_TYPE WHEN 'VIEW' THEN 'VIEW' WHEN 'SEQUENCE' THEN 'SEQUENCE' ELSE 'TABLE' END,
                  ' IF EXISTS \`', REPLACE(TABLE_NAME, '\`', '\`\`'), '\`;')
      FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
    UNION ALL
    SELECT CONCAT('DROP ', ROUTINE_TYPE, ' IF EXISTS \`', REPLACE(ROUTINE_NAME, '\`', '\`\`'), '\`;')
      FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()") || return 1
  if [[ -n $statements ]]; then
    sql_in "$db" "SET FOREIGN_KEY_CHECKS = 0; $statements" >/dev/null || return 1
  fi
  [[ -z $(sql_in "$db" "$(scratch_objects_sql)") ]]
}

scratch_objects_sql() {
  printf '%s' "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
    UNION ALL SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()"
}

sql_in() {
  local db=$1 sql=$2
  "$CLIENT" --defaults-extra-file="$OPTION_FILE" --batch --skip-column-names "$db" -e "$sql"
}

quote_identifier() {
  printf '`%s`' "${1//\`/\`\`}"
}

# 以下の .env の読み方とオプションファイルの書き方は dump-db.sh と同じ

# .env にキーが無いときは、名前だけを出して止める(値は出さない)
require_env() {
  local file=$1 key=$2
  if ! grep -Eq "^[[:space:]]*(export[[:space:]]+)?${key}[[:space:]]*=" "$file"; then
    die ".env に $key が無い"
  fi
  local value
  value=$(read_env "$file" "$key")
  [[ -n $value ]] || die ".env の $key が空"
  printf '%s' "$value"
}

# Laravel の .env と同じく、同じキーが何度もあるときは最後のものを使う
read_env() {
  local file=$1 key=$2 line raw=''
  local pattern="^[[:space:]]*(export[[:space:]]+)?${key}[[:space:]]*=(.*)$"
  while IFS= read -r line || [[ -n $line ]]; do
    line=${line%$'\r'}
    if [[ $line =~ $pattern ]]; then
      raw=${BASH_REMATCH[2]}
    fi
  done <"$file"
  unquote_env_value "$raw"
}

unquote_env_value() {
  local v=$1
  v=${v#"${v%%[![:space:]]*}"}
  if [[ $v == \"* ]]; then
    # "..." の中では \" と \\ を戻し、閉じの " で終わる
    local out='' i ch
    for ((i = 1; i < ${#v}; i++)); do
      ch=${v:i:1}
      if [[ $ch == \\ && $((i + 1)) -lt ${#v} ]]; then
        local next=${v:i+1:1}
        if [[ $next == \" || $next == \\ ]]; then
          out+=$next
          ((i++)) || true
          continue
        fi
      fi
      [[ $ch == \" ]] && break
      out+=$ch
    done
    printf '%s' "$out"
  elif [[ $v == \'* ]]; then
    v=${v#\'}
    printf '%s' "${v%%\'*}"
  else
    # 囲みの無い値では、空白の後の # からはコメント
    v=${v%%[[:space:]]#*}
    v=${v%"${v##*[![:space:]]}"}
    printf '%s' "$v"
  fi
}

find_command() {
  local name
  for name in "$@"; do
    if command -v "$name" >/dev/null 2>&1; then
      printf '%s' "$name"
      return
    fi
  done
  die "$* のどれも見つからない"
}

# オプションファイルでは " と \ がエスケープとして読まれるため、値は "..." で囲んでエスケープする
write_option_file() {
  local file=$1 user=$2 pass=$3 host=$4 port=$5 socket=$6
  {
    printf '[client]\n'
    printf 'user="%s"\n' "$(escape_option_value "$user")"
    printf 'password="%s"\n' "$(escape_option_value "$pass")"
    printf 'default-character-set=utf8mb4\n'
    if [[ -n $host ]]; then printf 'host="%s"\n' "$(escape_option_value "$host")"; fi
    if [[ -n $port ]]; then printf 'port=%s\n' "$port"; fi
    if [[ -n $socket ]]; then printf 'socket="%s"\n' "$(escape_option_value "$socket")"; fi
  } >"$file"
  chmod 600 "$file"
}

escape_option_value() {
  local v=$1
  v=${v//\\/\\\\}
  v=${v//\"/\\\"}
  printf '%s' "$v"
}

verify_restore "$@"
