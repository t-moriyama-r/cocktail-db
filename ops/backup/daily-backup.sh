#!/usr/bin/env bash
#
# 毎日のバックアップ。DB のダンプ(dump-db.sh)と画像の退避(archive-storage.sh)を 1 組取り、
# 古い世代を消す(prune-backups.sh)。サーバーの cron から動かす前提。
#
#   ops/backup/daily-backup.sh <アプリの .env のパス> [--storage-dir <画像のディレクトリ>]
#                              [--verify-restore <復元先の DB 名>] [--offsite <送り先の設定ファイル>]
#                              [--daily N] [--weekly N]
#
#   --storage-dir     archive-storage.sh に渡す画像のディレクトリ(省くと archive-storage.sh の既定)
#   --verify-restore  ダンプの直後に verify-restore.sh で、ダンプを使い捨ての DB に戻して元と比べる(月 1 回の確かめ)
#   --offsite         その日の組を offsite-upload.sh で暗号化して Backblaze B2 に送る(設定ファイルの中身は offsite-upload.sh)
#   --daily・--weekly  prune-backups.sh に渡す世代数(省くと prune-backups.sh の既定)
#
# 終了コード:
#   0  ダンプ・退避(・復元の確かめ)がすべて通り、古い世代を消した
#   1  引数・設定のエラー、ダンプに失敗した
#   2  復元の確かめ・退避の検証に通らなかった(verify-restore.sh・archive-storage.sh が 2 を返した)
#   3  バックアップは取れて古い世代も消したが、B2 に送れなかった(--offsite のとき。ほかの失敗があればそちらの終了コード)
#   ほか  失敗したスクリプトの終了コード
#
# 前提と決めごと:
# - どれかが失敗したら、古い世代を消さない。失敗が続いても、最後に取れた良い組が消えないようにするため
# - 復元の確かめはダンプのすぐ後に流す。元の DB を今の中身と比べるので、間に書き込みがあると一致しない
# - 復元の確かめが通らなくても、画像の退避は取る(その日のバックアップを欠かさないため)。終了コードで知らせる
# - 世代を消すのは、dump-db.sh が書いた先(BACKUP_DIR を解決したもの)の下。archive-storage.sh の書いた先が
#   その storage/ でなければ止める(BACKUP_DIR の読み方が 2 つのスクリプトで違うと、片方だけ消し損ねるため)
# - B2 に送るのは、ダンプと退避がどちらも取れた日だけ(欠けた組は送らない)。復元の確かめが通らなかった日も、
#   組はそろっているので送る
# - B2 に送れなくても、古い世代は消す。サーバーの中のバックアップは取れていて、世代はその組の日時で数えるため、
#   送れない日が続いてもサーバーの最新の組は残る。止めると、送れない間サーバーの容量だけが増え続ける。
#   送れなかったことは終了コード 3 とログで知らせる

set -uo pipefail

daily_backup() {
  local env_file='' storage_dir='' scratch_db='' offsite_config='' prune_args=()
  while [[ $# -gt 0 ]]; do
    case $1 in
      --storage-dir) require_value "$@"; storage_dir=$2; shift 2 ;;
      --verify-restore) require_value "$@"; scratch_db=$2; shift 2 ;;
      --offsite) require_value "$@"; offsite_config=$2; shift 2 ;;
      --daily | --weekly) require_value "$@"; prune_args+=("$1" "$2"); shift 2 ;;
      -*) usage ;;
      *)
        [[ -z $env_file ]] || usage
        env_file=$1
        shift
        ;;
    esac
  done
  [[ -n $env_file ]] || usage
  # 世代数の誤りで、バックアップを取った後に世代を消せずに終わらないよう、先に確かめる
  bash "$HERE/prune-backups.sh" --check "${prune_args[@]}" || exit 1

  log "始める"

  local dump status=0
  dump=$(bash "$HERE/dump-db.sh" "$env_file") || {
    status=$?
    log "ダンプに失敗した(終了コード $status)。退避も世代の削除もしない"
    exit "$status"
  }
  [[ -f $dump ]] || die "dump-db.sh の出力がファイルでない: $dump"
  log "ダンプ: $dump"
  local backup_dir
  backup_dir=$(dirname "$(dirname "$dump")")

  if [[ -n $scratch_db ]]; then
    if bash "$HERE/verify-restore.sh" "$env_file" "$dump" "$scratch_db"; then
      log "復元の確かめが通った"
    else
      status=$?
      log "復元の確かめが通らなかった(終了コード $status)"
    fi
  fi

  local archive_args=("$env_file") archive_out archive_status=0 offsite_failed=0
  [[ -z $storage_dir ]] || archive_args+=("$storage_dir")
  archive_out=$(bash "$HERE/archive-storage.sh" "${archive_args[@]}") || archive_status=$?
  [[ -z $archive_out ]] || printf '%s\n' "$archive_out"
  if ((archive_status != 0)); then
    log "画像の退避に失敗した(終了コード $archive_status)"
    ((status != 0)) || status=$archive_status
  else
    if check_archive_dir "$archive_out" "$backup_dir"; then
      [[ -z $offsite_config ]] || send_offsite "$offsite_config" "$dump" "$archive_out" || offsite_failed=1
    else
      ((status != 0)) || status=1
    fi
  fi

  if ((status != 0)); then
    log "失敗があったので、古い世代は消さない(終了コード $status)"
    exit "$status"
  fi

  bash "$HERE/prune-backups.sh" "$backup_dir" "${prune_args[@]}" || {
    status=$?
    log "古い世代を消せなかった(終了コード $status)"
    exit "$status"
  }
  if ((offsite_failed)); then
    log "古い世代は消したが、B2 に送れなかった(終了コード 3)"
    exit 3
  fi
  log "終わった"
}

HERE=$(cd "$(dirname "$0")" && pwd)

usage() {
  sed -n '6,8p' "$0" >&2
  exit 1
}

die() {
  log "$*"
  exit 1
}

log() {
  printf '%s daily-backup: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

require_value() {
  [[ $# -ge 2 && -n $2 && $2 != -* ]] || die "$1 に値を渡す"
}

# archive-storage.sh は「archive: <パス>」の行で書いた先を出す
check_archive_dir() {
  local out=$1 backup_dir=$2 archive
  archive=$(sed -n 's/^archive: //p' <<<"$out" | tail -n 1)
  if [[ -z $archive ]]; then
    log "archive-storage.sh の出力に archive: の行が無い"
    return 1
  fi
  local actual expected
  actual=$(realpath -m -- "$(dirname "$archive")")
  expected=$(realpath -m -- "$backup_dir/storage")
  if [[ $actual != "$expected" ]]; then
    log "画像の退避先($actual)が、ダンプの置き場所の storage/($expected)と違う。BACKUP_DIR を ~/ を使わない絶対パスで書く"
    return 1
  fi
}

send_offsite() {
  local config=$1 dump=$2 out=$3 archive
  archive=$(sed -n 's/^archive: //p' <<<"$out" | tail -n 1)
  if bash "$HERE/offsite-upload.sh" "$config" "$dump" "$archive"; then
    log "B2 に送った"
  else
    log "B2 に送れなかった(offsite-upload.sh の終了コード $?)"
    return 1
  fi
}

daily_backup "$@"
