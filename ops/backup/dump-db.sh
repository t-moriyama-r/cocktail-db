#!/usr/bin/env bash
#
# アプリの DB をダンプする。サーバー上で実行する前提。
#
#   ops/backup/dump-db.sh <アプリの .env のパス>
#
# .env から読むもの:
#   DB_DATABASE・DB_USERNAME・BACKUP_DIR  必須
#   DB_PASSWORD・DB_HOST・DB_PORT・DB_SOCKET  あれば使う(DB_HOST が無ければクライアントの既定)
#
# 出すもの(BACKUP_DIR/db の下。ディレクトリは 700、ファイルは 600):
#   <DB名>-<YYYYMMDD-HHMMSS>.sql.gz      ダンプ。CREATE DATABASE・USE を含まないので、
#                                         `gunzip -c X.sql.gz | mysql <別の DB>` で別の DB に入れられる
#   <DB名>-<YYYYMMDD-HHMMSS>.sha256      .sql.gz のハッシュ。db/ の中で `sha256sum -c X.sha256` で確かめる
#   <DB名>-<YYYYMMDD-HHMMSS>.counts.tsv  テーブルごとの行数(`テーブル名<TAB>行数`)。復元した後の突き合わせに使う
# 標準出力には、できた .sql.gz のパスだけを出す。
#
# 前提と決めごと:
# - パスワードはコマンドラインに出さない(ps で見えるため)。権限 600 の一時ファイルに書き、
#   --defaults-extra-file で渡して、終わったら消す。エラーのメッセージにも値は出さない
# - .env は source しない(値の $ やスペースがシェルとして解釈されるため)。KEY=VALUE を 1 行ずつ読み、
#   囲みの "..."・'...' を外す。"..." の中の \" と \\ は戻す。${VAR} の展開はしない
# - BACKUP_DIR は絶対パス(先頭の ~/ は $HOME にする)。シンボリックリンクを解決した先に public_html が
#   入っていたら止める(cPanel では ~/www が public_html へのリンクのため)。Web から見える場所にダンプを置かない
# - 行数はダンプの後に数える。ダンプ中に書き込みがあると、ダンプの中身と少しずれることがある
# - 定期実行は daily-backup.sh から行う(docs/backup.md)

set -euo pipefail

dump_db() {
  if [[ $# -ne 1 ]]; then
    die "使い方: $0 <アプリの .env のパス>"
  fi
  local env_file=$1
  [[ -f $env_file && -r $env_file ]] || die ".env が読めない: $env_file"

  local db_name db_user db_pass db_host db_port db_socket backup_dir
  db_name=$(require_env "$env_file" DB_DATABASE)
  db_user=$(require_env "$env_file" DB_USERNAME)
  backup_dir=$(require_env "$env_file" BACKUP_DIR)
  db_pass=$(read_env "$env_file" DB_PASSWORD)
  db_host=$(read_env "$env_file" DB_HOST)
  db_port=$(read_env "$env_file" DB_PORT)
  db_socket=$(read_env "$env_file" DB_SOCKET)

  local out_dir
  # db がリンクで public_html を指すこともあるので、db まで含めて解決する
  out_dir=$(resolve_backup_dir "$backup_dir/db")

  local dump_cmd client_cmd
  dump_cmd=$(find_command mariadb-dump mysqldump)
  client_cmd=$(find_command mariadb mysql)

  umask 077
  OPTION_FILE=$(mktemp "${TMPDIR:-/tmp}/dump-db.XXXXXXXX.cnf")
  trap cleanup EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  write_option_file "$OPTION_FILE" "$db_user" "$db_pass" "$db_host" "$db_port" "$db_socket"

  mkdir -p "$out_dir"
  chmod 700 "$out_dir"

  local stem
  stem="$out_dir/$db_name-$(date +%Y%m%d-%H%M%S)"
  if [[ -e $stem.sql.gz ]]; then
    die "同じ名前のダンプがもうある: $stem.sql.gz"
  fi

  # 途中で落ちたときに不完全なファイルが正規の名前で残らないよう、3 つとも作りかけの名前で書き上げてから名前を付ける
  local gz_part sha_part counts_part
  gz_part=$(mktemp "$out_dir/.dump-db.XXXXXXXX.partial")
  PARTIAL_FILES+=("$gz_part")
  sha_part=$(mktemp "$out_dir/.dump-db.XXXXXXXX.partial")
  PARTIAL_FILES+=("$sha_part")
  counts_part=$(mktemp "$out_dir/.dump-db.XXXXXXXX.partial")
  PARTIAL_FILES+=("$counts_part")

  "$dump_cmd" --defaults-extra-file="$OPTION_FILE" \
    --single-transaction --routines --triggers --hex-blob \
    --default-character-set=utf8mb4 --no-tablespaces \
    "$db_name" | gzip -c >"$gz_part"

  local hash
  hash=$(sha256sum <"$gz_part")
  printf '%s  %s\n' "${hash%% *}" "$(basename "$stem.sql.gz")" >"$sha_part"

  count_rows "$client_cmd" "$db_name" >"$counts_part"

  chmod 600 "$gz_part" "$sha_part" "$counts_part"
  mv "$counts_part" "$stem.counts.tsv"
  mv "$sha_part" "$stem.sha256"
  mv "$gz_part" "$stem.sql.gz"
  PARTIAL_FILES=()

  printf '%s\n' "$stem.sql.gz"
}

OPTION_FILE=
PARTIAL_FILES=()

die() {
  printf 'dump-db: %s\n' "$*" >&2
  exit 1
}

cleanup() {
  if [[ -n $OPTION_FILE ]]; then rm -f "$OPTION_FILE"; fi
  if [[ ${#PARTIAL_FILES[@]} -gt 0 ]]; then rm -f "${PARTIAL_FILES[@]}"; fi
}

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

resolve_backup_dir() {
  local dir=$1
  if [[ $dir == \~/* ]]; then
    dir=$HOME/${dir#"~/"}
  fi
  [[ $dir == /* ]] || die "BACKUP_DIR は絶対パスで書く"

  local resolved
  resolved=$(realpath -m -- "$dir") || die "BACKUP_DIR を解決できない"
  if [[ /$resolved/ == */public_html/* ]]; then
    die "BACKUP_DIR が公開ディレクトリ(public_html)の下にある。公開ディレクトリの外にする"
  fi
  printf '%s' "$resolved"
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

# ビューは行数を持たないので、実テーブル(BASE TABLE)だけを数える
count_rows() {
  local client=$1 db=$2 table
  local tables
  tables=$("$client" --defaults-extra-file="$OPTION_FILE" --batch --skip-column-names "$db" \
    -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
  while IFS= read -r table; do
    [[ -n $table ]] || continue
    local count
    count=$("$client" --defaults-extra-file="$OPTION_FILE" --batch --skip-column-names "$db" \
      -e "SELECT COUNT(*) FROM \`${table//\`/\`\`}\`")
    printf '%s\t%s\n' "$table" "$count"
  done <<<"$tables"
}

dump_db "$@"
