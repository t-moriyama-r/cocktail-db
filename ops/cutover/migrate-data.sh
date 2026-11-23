#!/usr/bin/env bash
#
# 切り替えの手順 2〜6(docs/cutover.md)を順に流す。サーバー上で、書き込みを止めた(手順 1)後に流す。
#
#   ops/cutover/migrate-data.sh --old-env <旧アプリの .env> --old-storage <旧の画像のディレクトリ>
#                               --verify-db <復元を確かめる空の DB> [--new-app <新アプリのディレクトリ>]
#                               [--offsite <送り先の設定ファイル>] [--state-dir <進み具合を残すディレクトリ>]
#                               [--from <手順>] [--restart]
#
#   --new-app     新アプリのディレクトリ(省くと ~/cocktail-db)。.env と storage/app/public はこの下のものを使う
#   --offsite     手順 2 の組を B2 にも送る(daily-backup.sh の --offsite)
#   --state-dir   進み具合を残すディレクトリ(省くと ~/cocktail-ops/cutover-state)
#   --from        この手順と、その後ろの手順を「済み」から外して流し直す(例: 移行を直して 4 から流し直すときは --from migrate)
#   --restart     「済み」をすべて外し、手順 2 から流す(別の日に切り替えをやり直すとき)
#
# 手順(この順に流す。名前は --from に渡すもの):
#   backup        2・3  旧アプリの .env で daily-backup.sh を流し、ダンプを --verify-db に戻して旧 DB と比べる
#   dump-new      4.1   新 DB の今の中身を dump-db.sh で取っておく(戻すとき用)
#   migrate       4.2   php artisan migrate --force
#   import        4.2   php artisan legacy:import
#   copy-storage  5     copy-storage.sh で、旧の画像を新アプリの storage/app/public に写す
#   verify        6     php artisan legacy:verify と legacy:verify-images。verify-images の missing が、
#                       backup の退避の missing(旧から欠けていたもの)と同じ件数かを見る
#
# 何度流しても同じ結果になるように:
# - 通った手順は、--state-dir に「済み」として残し、次に流したときは飛ばす。落ちた手順から続ける
# - 済みを飛ばすので、新 DB のダンプ(dump-new)は、移す前の 1 回だけ取る(流し直しで、移した後の中身に置き換わらない)
# - 前に流したときと違う引数で流したら止める(混ざった進み具合で続けないため)。やり直すなら --restart
# - 中で呼ぶもの(daily-backup.sh・dump-db.sh・migrate・legacy:import・copy-storage.sh・legacy:verify*)は、
#   それぞれ同じ入力で何度流しても結果が変わらない(手順 2 は流すたびに組が 1 つ増えるが、済みにした後は流さない)
#
# 途中で失敗したら、その手順で止め、済みの手順・止まった手順・続け方を出す。手順ごとの出力は --state-dir の
# <手順>.log にも残る(かかった時間は済みの記録に残る。#79 の記録に使う)。
#
# 環境変数:
#   PHP  php artisan に使う PHP(省くと php。版を決めるなら /usr/local/bin/ea-php85 など。README の「サーバーの PHP の版」)
#
# 終了コード:
#   0  すべての手順が済んだ(前に済んでいたものを含む)
#   1  引数・設定のエラー、前と違う引数、または手順が失敗した(中で呼んだものが 1 を返した)
#   2  手順 6 の確かめが通らなかった・中身の違う同じ名前の画像があった(人が見て決める)
#   ほか  失敗した手順で中から返った終了コード
#
# 手順 1(書き込みを止める)・7(ドキュメントルートの切り替え)・9(新の cron)は cPanel での人の作業なので、ここでは流さない。

set -uo pipefail

STEPS=(backup dump-new migrate import copy-storage verify)

migrate_data() {
  local old_env='' old_storage='' verify_db='' new_app="$HOME/cocktail-db" offsite='' state_dir="$HOME/cocktail-ops/cutover-state"
  local from='' restart=0
  while [[ $# -gt 0 ]]; do
    case $1 in
      --old-env) require_value "$@"; old_env=$2; shift 2 ;;
      --old-storage) require_value "$@"; old_storage=$2; shift 2 ;;
      --verify-db) require_value "$@"; verify_db=$2; shift 2 ;;
      --new-app) require_value "$@"; new_app=$2; shift 2 ;;
      --offsite) require_value "$@"; offsite=$2; shift 2 ;;
      --state-dir) require_value "$@"; state_dir=$2; shift 2 ;;
      --from) require_value "$@"; from=$2; shift 2 ;;
      --restart) restart=1; shift ;;
      *) usage ;;
    esac
  done
  [[ -n $old_env && -n $old_storage && -n $verify_db ]] || usage
  [[ -z $from ]] || step_index "$from" >/dev/null || die "--from に知らない手順が渡された: $from(選べるもの: ${STEPS[*]})"
  [[ -f $old_env ]] || die "旧アプリの .env が見つからない: $old_env"
  [[ -d $old_storage ]] || die "旧の画像のディレクトリが見つからない: $old_storage"
  [[ -f $new_app/artisan ]] || die "新アプリのディレクトリに artisan が無い: $new_app"
  [[ -f $new_app/.env ]] || die "新アプリの .env が見つからない: $new_app/.env"
  [[ -d $new_app/storage/app/public ]] || die "新アプリの画像のディレクトリが見つからない: $new_app/storage/app/public"
  [[ -z $offsite || -f $offsite ]] || die "--offsite の設定ファイルが見つからない: $offsite"

  OLD_ENV=$(realpath -- "$old_env")
  OLD_STORAGE=$(realpath -- "$old_storage")
  NEW_APP=$(realpath -- "$new_app")
  VERIFY_DB=$verify_db
  OFFSITE=''
  [[ -z $offsite ]] || OFFSITE=$(realpath -- "$offsite")
  STATE_DIR=$state_dir

  (umask 077 && mkdir -p -- "$STATE_DIR") || die "進み具合のディレクトリを作れない: $STATE_DIR"
  if ((restart)); then
    forget_from 0
    rm -f -- "$STATE_DIR/args"
    log "--restart: 済みの記録をすべて外した"
  fi
  check_args
  if [[ -n $from ]]; then
    forget_from "$(step_index "$from")"
    log "--from $from: $from から後ろの済みの記録を外した"
  fi

  log "始める(進み具合: $STATE_DIR)"
  local step status
  for step in "${STEPS[@]}"; do
    if [[ -f $STATE_DIR/$step.done ]]; then
      log "[$step] 済み($(read_state "$step" finished))。飛ばす"
      continue
    fi
    log "[$step] 始める"
    rm -f -- "$STATE_DIR/$step.done.tmp"
    local started=$SECONDS
    "step_${step//-/_}"
    status=$?
    if ((status != 0)); then
      stopped "$step" "$status"
      exit "$status"
    fi
    # 記録を残せなければ(容量・inode が尽きたなど)、後ろへ進まずに止める。進むと、流し直しで新 DB のダンプを取り直してしまう
    if ! { write_state "$step" finished "$(date '+%Y-%m-%d %H:%M:%S')" \
      && write_state "$step" seconds "$((SECONDS - started))" \
      && mv -- "$STATE_DIR/$step.done.tmp" "$STATE_DIR/$step.done"; }; then
      log "[$step] 済みの記録を書けなかった。進み具合のディレクトリの空きを確かめてから、同じコマンドで流し直す: $STATE_DIR"
      exit 1
    fi
    log "[$step] 済んだ($((SECONDS - started)) 秒)"
  done
  log "すべての手順が済んだ。次は docs/cutover.md の 7(ドキュメントルートの切り替え)"
}

# --- 手順 ---

step_backup() {
  local args=("$OLD_ENV" --storage-dir "$OLD_STORAGE" --verify-restore "$VERIFY_DB") status
  [[ -z $OFFSITE ]] || args+=(--offsite "$OFFSITE")
  run_logged backup bash "$OPS/backup/daily-backup.sh" "${args[@]}"
  status=$?
  if ((status == 3)); then
    log "[backup] B2 には送れなかったが、サーバーの組は取れたので進む。後で offsite-upload.sh を手で流す"
  elif ((status != 0)); then
    log "[backup] daily-backup.sh が終了コード $status で終わった。ログの理由を直して流し直す(2 なら、書き込みが止まっているかを見る)"
    return "$status"
  fi
  local log_file=$STATE_DIR/backup.log dump check missing
  # daily-backup.sh は「ダンプ: <パス>」、archive-storage.sh は「check: <パス>」の行で書いた先を出す
  dump=$(sed -n 's/^.* daily-backup: ダンプ: //p' "$log_file" | tail -n 1)
  check=$(sed -n 's/^check: //p' "$log_file" | tail -n 1)
  [[ -n $dump && -f $dump ]] || { log "[backup] 出力からダンプのパスを読めない"; return 1; }
  [[ -n $check && -f $check ]] || { log "[backup] 出力から退避の突き合わせ(check.tsv)のパスを読めない"; return 1; }
  missing=$(grep -c "^missing"$'\t' "$check")
  [[ $missing =~ ^[0-9]+$ ]] || { log "[backup] check.tsv の missing を数えられない: $check"; return 1; }
  write_state backup dump "$dump" || return 1
  write_state backup check "$check" || return 1
  write_state backup missing "$missing" || return 1
  log "[backup] 旧のダンプ: $dump / 旧から欠けていた画像(missing): $missing"
}

step_dump_new() {
  # dump-db.sh は標準出力にダンプのパスだけを出す。エラーは標準エラーにそのまま出す
  local out=$STATE_DIR/dump-new.log dump
  bash "$OPS/backup/dump-db.sh" "$NEW_APP/.env" >"$out" || {
    local status=$?
    log "[dump-new] 新 DB のダンプに失敗した(終了コード $status)。新アプリの .env に BACKUP_DIR があるかを見る(docs/backup.md の「サーバーに入れる」の 2)"
    return "$status"
  }
  dump=$(tail -n 1 "$out")
  [[ -n $dump && -f $dump ]] || { log "[dump-new] 出力からダンプのパスを読めない"; return 1; }
  write_state dump-new dump "$dump" || return 1
  log "[dump-new] 新 DB の切り替え前のダンプ: $dump(戻すときは docs/cutover.md の 4 の戻し方)"
}

step_migrate() {
  run_logged migrate artisan migrate --force --no-interaction
}

step_import() {
  run_logged import artisan legacy:import --no-interaction || {
    local status=$?
    log "[import] 原因を直して流し直す(legacy:import は何度流しても結果が変わらない)"
    return "$status"
  }
}

step_copy_storage() {
  run_logged copy-storage bash "$OPS/migrate/copy-storage.sh" "$OLD_STORAGE" "$NEW_APP/storage/app/public" || {
    local status=$?
    if ((status == 2)); then
      log "[copy-storage] 中身の違う同じ名前のファイルがある。旧と新で見比べ、どちらを残すかを決めてから流し直す"
    fi
    return "$status"
  }
}

step_verify() {
  run_logged verify artisan legacy:verify --no-interaction || {
    log "[verify] legacy:verify が通らなかった。7 に進まない(docs/cutover.md の 6)"
    return 2
  }
  local log_file=$STATE_DIR/verify-images.log status=0 missing expected
  run_logged verify-images artisan legacy:verify-images --no-interaction || status=$?
  missing=$(sed -n 's/^missing: //p' "$log_file" | tail -n 1)
  expected=$(read_state backup missing)
  if [[ ! $missing =~ ^[0-9]+$ ]]; then
    log "[verify] legacy:verify-images の出力から missing を読めない(終了コード $status)"
    return 2
  fi
  if ((status != 0 && missing == 0)); then
    log "[verify] legacy:verify-images が終了コード $status で終わった(missing は 0)"
    return 2
  fi
  if [[ $missing != "$expected" ]]; then
    log "[verify] legacy:verify-images の missing($missing)が、旧から欠けていた件数($expected)と違う。7 に進まない"
    return 2
  fi
  # 欠けがあると verify-images は 1 を返す。旧から欠けていた分だけなら通す
  log "[verify] 画像の missing は $missing 件で、旧から欠けていた件数と同じ"
}

# --- 下回り ---

HERE=$(cd "$(dirname "$0")" && pwd)
OPS=$(dirname "$HERE")

usage() {
  sed -n '5,8p' "$0" >&2
  exit 1
}

die() {
  log "$*"
  exit 1
}

log() {
  printf '%s migrate-data: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

require_value() {
  [[ $# -ge 2 && -n $2 && $2 != -* ]] || die "$1 に値を渡す"
}

step_index() {
  local i
  for i in "${!STEPS[@]}"; do
    [[ ${STEPS[$i]} != "$1" ]] || { echo "$i"; return 0; }
  done
  return 1
}

# i 番目から後ろの手順の済みの記録を消す
forget_from() {
  local i
  for ((i = $1; i < ${#STEPS[@]}; i++)); do
    rm -f -- "$STATE_DIR/${STEPS[$i]}.done" "$STATE_DIR/${STEPS[$i]}.done.tmp"
  done
}

# 前に流したときと同じ引数かを確かめる。初めてなら残す
check_args() {
  local current
  current=$(printf 'old-env=%s\nold-storage=%s\nverify-db=%s\nnew-app=%s\noffsite=%s\n' \
    "$OLD_ENV" "$OLD_STORAGE" "$VERIFY_DB" "$NEW_APP" "$OFFSITE")
  if [[ -f $STATE_DIR/args ]]; then
    if [[ $(cat -- "$STATE_DIR/args") != "$current" ]]; then
      log "前に流したときと引数が違う。前の引数:"
      sed 's/^/  /' -- "$STATE_DIR/args"
      die "同じ引数で流し直すか、やり直すなら --restart を付ける"
    fi
  else
    printf '%s\n' "$current" >"$STATE_DIR/args" || die "進み具合を書けない: $STATE_DIR/args"
  fi
}

# 済みの記録は <手順>.done.tmp に書き、手順が通ったら .done に置く(途中で落ちた記録を済みと見なさないため)
write_state() {
  printf '%s=%s\n' "$2" "$3" >>"$STATE_DIR/$1.done.tmp"
}

read_state() {
  local file=$STATE_DIR/$1.done
  [[ -f $file ]] || file=$STATE_DIR/$1.done.tmp
  sed -n "s/^$2=//p" "$file" 2>/dev/null | tail -n 1
}

# 出力を画面と <名前>.log の両方に出し、コマンドの終了コードを返す
run_logged() {
  local name=$1
  shift
  "$@" 2>&1 | tee -- "$STATE_DIR/$name.log"
  return "${PIPESTATUS[0]}"
}

artisan() {
  (cd -- "$NEW_APP" && "${PHP:-php}" artisan "$@")
}

stopped() {
  local step=$1 status=$2 s done=()
  rm -f -- "$STATE_DIR/$step.done.tmp"
  for s in "${STEPS[@]}"; do
    [[ ! -f $STATE_DIR/$s.done ]] || done+=("$s")
  done
  log "[$step] で止まった(終了コード $status)"
  log "済みの手順: ${done[*]:-(無し)}"
  log "止まった手順: $step(出力は $STATE_DIR/$step.log)"
  log "直してから同じコマンドを流すと、$step から続ける"
}

migrate_data "$@"
