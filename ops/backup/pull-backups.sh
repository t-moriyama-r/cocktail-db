#!/usr/bin/env bash
#
# サーバーの BACKUP_DIR を、手元の PC に rsync で取る。週 1 回、手元(WSL などの bash)で動かす前提。
#
#   ops/backup/pull-backups.sh <取り元> <手元の保管先> [--daily N] [--weekly N]
#
#   <取り元>        rsync の取り元。サーバーなら <user>@<host>:<BACKUP_DIR>(ssh で繋ぐ)
#   <手元の保管先>  取ったものを置くディレクトリ。無ければ作る(700)
#   --daily・--weekly  取った後に prune-backups.sh で手元に残す世代数(既定 7・4。サーバーと同じ)
#
# 取るのは db/ と storage/ の中身だけ。名前が . で始まるもの(作りかけの .partial・作業用の .work.*)は取らない。
#
# 終了コード:
#   0  取って、ハッシュが合い、手元の古い世代を消した
#   1  引数のエラー、rsync・sha256sum が無い、rsync が失敗した
#   2  ハッシュの合わない・ハッシュの無いファイルがある(手元の世代は消さない)
#
# 前提と決めごと:
# - --delete を付けない。サーバーのバックアップが消された・壊されたときに、手元の写しまで消さないため。
#   手元の世代は、取った後に prune-backups.sh で別に数えて消す(今日の日付ではなく、ある組の日時で数える)
# - 取った後に、手元のすべての組を .sha256 で確かめる。合わないものがあれば世代を消さない
# - ssh の鍵・接続先は ~/.ssh/config に書いて、取り元には Host の別名を使う(このスクリプトには書かない)

set -euo pipefail

pull_backups() {
  local src='' dest='' daily=7 weekly=4
  while [[ $# -gt 0 ]]; do
    case $1 in
      --daily) require_value "$@"; daily=$2; shift 2 ;;
      --weekly) require_value "$@"; weekly=$2; shift 2 ;;
      -*) usage ;;
      *)
        if [[ -z $src ]]; then
          src=$1
        elif [[ -z $dest ]]; then
          dest=$1
        else
          usage
        fi
        shift
        ;;
    esac
  done
  [[ -n $src && -n $dest ]] || usage
  command -v rsync >/dev/null 2>&1 || die "rsync が無い(WSL なら sudo apt install rsync)"
  command -v sha256sum >/dev/null 2>&1 || die "sha256sum が無い"

  umask 077
  mkdir -p -- "$dest"
  chmod 700 "$dest"

  # . で始まるものを先に外し、db/・storage/ の下だけを取る(それ以外の直下のものは取らない)
  rsync -a --chmod=D700,F600 \
    --exclude='.*' --include='/db/***' --include='/storage/***' --exclude='*' \
    "${src%/}/" "${dest%/}/" || die "rsync が失敗した(終了コード $?)"

  local bad=0
  check_hashes "$dest/db" '*.sha256' || bad=1
  check_hashes "$dest/storage" '*.tar.gz.sha256' || bad=1
  missing_hashes "$dest" || bad=1
  if ((bad)); then
    printf 'pull-backups: ハッシュの合わないファイルがあるので、手元の古い世代は消さない\n' >&2
    exit 2
  fi

  bash "$HERE/prune-backups.sh" "$dest" --daily "$daily" --weekly "$weekly"
}

HERE=$(cd "$(dirname "$0")" && pwd)

usage() {
  sed -n '5,9p' "$0" >&2
  exit 1
}

die() {
  printf 'pull-backups: %s\n' "$*" >&2
  exit 1
}

require_value() {
  [[ $# -ge 2 && -n $2 && $2 != -* ]] || die "$1 に値を渡す"
}

# .sha256 はディレクトリの中で `sha256sum -c` する形(ファイル名だけ)で書いてある
check_hashes() {
  local dir=$1 glob=$2 sum ok=0
  [[ -d $dir ]] || return 0
  for sum in "$dir"/$glob; do
    [[ -f $sum ]] || continue
    if ! (cd "$dir" && sha256sum -c --quiet -- "${sum##*/}" >&2); then
      printf 'pull-backups: ハッシュが合わない: %s\n' "$sum" >&2
      ok=1
    fi
  done
  return "$ok"
}

# 本体(.sql.gz・.tar.gz)があってハッシュの無いものは、確かめられないので失敗にする
missing_hashes() {
  local dest=$1 path ok=0
  for path in "$dest"/db/*.sql.gz; do
    [[ -f $path ]] || continue
    [[ -f ${path%.sql.gz}.sha256 ]] || { printf 'pull-backups: ハッシュが無い: %s\n' "$path" >&2; ok=1; }
  done
  for path in "$dest"/storage/*.tar.gz; do
    [[ -f $path ]] || continue
    [[ -f $path.sha256 ]] || { printf 'pull-backups: ハッシュが無い: %s\n' "$path" >&2; ok=1; }
  done
  return "$ok"
}

pull_backups "$@"
